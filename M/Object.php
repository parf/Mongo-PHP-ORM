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
                                // hash("field" => 1) - some fields loaded
                                // true - all data loaded
    protected $D=[];      // read data cache

    static $debug = 0;

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
    function load($fields="") { #
        $fields = $this->MC->_fields($fields);

        if ($this->loaded === true)
            return;

        // load all - exclude already loaded fields
        if (! $fields && is_array($this->loaded)) { // already loaded fields
            $fields= [];
            foreach($this->loaded as $k => $v)
                if ($k!='_id')
                    $fields[$k] = false;
            $this->_load($fields);
            $this->loaded=true;
            return;
        }

        // do not load already loaded fields
        if ($fields && is_array($this->loaded) && isset($fields[0])) { // already loaded fields
            $ftl = []; // fields to load
            foreach($fields as $f)
                if (! isset($this->loaded[$f]))
                    $ftl[] = $f;
            if (! $ftl)
                return;
            $fields = $ftl;
            Profiler::info("M2:load_of/partial", ["".$this, $fields]);
        }

        $this->_load($fields);
    }

    // reload fields
    function reload() { # this
        $this->_load();
        return $this;
    }

    // low level - forced load/reload
    // avoid unless you want to re-query data from mongo
    // takes care of loaded fields
    function _load($fields="") { // {f:v} loaded fields
        if (! is_array($fields))
            $fields = $this->MC->_fields($fields);

        if ($fields) {
            Profiler::in_off("M:load/partial", ["".$this, $fields]);
            $D = $this->MC->findOne($this->id, $fields);
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
            Profiler::in_off("M:load", "".$this);
            $this->D = $D = $this->MC->findOne($this->id);
            $this->loaded = true;
        }
        Profiler::out();
        if (! isset($D["_id"])) {
            $this->loaded = false;
            throw new NotFoundException("".$this);
        }
        return $D;
    }

    // Load with respect to caclulated fields and relationships
    // use: "$this->_" to get loaded field list
    function get($fields="") { # {field:value}
        $fields = $this->MC->_fields($fields);
        $this->load($fields);
        if(!$fields && $this->loaded)
            return $this->D;

        $r = [];
        foreach($fields as $f)
            $r[$f] = isset($this->D[$f]) ? $this->D[$f] : $this->__get($f);
        return $r;
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
        if ($fa = $this->MC->C("field-alias"))
            $r[0] = $this->MC->_kv_aliases($fa, $r[0]);
        foreach($r[0] as $k => $v)
            $this->reset($k);
        $this->MC->update($this->id, [$op => $r[0]]);
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

    // SET is low level function
    // if you need calc fields, field-aliases - use save(array $kv)
    /* low-level */ function set() {  # this
        $a = func_get_args();
        if (count($a)==2)
            $a=[$a[0] => $a[1]];
        else
            $a=$a[0];
        $a = $this->MC->applyTypes($a);
        Profiler::in_off("M:set", ["".$this, $a]);
        $this->MC->MC()->update(["_id" => $this->id], ['$set' => $a]);
        Profiler::out();
        foreach($a as $k => $v) {
            if ($p=strpos($k, '.')) {
                $this->loaded = false;
                unset($this->D[ substr($k, 0, $p) ]);
            } else {
                $this->D[$k] = $v;
            }
        }
        return $this;
    }

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

    // set with respect to setters and field aliases
    // PRECEDENCE:
    //   METHOD > FIELD > MAGIC > ALIAS
    function save(array $set=[]) {
        if (! $set)
            return;
        $ts = [];
        foreach($set as $k => $v) {

            if ( method_exists($this, "set$k") ) {
                $v = $this->{"set$k"}($v);
                if ($v !== null)
                    $ts[$k] = $v;
                continue;
            }

            if (isset($this->D[$k]) && $this->D[$k]===$v) // skip useless writes
                continue;

            // MAGIC FIELDS
            if ( $k[0] == '_' ) {
                $k = substr($k, 1);
                if (!$k) {
                    trigger_error("can't assign to '_' field");
                    die;
                }
                if ( $fa = $this->MC->C("field-alias.$k") )
                    $k = $fa;

                $ts[$k] = $this->MC->setMagicField($k, $v);
                continue;
            }

            // Field Alias
            if ( $fa = $this->MC->C("field-alias.$k") ) {
                $key = $fa;
                if (isset($this->D[$k]) && $this->D[$k]===$v) // skip useless writes
                    continue;
                $ts[$k] = $v;
                continue;
            }

            $ts[$k] = $v;

            if ($this->MC->C("field.$k") == 'array' && ! is_array($v)) {
                trigger_error("can't assign scalar to array");
                die;
            }
        }

        if ($ts)
            $this->set($ts);
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
    // INTERNAL
    //


    // PRECEDENCE:
    //   FIELD > ALIAS > METHOD > MAGIC_FIELD > HAS-ONE > HAS-MANY
    //   Magic fields - fields starting with _
    function __get($key) {
        if ( isset($this->D[$key]) )
            return $this->D[$key];

        // FIELD ALIAS
        if ( $fa = $this->MC->C("field-alias.$key") )
            return $this->__get($fa);

        // non-existent loaded fields
        if (is_array($this->loaded) && isset($this->loaded[$key]))
            return null;

        // MAGIC FIELDS
        if ( $key[0] == '_' ) {
            $key = substr($key, 1);
            if ( $fa = $this->MC->C("field-alias.$key") )
                $key = $fa;
            if (! isset($D[$key]))
                $this->load();
            if (is_array($this->loaded) && isset($this->loaded[$key]))
                return null;
            if (! $key)
                return $this->D;
            if ($key == '_') // magic field representation (when possible)
                return $this->MC->allMagic($this->D); // typed collection expected
            if (! isset($this->D[$key])) {
                if ( $fa = $this->MC->C("field-alias.$key") )
                    return $this->__get("_".$fa);
                return $this->MC->formatMagicField($key, null);
            }
            return $this->MC->formatMagicField($key, $this->D[$key]);
        }

        //if (is_array($this->loaded))
        //Profiler::info("loading: ", [$key, $this->loaded]);

        // HAS-ONE
        if ($c=$this->MC->C("has-one.$key")) {  # [FK, db.collection]
            if (! isset($D[$c[0]]))
                $this->load($c[0]);
            if (! isset($this->D[$c[0]]))
                return; // null
            $fk=$this->D[$c[0]];
            return M($c[1], $fk);
        }

        // HAS-MANY
        if ($c=$this->MC->C("has-many.$key")) { // [FK, db.collection.KEY]
            if (! isset($D[$c[0]]))
                $this->load($c[0]);
            $fk=$this->D[$c[0]];
            if (! $fk)
                return null;
            list($db, $col, $key)=explode(".", $c[1], 3);
            return M($db.".".$col)->f( [$key => $fk] );
        }

        $this->load();
        if ( isset($this->D[$key]) )
            return $this->D[$key];

        if ( method_exists($this, "get$key") )
            return call_user_func( [$this, "get$key"] );

        // return array() for non existant array-type fields
        if ($this->MC->C("field.$key") == 'array')
            return [];

        return null;
    }

    // PRECEDENCE:
    //   METHOD > FIELD > MAGIC > ALIAS
    function __set($key, $value) {
        $this->save([$key => $value]);
    }

    function __unset($key) {
        unset($this->D[$key]);
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
    function offsetSet($offset, $value) {
        $this->set($offset, $value);
    }

    // unset( $m_object["node.field"] )
    function offsetUnset($offset) {
        $this->_unset($offset);
    }

    // isset( $m_object["node.field"] )
    function offsetExists($offset) { # id of found record
        return $this->MC->one($this->id, $offset);
    }

    // M::Alias($id)[$field]
    function offsetGet($offset) { # value
        if (! strpos($offset, "."))
            return $this->__get($offset);
        $magic = 0;
        if ($offset[0]=='_') {
            $magic = 1;
            $offset = substr($offset, 1);
        }
        $p=explode(".", $offset);
        if (! isset($this->D[$p[0]]))
            $this->load($p[0]);
        $r=& $this->D;
        $z = null;
        foreach($p as $k) {
            if (! isset($r[$k]))
                break;
            if (! is_array($r[$k])) {
                $z = $r[$k];
                break;
            }
            $r = & $r[$k];
            $z = $r;
        }
        if ($magic && $T=$this->MC->C("field.$offset")) {
            $z = M_Type::getMagic($z, $T, false);
        }
        return $z;
    }

}

/*

  extend this class of you want to get errors when you access unknown fields

  controls ORM style access and saves:
  * read only defined fields/aliases, calc fields, magic_fields of defined fields/aliases
  * write to defined fields/aliases, calc fields, magic_fields of defined fields/aliases

  throws DomainException when trying to read/write unknown fields

  TODO:
    -- does not support inserts
    -- does not support operations (see $op)

*/
class M_StrictField extends M_Object {

    function __get($field) {
        $MC = $this->MC;

        // check field, alias, magic, function
        if ( $fa = $MC->C("field-alias.$field") )
            $field = $fa;

        // do we know this field?
        if ( $MC->C("field.$field") )
            return parent::__get($field);

        // do we know this field? (as array)
        if ( $MC->C("field.$field.*") )
            return parent::__get($field);

        // calc field
        if ( method_exists($this, "get$field") )
            return call_user_func( [$this, "get$field"] );

        // magic field - requiring to have underlying field
        if ($field['0']=='_') {
            $f = substr($field, 1);
            if (! $f || $f=='_')
                return parent::__get($field);
            if (!$MC->C("field.$f")) {
                // alias of magic field
                if (! $MC->C("field-alias.$f") ) {
                    if (! $MC->C("field-alias.$f.*") )
                        throw new DomainException("Mongo_StrictField::get unknown magic field ".$this.".$field");
                }
            }
            return parent::__get($field);
        }

        if ($MC->C("has-one.$field") || $MC->C("has-many.$field"))
            return parent::__get($field);

        throw new DomainException("Mongo_StrictField::get unknown field ".$this.".$field");
    }

    /* low-level */ function set() {  // this
        $a = func_get_args();
        if (count($a)==2)
            $a=[$a[0] => $a[1]];
        else
            $a=$a[0];
        $rename = [];
        foreach($a as $field => $value) {
            if ( $fa = $this->MC->C("field-alias.$field") ) {
                $rename[$field] = $fa;
                $field = $fa;
            }
            if (!$this->MC->C("field.$field")) {
                if ($T = $this->MC->C("field.$field.*")) {
                    if (! is_array($value))
                        throw new DomainException("Mongo_StrictField::set array expected for ".$this.".$field");
                    continue;
                }
                if (strpos($field, ".")) {
                    $f = explode(".", $field);
                    if (count($f)==2 && is_numeric($f[sizeof($f)-1])) {  // field.42 == array access
                        if ($T = $this->MC->C("field.$f[0].*")) {
                            $a[$field] = M_Type::apply($value, $T);
                            continue;
                        }
                    }
                }
                throw new DomainException("Mongo_StrictField::set unknown field ".$this.".$field");
            }
        }
        if ($rename) {
            foreach($rename as $from => $to) {
                $a[$to]=$a[$from];
                unset($a[$from]);
            }
        }
        parent::set($a);
        return $this;
    }


    // supports op($op, [[$key:$value]]) and op($q, [$key, $value])
    protected function op($op, array $r) {
        if (isset($r[1]))
            $r=[[$r[0] => $r[1]]];
        $T=$this->MC->C("field");
        foreach($r[0] as $f => $v) {
            if ( $fa = $this->MC->C("field-alias.$f") )
                $f = $fa;
            if (! isset($T[$f]))
                throw new DomainException("unknown field $f");
        }
        return parent::op($op, $r);
    }


}

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

        // FIELD ALIAS
        if ( $fa = $this->_instance->MC()->C("field-alias.$key") )
            $key = $fa;

        // FIELD ALIAS
        if ( $this->_instance->MC()->C("field.$key") || $this->_instance->MC()->C("field.$key.*") )
            $key="_".$key;

        return $this->_instance->__get($key);
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
        if (! strpos($offset, "."))
            return $this->__get($offset);
        return $this->_instance->offsetGet('_'.$offset);
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
