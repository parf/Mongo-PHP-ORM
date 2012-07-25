<?

/*

ORM for Mongo -

NEVER CALL THIS DIRECTLY

Call only via:
* M::Alias($id)
* M("db.col", $id)

Mapping of collection entry to Object.

M_Object provides:
* field aliases
* field type support
  both on get and set
* calculated fields (rvalue)
* pseudo fields (lvalue)
* has-one (one-to-one) relationship
* has-many (one-to-many) relationship
* handful of useful functions
  M::Alias(10)->inc(["counter" => -1])
  M::Alias($id)->inc("counter", -1)
  M::Alias($id)->inc("counter", -1)

M_Router (M_Object) provides:
* custom class instantiation based on 'class' field,
  M_Object if no class field

M_StrictRouter (M_Router) provides:
* custom class instantiation based on 'class' field
* 'class' fields required

Overload M_Object to get:
* calulated properties:
  * get$key()
  * set$key()

*/

class M_Object implements ArrayAccess {

    // Instance Variables
    public $id;  // current id == $D["_id"]

    protected $MC;  // M_Collection
    protected $loaded=false;    // false - DATA NOT LOADED
                                // hash("field" => 1) - specific fields loaded
                                // true - all data loaded
    protected $D=[];            // read data cache

    static $debug = 0;          // for dbg function only

    // instantiate object by id (primary key)
    static function i($MC, $id, $autoload=true) { # instance | NotFoundException
        if ($o=$MC->_getObject($id))
            return $o;
        $o=new static($MC, $id);
        if ($autoload)
            $o->autoload($autoload);
        return $MC->_setObject($id, $o);
    }

    // instantiate object from already loaded data
    // $D - data {field => value}
    // no exists checks performed
    // $loaded - {see $this->loaded}
    static final function i_d($MC, $D, $loaded=false) { # instance
        $id=$D["_id"];
        if (! $id) {
            trigger_error("_id field required");
            die;
        }
        $o = static::i($MC, $id, false);
        $o->_setD($D, $loaded);
        return $o;
    }

    // instantiate object from existing M_Object
    // no exists checks performed
    static final function i_o(M_Object $O) { # static (current class)
        $o  = new static($O->MC(), $O->id);
        $o->_setD($O->D());
        return $O->MC()->_setObject($O->id, $o);
    }

    // --------------------------------------------------------------------------------

    // PUBLIC HIGH LEVEL FUNCTIONS

    // Load with respect to caclulated fields, aliases, relationships ...
    // use: "$this->_" to get loaded field list
    PUBLIC function get($fields="") { # {field:value}
        $fields = $this->MC->_fields($fields);
        $this->load($fields);
        if(!$fields && $this->loaded)
            return $this->D;
        $r = [];
        foreach($fields as $f)
            $r[$f] = isset($this->D[$f]) ? $this->D[$f] : $this->__get($f);
        return $r;
    }

    // reload fields
    // use only when some process can concurently change data
    PUBLIC function reload() { // this
        $this->_load();
        return $this;
    }

    // --------------------------------------------------------------------------------

    // called from ::i to autoload record from db when autoload is not disabled !!
    // overload:
    //     to remove autoload
    //     want it to work differently
    // autoload = true - load all
    // autoload = "field list" - load specific fields
    function autoload($autoload) {
        if ($autoload === true) { // all
            $this->_load();       // $this->loaded = true
            return;
        }
        $this->_load($autoload);  // $this->loaded - hash of loaded fields
    }

    // load data - will load data only once
    // works with actual fields only
    function load($fields="") { // null
        if ($this->loaded === true)
            return;

        // LOAD ALL - exclude already loaded fields
        if (! $fields) {
            $fields= [];
            if (is_array($this->loaded)) { //
                foreach($this->loaded as $k => $v)
                    if ($k!='_id')
                        $fields[$k] = false;
            }
            Profiler::in_off("M2:load", ["".$this, "*all*"]);
            $this->_load($fields);
            Profiler::out();
            $this->loaded=true;
            return;
        }

        if (! is_array($fields))
            $fields = explode(" ", $fields);

        // LOAD SPECIFIC - do not load already loaded fields
        // only string($fields) and [$field, ... ] are supported
        if (is_array($this->loaded) && isset($fields[0])) { // already loaded fields
            $ftl = []; // fields to load
            foreach($fields as $f)
                if (! isset($this->loaded[$f]))
                    $ftl[] = $f;
            if (! $ftl)
                return;
            $fields = $ftl;
        }

        $this->_load($fields);
    }


