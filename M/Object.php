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
* callbacks
  * after_load

*/

class M_Object implements ArrayAccess {

    // Instance Variables
    public $id;  // current id == $D["_id"]

    protected $MC;  // M_Collection
    protected $loaded=false;   // false - DATA NOT LOADED
    protected $D=[];      // read data cache

    // instantiate object by id (primary key)
    // do not overload - overload _i() instead
    static function i($MC, $id, $autoload=true) { # instance | NotFoundException
        if ($o=$MC->_getObject($id))
            return $o;
        $o=new static($MC, $id);
        if ($autoload)
            $o->autoload($autoload);
        return $MC->_setObject($id, $o);
    }

    // instantiate object from already loaded data
    // D - loaded data hash
    // no exists checks performed
    static final function i_d($MC, $D) { # instance
        $id=$D["_id"];
        if (! $id) {
            trigger_error("_id field required");
            die;
        }
        $o = static::i($MC, $id, false);
        $o->_setD($D);
        return $o;
    }

    // instantiate object from existing M_Object
    // no exists checks performed
    static final function i_o(M_Object $O) { # static (current class)
        $o  = new static($O->C(), $O->id);
        $o->_setD($O->_getD());
        return $O->C()->_setObject($O->id, $o);
    }

    // --------------------------------------------------------------------------------

