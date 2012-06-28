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
    PUBLIC function reload() { # this
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
        if (! is_array($fields))
            $fields = explode(" ", $fields);

        if ($this->loaded === true)
            return;

        // load all - exclude already loaded fields
        if (! $fields && is_array($this->loaded)) { // already loaded fields
            $fields= [];
            foreach($this->loaded as $k => $v)
                if ($k!='_id')
                    $fields[$k] = false;
            Profiler::in_off("M2:load", ["".$this, "*all*"]);
            $this->_load($fields);
            Profiler::out();
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
        }

        $this->_load($fields);
    }


    // low level - forced load/reload
    // avoid unless you want to re-query data from mongo
    // takes care of loaded fields
    function _load($fields="") { // {f:v} loaded fields
        if (! is_array($fields))
            $fields = explode(" ", $fields);

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


    function set(array $kv) {  // this
        $this->MC->kv_aliases($kv); // take care of aliases
        
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



    // mongo::update build-in subfunction wrapper
    // supports op($op, [[$key:$value]]) and op($q, [$key, $value])
    protected function op($op, array $r) {
        // $r is [array $kv] or [$key, $value]
        if (! isset($r[0])) {
            trigger_error("not enough params");
            die;
        }
        if (! array_key_exists(1, $r)) {
            foreach($r[0] as $k => $v)
                $this->reset($k);
            return $this->MC->update($this->id, [$op => $r[0]]);
        }
        if (is_array($r[0])) {
            trigger_error("can't mix KV-Array and 'key, value' syntax");
            die;
        }
        $this->reset($r[0]);
        Profiler::in_off("M:$op", ["".$this, $r]);
        $this->MC->update($this->id, [$op => [$r[0] => $r[1]]]);
        Profiler::out();
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

    // set with respect to setters and field aliases
    // PRECEDENCE:
    //   METHOD > FIELD > MAGIC > ALIAS
    function save(array $set=[]) {
        if (! $set)
            return;
        $T = $this->MC->C("field.$field");
                
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


    // get does not change existing field types
    // T - [type, ... options]
    protected function _get($field, $T) {
        switch($T[0]) {
        case "array":  // empty array of whatever
            return [];
        case "method":
            return call_user_func( [$this, "get$field"] );
        case "has-one": // ["has-one", FK, db.collection]
            $f = $T[1];
            if (! isset($this->D[$fk]))
                $this->load($fk);
            if (! isset($D[$fk]))
                return null;
            return M($T[2], $D[$fk]);
        case "has-many": // ["has-many", FK, db.collection.KEY]
            $fk = $T[1];
            if (! isset($this->D[$fk]))
                $this->load($fk);
            if (! isset($D[$fk]))
                return [];
            list($db, $col, $key)=explode(".", $T[2], 3);
            return M($T[2])->f([$key => $fk]);
        }
        return null;
    }

    // field is a deep field (as in "node.node.field")
    protected function __get_deep($field) { // value
        $p=explode(".", $field);
        if (! isset($this->D[$p[0]]))
            $this->load($p[0]);
        $r=& $this->D;
        foreach($p as $k) {
            if (! isset($r[$k]))
                break;
            if (! is_array($r[$k])) {
                $r = $r[$k];
                break;
            }
            $r = & $r[$k];
        }
        return $r;
    }

    // PRECEDENCE:
    //   CHECK > ALIAS >  MAGIC > SPECIAL
    //   Magic fields - fields starting with _
    function __get($field) {
        $T = $this->MC->C("field.$field");

        // alias support
        if (is_array($T) && $T[0]=='alias')
            return $this->__get($T[1]);
        
        if (! $T && $this->MC->C("strict"))
            throw new DomainException("unknown field $field");
        
        if ( isset($this->D[$field]) )
            return $this->D[$field];

        // Magic Fields
        if ($field[0]=='_') {
            $field = substr($field, 1);
            if (! $field)  // ->_
                return $this->D;
            if ($field == '_') // magic field representation (when possible)
                return $this->MC->allMagic($this->D); // typed collection expected
            $T = $this->MC->C("field.$field");
            if (is_array($T) && $T[0]=='alias') {
                $field = $T[1];
                $T = $this->MC->C("field.$field");
            }
            if (! $T)
                throw new DomainException("type required for magic field $field");

            
            if (! isset($this->D[$field])) {
                if (strpos($field, "."))
                    $v = $this->__get_deep($field);
                else {
                    list($v)=$this->load($field);
                }
            }
            return M_Type::getMagic($v, $T);
        }

        if (! strpos($field, "."))
            return $this->__get_deep($field);

        if (is_array($T))  // relations, methods, ...
            return $this->_get($field, $T);  

        list($v) = $this->load($field);

        if (!$v && $T == 'array')
            return [];

        return $v;

    }

    // PRECEDENCE:
    //   METHOD > FIELD > MAGIC > ALIAS
    function __set($key, $value) {
        $this->save([$key => $value]);
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
        $this->save([$field => $value]);
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


class NotFoundException extends RuntimeException {}