    // --------------------------------------------------------------------------------

    // check that record with current id exists
    // you never need this (unless you did no-autoload && no-exist-check)
    // use: $this->_id instead
    // function exists() { # bool
    //     return $this->MC->one($this->id);
    // }

    // throw out loaded data, reset loaded flag
    // fields = space delimited field list
    function reset($fields=false) {
        $this->loaded=false;
        if ($fields) { // reset specific fields
            if (! is_array($fields))
                $fields=explode(" ", $fields);
            foreach($fields as $f) {
                if (strpos($f, "."))
                    list($f, $x) = explode(".", $f, 2);
                unset($this->D[$f]);
            }
            return;
        }
        $this->D = [];
    }

    // save data to DB
    // support types, aliases, magic fields, method-fields, deep fields, ...
    function set(array $kv) {  // this
        $kv = $this->MC->_kv($kv, $this, true);
        Profiler::in_off("M:set", ["".$this, $kv]);
        $this->MC->MC()->update(["_id" => $this->id], ['$set' => $kv]);
        Profiler::out();
        // take care of cached data
        foreach($kv as $k => $v) {
            if ($p=strpos($k, '.')) {
                $k0 = substr($k, 0, $p); // top key
                if (is_array($this->loaded))
                    unset($this->loaded[$k0]);
                else
                    $this->loaded = false;
                unset($this->D[$k0]);
            } else {
                $this->D[$k] = $v;
            }
        }
        return $this;
    }

    // mongo::update build-in subfunction wrapper
    // supports op($op, [[$key:$value]]) and op($q, [$key, $value])
    protected function op($op, array $r) {
        // $r is [array $kv] or [$key, $value]
        if (! isset($r[0])) {
            trigger_error("not enough params");
            die;
        }
        if (array_key_exists(1, $r))
            $r = [ [$r[0] => $r[1]] ];

        foreach($r[0] as $k => $v)
            $this->reset($k);
        
        // 2.1 way
        $kv = $this->MC->_kv($r[0]);
        $this->MC->MC()->update(['_id' => $this->id], [$op => $kv]);
        
        return $this;
    }

    // avoid this
    // - this is not a sql update - it is a sql replace
    function update(array $r) {
        Profiler::in_off("M:UPDATE", ["".$this, $r]);
        $this->reset();
        $this->MC->update($this->id, $r);
        Profiler::out();
    }

    // unset - "field field", ["field", "field"], ["field" => x, "field" => x"]
    function _unset($unset="") { # this
        if (is_array($unset) && $unset && ! isset($unset[0]))
            $this->reset( array_keys($unset) );
        else
            $this->reset($unset);
        $this->MC->_unset($this->id, $unset);
        return $this;
    }

    // UPDATE build-in function wrappers
    //     M::Alias($id)->$op($key, $value);
    //     M::Alias($id)->$op([$key => $value, $key2 => $value2])
    // Ex:
    //     M::Alias(2)->inc("counter", 1);
    //     M::Alias(2)->inc(["counter" => 1]);
    //     M::Alias(2)->inc("counter");  << special case for inc, default is 1


    // smart addToSet
    // add("key", v1, v2, v3, ...)
    // add one or more values to set
    function add(/* field, value, value, value */) {
        $a=func_get_args();
        $this->reset($a[0]);
        array_unshift($a, $this->id);
        call_user_func_array([$this->MC, "add"], $a);
        return $this;
    }

    function addToSet() { return $this->op('$addToSet', func_get_args());   }

    // default - inc field by one
    function inc()       {
        $a = func_get_args();
        if (! is_array($a[0]) && ! isset($a[1]))
            $a[1]=1;
        return $this->op('$inc', $a);
    }