    // for overload
    // called after data load
    function afterLoad() {
        // overload me
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
            $this->_load();
            $this->loaded=true;
        } else { // load specific fields
            $this->_load($autoload);
            $this->loaded = "a"; // autoload fields only (partial)
        }
        $this->afterLoad();
    }

    // load data - will load data only once
    function load($fields="") { #
        if ($this->loaded === true)
            return;

        if (! $fields && $this->loaded=='a') {
            $fields = $this->__getAlMap();
            $this->loaded=true;
        }
        // do not load already loaded fields
        if (! $fields && $this->D) { // loaded = partial
            $this->loaded=true;
            $fields= [];
            foreach($this->D as $k => $v)
                $fields[$k] = false;
            unset($fields["_id"]);
        }

        $this->_load($fields);
        if (! $fields) {
            $this->loaded=true;
            $this->afterLoad();
        }
    }

    // reload fields
    function reload() { # this
        $this->_load();
        return $this;
    }

    // low level
    // forced load/reload
    protected function _load($fields="") {
        Profiler::in("M_Object:load", [$this->id, $fields]);
        if ($fields)
            $this->D = $this->MC->findOne($this->id, $fields) + $this->D;
        else
            $this->D = $this->MC->findOne($this->id);
        Profiler::out();
        if (! $this->D["_id"])
            throw new NotFoundException("".$this);
    }

    // forced field get
    // works with actual fields ONLY !!
    // avoid using use get instead
    /* low-level */ function _get($fields="") {
        Profiler::in("M_Object:_get", [$this->id, $fields]);
        $D = $this->MC->findOne($this->id, $fields);
        if (! $D["_id"])
            throw new NotFoundException("".$this);
        $this->D = $D + $this->D;
        Profiler::out();
        return $D;
    }

    // Load with respect to caclulated fields and relationships
    // use: "$this->_" to get loaded field list
    function get($fields="") { # {field:value}
        $fields = $this->MC->_fields($fields);
        $this->load($fields);
        $r = [];
        foreach($fields as $f)
            $r[$f] = isset($this->D[$f]) ? $this->D[$f] : $this->__get($f);
        return $r;
    }

    // --------------------------------------------------------------------------------

    // check that record with current id exists
    // you never need this (unless you did no-autoload && no-exist-check)
    function exists() { # bool
        return $this->MC->one($this->id);
    }

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
        $this->MC->update($this->id, [$op => [$r[0] => $r[1]]]);
        return $this;
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
        $this->MC->MC()->update(["_id" => $this->id], ['$set' => $a]);
        foreach($a as $k => $v)
            $this->D[$k] = $v;
        return $this;
    }

    // smart addToSet
    // add("key", v1, v2, v3, ...)
    // add one or more values to set
    function add(/* field, value, value, value */) {
        $a=func_get_args();
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
    function save(array $set) {
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
                if ($k == '_') {
                    trigger_error("can't assign to $k field");
                    die;
                }
                $k = substr($k, 1);
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

            if ($this->MC->C("field.$k") == 'array' && ! is_array($value)) {
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

    /* debug */ function v() { # Debug function
        return ["id" => $this->id, "D" => $this->D, "loaded" => $this->loaded];
    }

    // --------------------------------------------------------------------------------
    // INTERNAL
    //


    // PRECEDENCE:
    //   FIELD > METHOD > MAGIC_FIELD > ALIAS > HAS-ONE > HAS-MANY
    //   Magic fields - fields starting with _
    function __get($key) {
        if ( isset($this->D[$key]) )
            return $this->D[$key];

        // avoid additional queries for non-exitent autoload fields
        if ($this->loaded=='a') {
            $af=$this->__getAlMap();
            if (isset($af[$key]))
                return null;
        }

        $this->load();

        if ( isset($this->D[$key]) )
            return $this->D[$key];

        if ( method_exists($this, "get$key") )
            return call_user_func( [$this, "get$key"] );

        // MAGIC FIELDS
        if ( $key[0] == '_' ) {
            if ($key == '_')
                return $this->D;
            $key = substr($key, 1);
            return $this->MC->formatMagicField($key, @$this->D[$key]);
        }

        // FIELD ALIAS
        if ( $fa = $this->MC->C("field-alias.$key") )
            return $this->__get($fa);


        // HAS-ONE
        if ($c=$this->MC->C("has-one.$key")) {  # [FK, db.collection]
            if (! isset($this->D[$c[0]]))
                return; // null
            $fk=$this->D[$c[0]];
            return M($c[1], $fk);
        }

        // HAS-MANY
        if ($c=$this->MC->C("has-many.$key")) { # [FK, db.collection.KEY]
            $fk=$this->D[$c[0]];
            if (! $fk)
                return null;
            list($db, $col, $key)=explode(".", $c[1], 3);
            return M($db.".".$col)->f( [$key => $fk] );
        }

        // return array() for non existant array-type fields
        if ($this->MC->C("field.$key") == 'array')
            return [];

        return null;
    }

    private function __getAlMap() { # al_field => -1
        $af=$this->MC->C("autoload-f");
        if (is_array($af))
            return $af;
        $af = [];
        foreach(explode(" ", $this->MC->C("autoload")) as $f)
            $af[$f]=false;
        unset($af["_id"]);
        $this->MC->C_set("autoload-f", $af);
        return $af;
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
        return "".$this->MC->sdc."[".$this->id."]";
    }


    // internal: calling this will void your warranty
    // replace cached object data
    /* internal */ final function _setD(array $D) {
        $this->D=$D;
    }

    // loaded data
    final function _getD() {
        return $this->D;
    }

    // --------------------------------------------------------------------------------
    // Array Access

    // getting / setting deep nested items

    // M::Col($id)["node.node.field"] = "value";

    function offsetSet($offset, $value) {
        $this->set($offset, $value);
        if (strpos($offset, ".")) {
            // TODO: update right way
            $p=explode(".", $offset);
            $this->reset($p[0]);
        }
    }

    function offsetUnset($offset) {
        $this->_unset($offset);
    }

    function offsetExists($offset) { # id of found record
        return $this->MC->one($this->id, $offset);
    }

    // M::Alias($id)[$field]
    function offsetGet($offset) { # value
        if (! strpos($offset, "."))
            return $this->__get($offset);
        $this->_load();
        $r=$this->D;
        $p=explode(".", $offset);
        foreach($p as $k) {
            if (! is_array($r[$k]))
                return $r[$k];
            $r= & $r[$k];
        }
        return $r;
    }

}

// extend this class of you want to get errors when you access unknown fields
//
// controls ORM style access and saves
// you can read any existing fields, field aliases, calc fields
// you can write to field aliases, calc fields and only defined fields
class M_StrictField extends M_Object {
    
    function __get($field) {
        $v = parent::__get($field);
        if ($v === null && $this->MC->C("field.$key"))
            throw new DomainException("unknown field $field");
        return $v;
    }

    /* low-level */ function set() {  // this
        $a = func_get_args();
        if (count($a)==2)
            $a=[$a[0] => $a[1]];
        else
            $a=$a[0];
        foreach($a as $field => $value) {
            if ($this->MC->C("field.$key"))
                throw new DomainException("unknown field $field");
        }
        return $this;
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
  'class' field is required
  DomainException if no field
*/
class M_StrictRouter extends M_Router {

    protected static function _class($class) { # "class" | DomainException
        if (! $class)
            throw new DomainException("class field required");
        return "M_".$class;
    }

}


class NotFoundException extends RuntimeException {}