    // default - dec field by one
    function dec($field, $by=1)       {
        return $this->inc($field, -$by);
    }

    // add element to list
    function push()      { return $this->op('$push', func_get_args());   }

    // add list of elements to list
    // $id, $key, array $values only!
    function pushAll()  { return $this->op('$pushAll', func_get_args());   }

    // pop first of last list element
    // $id, $key, $how (1:last, -1: first)
    // default is last
    function pop()       {
        $a = func_get_args();
        if (! is_array($a[0]) && ! isset($a[1]))
            $a[1]=1;
        return $this->op('$pop', $a);
    }

    // remove value from set
    function pull()      { return $this->op('$pull', func_get_args());   }

    // remove list of values from set
    // $key, array $values only!
    function pullAll()  { return $this->op('$pullAll', func_get_args());   }

    // ["and" => $b, "or" => $b]
    function bit()       { return $this->op('$bit', func_get_args());   }

    // field rename
    // [$old_field => $new_field]
    function rename()   {
        $a = func_get_args();
        if (is_array($a[0]))
            $this->reset( reset($a[0]) );
        else
            $this->reset( reset($a[1]) );
        return $this->op('$rename', $a);
    }

    // Legacy
    function save(array $set) {
        if (! $set)
            return;
        $this->set($set);
    }

    // json dump
    function json() { # json
        $this->load();
        return json_encode($this->D);
    }

    final function MC() { # MongoCollection
        return $this->MC;
    }

    // MAGIC representation of M_Object - all normal get accesses are magic, all magic are normal
    final function M() { // M_Object_Magic
        return new M_Object_Magic($this);
    }

    // access to loaded data
    // use in getters to avoid recursion, use when sorting
    final function D($field=false) { // loaded field value
        if ($field === false)
            return $this->D;
        return @$this->D[$field];
    }

    /* debug */ function v() { # Debug function
        return ["id" => $this->id, "D" => $this->D, "loaded" => $this->loaded];
    }

    // --------------------------------------------------------------------------------
    // ADVANCED USERS ONLY - SEMI INTERNAL
    //

    // low level - forced load/reload
    // avoid unless you want to re-query data from mongo
    // takes care of loaded fields
    PUBLIC function _load($fields="") { // {f:v} loaded fields
        if ($fields && ! is_array($fields))
            $fields = explode(" ", $fields);

        if ($fields) {
            Profiler::in_off("M:load/partial", ["".$this, $fields]);
            $D = $this->MC->findOne($this->id, $fields);
            if (! $D) {
                $this->loaded = false;
                throw new NotFoundException("".$this);
            }
            $this->D = $D + $this->D;
            $lf = []; // loaded fields
            if (isset($fields[0])) { // list of fields
                foreach($fields as $f)
                    $lf[$f] = 1;
            } else { // fields => false/true
                foreach($fields as $f => $v)
                    $lf[$f] = 1;
            }
            // unset($lf["_id"]);
            $lf["_id"]=1;
            if (is_array($this->loaded))
                $this->loaded = $this->loaded + $lf;
            else
                $this->loaded = $lf;
        } else {
            Profiler::in_off("M:load/all", "".$this);
            $this->D = $D = $this->MC->findOne($this->id);
            if (! $this->D) {
                $this->loaded = false;
                $this->D = [];
                throw new NotFoundException("".$this);
            }
            $this->loaded = true;
        }
        Profiler::out();
        return $D;
    } // _load

    // --------------------------------------------------------------------------------
    // INTERNAL
    //

    // check if data already loaded, load it if needed
    private function _g($field, $default=null) { // value
        if (array_key_exists($field, $this->D))
            return $this->D[$field];
        if ($this->loaded === true || (is_array($this->loaded) && isset($this->loaded[$field])))
            return $default;
        $loaded = $this->_load($field);
        if (array_key_exists($field, $loaded))
            return $loaded[$field];
        return $default;
    }

    // get does not change existing field types
    // T - [type, ... options]
    protected function _get($field, $T) {
        switch($T[0]) {
        case "array":  // empty array of whatever
            return $this->_g($field, []);
        case "method":
            $m = [$this, "get$field"];
            if (is_callable($m))
                return $m();
            return $this->_g($field);
        case "has-one": // ["has-one", FK, db.collection]
            $fk = $this->_g($T[1]);
            if (! $fk)
                return null;
            return M($T[2], $fk);
        case "has-many": // ["has-many", FK, db.collection.KEY]
            $fk = $this->_g($T[1]);
            if (! $fk)
                return [];
            list($db, $col, $key)=explode(".", $T[2], 3);
            return M($db.".".$col)->f([$key => $fk]);
        case "enum":
            if (! isset($this->D[$field]))
                $this->load($field);
            return @$this->D[$field];
        }
        return null;
    }

    // field is a deep field (as in "node.node.field")
    protected function __get_deep($field) { // value
        $p = explode(".", $field);
        if (! isset($this->D[$p[0]]))
            $this->load($p[0]);
        $r = & $this->D;

        if ($t = @$this->MC->type[$p[0]])
            if (is_array($t) && $t[0]=='alias')
                $p[0]=$t[1];

        foreach($p as $k) {
            if (! isset($r[$k]))
                return null;
            if (! is_array($r[$k]))
                return $r[$k];
            $r = & $r[$k];
        }
        return $r;
    }

    // magic representation of a field
    /* protected */ function __get_magic($field, $exception=true) {
        if (! $field)  { // ->_
            $this->load();
            return $this->D;
        }
        // ->__ allMagic
        if ($field == '_') // magic field representation (when possible)
            return $this->MC->allMagic($this->D); // typed collection expected

        $t = @$this->MC->type[$field];
        if ($t && is_array($t) && $t[0]=='alias') {
            $field = $t[1];
            $t = @$this->MC->type[$field];
        }
        if (! $t) {
            $dot = strrpos($field, ".");

            // node.INDEX (node.123) support
            if ($dot && is_numeric(substr($field, $dot+1))) {
                $t=@$this->MC->type[ substr($field, 0, $dot) ];
                // get type from ["array", $type]
                if ($t && is_array($t) && $t[0]=='array')
                    return M_Type::getMagic($this->__get_deep($field), $t[1]);
            }
            
            if ($exception)
                throw new DomainException("type required for magic field $this.$field");

            if ($dot)
                return $this->__get_deep($field);
            return $this->_g($field);
        }

        if (! isset($this->D[$field])) {
            if ($p=strpos($field, ".")) {
                $v = $this->__get_deep($field);
            } else {
                $this->load($field);
                $v = @$this->D[$field];
            }
        } else {
            $v = $this->D[$field];
        }
        return M_Type::getMagic($v, $t);
    }

    // PRECEDENCE:
    //   ALIAS/SPECIAL > FIELD > MAGIC > DEEP
    //   Magic fields - fields starting with _
    function __get($field) {
        $T = $this->MC->C("field.$field");

        // complex type: alias, relation, method, ...
        if (is_array($T)) {
            if ($T[0]=='alias')
                return $this->__get($T[1]);
            return $this->_get($field, $T);
        }

        // strict collection, known field check
        if (! $T && $this->MC->C("strict") && $field[0]!='_')
            throw new DomainException("unknown field $this.$field");

        if (isset($this->D[$field]))
            return $this->D[$field];
        
        // Magic Field
        if ($field[0]=='_')
            return $this->__get_magic(substr($field, 1));

        // Deep Field
        if (strpos($field, "."))
            return $this->__get_deep($field);

        $this->load($field);
        $v = @$this->D[$field];

        if (!$v && $T == 'array')
            return [];

        return $v;

    }

    // PRECEDENCE:
    //   METHOD > FIELD > MAGIC > ALIAS
    function __set($key, $value) {
        $this->set([$key => $value]);
    }

    function __unset($key) {
        $this->_unset($key);
    }

    // M_Collection
    // see ::i
    /* protected */ function __construct($MC, $id, array $D=[]) {
        $this->MC=$MC;
        $this->id=$id;
        $this->D=$D;
    }

    function __toString() {
        return $this->MC->sdc."[".$this->id."]";
    }


    // INTERNAL: calling this will void your warranty!!
    // replace cached data
    /* internal */ final function _setD(array $D, $loaded=false) {
        $this->D = $D;
        $this->loaded = $loaded;
        if (is_array($loaded))
            $this->loaded["_id"]=1;
    }

    // used for class debug
    /* internal */ function dbg($msg) {
        if (self::$debug)
            echo 'D:'.$this." ".json_encode($msg)."\n";
    }

    // --------------------------------------------------------------------------------
    // Array Access

    // getting / setting deep nested items

    // M::Col($id)["node.node.field"] = "value";
    function offsetSet($field, $value) {
        $this->set([$field => $value]);
    }

    // unset( $m_object["node.field"] )
    function offsetUnset($fied) {
        $this->_unset($field);
    }

    // isset( $m_object["node.field"] )
    function offsetExists($field) { // bool
        return (bool)$this->__get($field);
    }

    // M::Alias($id)[$field]
    function offsetGet($field) { // value
        return $this->__get($field);
    }

}

// M_StrictField is deprecated - use "strict: 1" config option

/*
  support for "class" field
  instantiate "M_$class" class based on data field "class"
  fallback to current class otherwise
*/
class M_Router extends M_Object {

    protected static function _class($class) { # class to instantiate
        if (! $class)
            return null;
        return "M_".$class;
    }

    // Router
    // Instantiate class based on 'class' field
    static function i($MC, $id, $autoload=true) { # instance
        if ($o=$MC->_getObject($id))
            return $o;
        if ($autoload)
            $D = $MC[$id]; // load autoload fields
        else
            $D = $MC->findOne($id, "class");  // _id & class
        $class = static::_class(@$D["class"]);
        $o = $class ? new $class($MC, $id) : new static($MC, $id);
        $o->_setD($D);
        if ($autoload)
            $o->loaded = "a";
        return $MC->_setObject($id, $o);
    }

}

/*
  Strict  Router:
      'class' field is required - DomainException if no field
*/
class M_StrictRouter extends M_Router {

    protected static function _class($class) { # "class" | DomainException
        if (! $class)
            throw new DomainException("class field required");
        return "M_".$class;
    }

}

/*
  M_Object->M()
  wrapper class, wraps/proxy M_Object

  inverts magic flavor of function __get() only !!

  all get requests are treated as magic requests when possible
  all (magic)"_field" get requests are treated as NON-magic

*/
/* internal */ class M_Object_Magic extends M__Proxy implements ArrayAccess {

    public $id;

    function __construct($instance) {
        $this->_instance=$instance;
        $this->id=$instance->id;
    }

    public function M() {  // get original object back
        return $this->_instance;
    }

    public function __get($key) {
        if ($key=='_id')
            return $this->_instance->_id;

        if ($key[0]=='_')
            return $this->_instance->__get(substr($key,1));

        return $this->_instance->__get_magic($key, false);
    }

    // --------------------------------------------------------------------------------
    // Array Access

    function offsetSet($offset, $value) {
        $this->_instance->offsetSet($offset, $value);
    }

    function offsetUnset($offset) {
        $this->_instance->offsetUnset($offset);
    }

    function offsetExists($offset) {
        return $this->_instance->offsetExists($offset);
    }

    function offsetGet($offset) { // value
        return $this->__get($offset);
    }

}

// using fancy name to avoid name collisions
abstract class M__Proxy {

    protected $_instance;

    function __construct($instance) {
        $this->_instance=$instance;
    }

    public function __call($meth, $args) {
        return call_user_func_array( array($this->_instance, $meth), $args);
    }

    public static function __callstatic($meth, $args) {
        return forward_static_call_array(array(get_class($this->_instance), $meth), $args);
    }

    public function __get($name) {
        return $this->_instance->$name;
    }

    public function __set($name, $value) {
        return $this->_instance->$name=$value;
    }

    public function __unset($name) {
        unset($this->_instance->$name);
    }

    public function __isset($name) {
        return isset($this->_instance->$name);
    }

}

class NotFoundException extends RuntimeException {}

# legacy
class M_StrictField extends M_Object {}
