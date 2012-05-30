<?

/*

M2 !!

MongoCollection based class. Never call directly! Do not overload

use M("db.col") or M("Alias") or M::Alias to instantiate

  Drop in replacement/improvement for original MongoCollection

  All original mongo calls works exactly the same as before.

  Adds new options to existing function, allowing you to write less code
  Adds new functions (expose mongo builtin functions on the top level
  Adds support for TYPIZATION via M_TypedCollection class
  Provides ORM via M_Object class


  Better way to call exising functions:

  * pass id as scalar instead of array in all queries
    M::Alias()->findOne($id) instead of M::Alias()->findOne( ["_id" => (int) $id] )
    note: scalar ids will be converted to int.

  * Above example can be simplified even more as:
    M::Alias()[$id]

  * pass field lists as space delimited strings
    M::Alias()->findOne(10, "age sex martial_status")

  * exposes mongo internal functions as top level functions
    M::Alias()->inc($id, "field", value)
    or
    M::Alias()->inc($id, ["field":value, "field2":value])

  * new convinient functions
    * one - get value of specific field
      $t=M::Alias()->one($id, "field.x.t")
    * hash($query, $fields) -
          key => value or key => [values]
    * insert/create -
      use build-in sequence generator when no "_id" field present
    * group_by -
      group by impementation with min, max, sum, count


Use:
  M::Alias()->method(..)               << recommended
  M("account.account")->method(..)

  M::Alias()[$id] << PK lookup - get all fields
  M::Alias()->_() << get original MongoCollection

  * Instead of ["_id" => $id] you can just pass $id,


See also:
  M_TypedCollection

*/

class M_Collection implements ArrayAccess {

    const VERSION=2.0;

    public $name;       // db.col
    public $sdc;        // server:db.col | db.col
    public $server;     // as-is

    protected $MC;         // MongoCollection
    private $O_CACHE;    // ID => M_Object

    // NEVER CALL DIRECTLY
    //  - use M("server:db.col") | M("db.col") | M("Alias") | M::Alias()
    //
    // sdc = server:db.col | db.col
    static function i($sdc) {
        if (strpos($sdc, ":"))
            list($server, $name) = explode(":", $sdc, 2);
        else
            list($server, $name) = ["", $sdc];
        
        if ($f = M::_config($server, $name.".field"))
            return  new M_TypedCollection($server, $name, $f);

        return new M_Collection($server, $name);
    }

    // --------------------------------------------------------------------------------
    // NEW FUNCTIONS

    // Query(select) one field
    // id - int (auto casted) or query hash
    // ex: M::Account()->one(1006, "usd.balance")
    function one($id, $field='_id') { # null | value
        $q = $this->_query($id);
        $r = $this->MC->findOne($q, [$field]);
        if (! $r)
            return null;
        if (strpos($field,".")) {
            $p=explode(".", $field);
            foreach($p as $k) {
                if(isset($r[$k]))
                    $r=$r[$k];
                else
                    $r=null;
            }
            return $r;
        }
        return $r[$field];
    }

    // find wrapper - returns array instead of MongoCollection
    function f($query, $fields="") { # Array
        return iterator_to_array( $this->find($query, $fields) );
    }

    // alias of f
    function find_a($query, $fields="") { # Array
        return iterator_to_array( $this->find($query, $fields) );
    }

    // find wrapper - return array of M_Object
    function find_o($query) { # [M_Object, ...]
        $r = [];
        foreach($this->find($query) as $e)
            $r[$e["_id"]]=$this->go_d($e);
        return $r;
    }

    // find records with specified ids
    // _id: { $in:[$ids] }
    function find_in(array $ids, $fields="") { # array
        return $this->f(["_id" => ['$in' => $ids]], $fields);
    }

    // 1  field  - field => {fields}
    // 2  fields - hash f1  => f2
    // 3+ fields - hash f1  => {f1, f2:, ....}
    // Ex: M("user.user")->hash(0, "_id name")   => hash {_id => name}
    //     M("user.user")->hash(0, "email name") => hash {_id => name}
    //     M("user.user")->hash(0, "email")      => hash {email => {....}}
    function hash($query, $fields) { # hash K=>V | K => [V,V,..]
        if(! $query) $query=array();
        $query=$this->_query($query);
        $f=explode(" ", $fields);
        $c=count($f);
        $r=array();
        $k=$f[0];
        if($c==1)
           $f=array();
        if (! isset($query[$k]))
            $query[$k]=array('$exists' => true);
        if($c==2) {
            $vf=$f[1];
            if (! isset($query[$vf]))
                $query[$vf]=array('$exists' => true);
            foreach($this->MC->find($query, $f) as $e) {
                $r[(int)$e[$k]]=$e[$vf];
            }
            return $r;
        }
        foreach($this->MC->find($query, $f) as $e)
            $r[$e[$k]]=$e;
        return $r;
    }


    // Update or Insert
    function upsert($query, array $newobj, array $options = array() ) {
        $options["upsert"]=true;
        return $this->update($query, $newobj, $options);
    }

    function update_multiple($q, $newobj, array $options = array() ) {
        return $this->update($q, $newobj, array("multiple" => true) + $options);
    }

    // update + set/unset | insert
    function upsert_set($id, array $set, $unset="") {
        $wh=array("_id" => (int)$id);
        $ts=($set ? array('$set' => $set) : array());
        if ($unset)
            $ts['$unset']= is_array($unset) ? $unset : qk($unset);
        if ($this->one($id))
            return $this->MC->update($wh, $ts);
        return $this->insert( $wh + $set );
    }


    // unset
    // Ex:
    //   M::Alias()->_unset(1, "f1 f2");
    //   M::Alias()->_unset(1, ["f1"=>1, "f2"=>1]);
    //   M::Alias()->_unset(1, ["f1", "f2"]);
    // unset is reserved name - can't name function this way :(
    function _unset($q, $unset="") {
        $ts = 0;
        if (is_array($unset) && isset($unset[0])) { // array of fields
            $ts=[];
            foreach($unset as $f)
                $ts[$f]=1;
        }
        if ( ! is_array($unset)) {
            $ts = [];
            foreach(explode(" ", $unset) as $f)
                $ts[$f]=1;
        }
        if ($ts === 0)
            $ts = $unset;
        $this->op('$unset', [$q, $ts]);
    }

    // mongo::update build-in subfunction wrapper
    // op - operation
    // q  - query
    // supports op($op, [$q, [$key:$value, ...]]) and op($op, $q, $key, $value)
    function op($op, array $r) {
        // $r is [$q, array $kv] or [$q, scalar $key, $value]
        if ( ! isset($r[1]))
            trigger_error("not enough params");
        if ( array_key_exists(2, $r) ) {
            if ( is_array($r[2]) )
                trigger_error("can't mix KV-Array and 'key, value' syntax");
            $r[1]=[$r[1] => $r[2]];
        }
        Profiler::in_off("Mongo::$op", $r[1]);
        $this->update($r[0], [$op => $r[1]]);
        Profiler::out();
        return $this;
    }


    // UPDATE build-in function wrappers
    //    M::Alias()->$op($q, $key, $value);
    //    M::Alias()->$op($q, [$key => $value, $key2, $value2])
    // Ex:
    //    M::Alias()->inc(1, "counter", 1);
    //    M::Alias()->inc(1, ["counter" => 1]);
    function set()      { return $this->op('$set', func_get_args());   }

    // smart addToSet
    // add($q, "key", v1, v2, v3, ...)
    // add one or more values to set
    function add($q, $field /* value, value, value */) {
        $q = $this->_query($q);
        $a=func_get_args();
        array_shift($a);
        array_shift($a);
        if ( count($a) == 1)
            return $this->MC->update($q, ['$addToSet' => [$field => $a[0]]]);
        $this->MC->update( $q,
                           ['$addToSet' => [$field => ['$each' => $a]]]
                           );
    }

    function addToSet() { return $this->op('$addToSet', func_get_args());   }

    // default - inc field by one
    function inc() {
        $a = func_get_args();
        if (! is_array($a[1]) && ! isset($a[2]))
            $a[2]=1;
        return $this->op('$inc', $a);
    }

    // default - dec field by one
    function dec($q, $field, $by=1) {
        return $this->op('$inc', [$q, [$field => -$by]]);
    }

    // add element to list
    function push()     {
        return $this->op('$push', func_get_args());
    }

    // add list of elements to list
    // $id, $key, array $values only!
    function pushAll()  { return $this->op('$pushAll', func_get_args());   }

    // pop first of last list element
    // $id, $key, $how (1:last, -1: first)
    function pop()      {
       $a = func_get_args();
        if (! is_array($a[1]) && ! isset($a[2]))
            $a[2]=1;
        return $this->op('$pop', $a);
    }

    // remove value from set
    function pull()     { return $this->op('$pull', func_get_args());   }

    // remove list of values from set
    // $key, array $values only!
    function pullAll()  { return $this->op('$pullAll', func_get_args());   }

    // $key, ["and" => $b, "or" => $b]
    function bit()       { return $this->op('$bit', func_get_args());   }

    // field rename
    // [$old_field => $new_field]
    function rename()   { return $this->op('$rename', func_get_args());   }

    // auto-generate (Sequence) IDs if no _id present
    function create(array $data) { # M_Object
        $id=$this->insert($data);
        $data["_id"]=$id;
        return $this->go_d($data);
    }

    // Sequences
    // see Sequence/Mongo.inc for details
    // * Does not support databases
    // * tracking collection "sequence" located in the same db as collection
    function next($inc=1) { #
        return M_Sequence::next($this->name, $inc, true); // autocreate
    }

    function last_id() { # get last id from collection
        $r=$this->f([":sort" => "-_id", ":limit" => 1], "_id");
        if (! $r)
            return 0;
        return key($r);
    }

    // Missing GROUPBY for Mongo
    //
    // dbc - see db_collection
    // group_by  - space delimited string
    // field_op  - space delimited string
    //   $field:$operation
    // result field name: $field_$operation
    //
    // Supported operations:
    //  sum,count,min,max
    //
    // Examples:
    //  M('merchant.sale')->group_by("sale:sum sale:max sale:count", "merchant_id", array("year" => 2011) )
    //   ==  select merchant_id, sum(sale) from merchant.sale  where year=2011 group by merchant_id
    function group_by($field_op, $group_by="", array $where=[]) { # { $group_fields, $sum_fields }
        return M_Helper::group_by($this->MC, $field_op, $group_by, $where);
    }

    function distinct($query=array(), $key, $raw=false) {
        $_=["distinct" => $this->getName(), "key" => $key];
        if ($query)
            $_["query"]=$query;
        $r=$this->db->command($_);
        if ($raw)
            return $r;
        return $r["values"];
    }

    // --------------------------------------------------------------------------------
    // Object as Array Functions

    // M::Alias()[$id]=$kv; == M::Alias()->set($id, $kv)
    function offsetSet($offset, $kv) {
        $this->set($offset, $kv);
    }

    // unset( M::Alias()[$id] )
    function offsetUnset($offset) {
        $this->remove($offset);
    }

    // isset( M::Alias()[$id] )
    function offsetExists($offset) { # id of found record
        return $this->one($offset);
    }

    // M::Alias()[$id]
    function offsetGet($offset) { # findOne
        return $this->findOne($offset);
    }

    // --------------------------------------------------------------------------------
    // EXISTING MONGO FUNCTIONS

    // see also: one, offsetGet (M::Alias()[$where])
    // Ex: M("account.account")->findOne(1006)
    // Ex: M("account.account")->findOne(1006, "balance date_created")
    function findOne($query, $fields="") { # hash | M_Object
        $query=$this->_query($query);
        $fields=$this->_fields($fields);
        return $this->MC->findOne($query, $fields);
    }

    // SPECIAL query fields: ":sort", ":skip", ":limit"
    //    :sort - hash or "space delimited field list" (if field starts with "-" - sort descending)
    // SPECIAL query field: ":pager" - pointer to Pager class instance.
    // [-!!! IMPORTANT. $pager->page_size has priority over :limit as well as $pager->start has priority over :skip-
    // returns array

    function find($query, $fields="") { # MongoCursor
        $query=$this->_query($query);
        $fields=$this->_fields($fields);

        $sort=hash_unset($query, ":sort");
        $skip=hash_unset($query, ":skip");
        $limit=hash_unset($query, ":limit");
        $pager=hash_unset($query, ":pager");

        if ($pager) {
            $skip = $pager->start;
            $limit = $pager->page_size;
            Profiler::info("find/pager", array("c" => $this->sdc, "skip" => $skip, "limit" => $limit));
        }

        $mc=$this->MC->find($query, $fields); // MongoCursor

        if ($sort) {
            if (! is_array($sort)) { # space delimited fields. if field starts with "-" - sort desc
                $_sort=array();
                foreach(explode(' ', $sort) as $s) {
                    if ($s[0]=='-')
                        $_sort[substr($s,1)]=-1;
                    else
                        $_sort[$s]=1;
                }
                $sort=$_sort;
            }
            $mc=$mc->sort($sort);
        }

        if ($pager)
            $pager->total($mc->count());
        if ($skip)
            $mc=$mc->skip($skip);
        if ($limit)
            $mc=$mc->limit($limit);
        return $mc;
    }


    // IF query  is NOT AN ARRAY - $query  - array("_id" => $query)
    function update($query, array $newobj, array $options = []) {
        $query=$this->_query($query);
        return $this->MC->update($query, $newobj, $options);
    }


    // auto-generate (Sequence) IDs if no _id present
    // mongo insert does not allow(support) dot notation - see dot_insert
    function insert(array $data, array $options=[]) { # ID
        if (! isset($data["_id"]))
            $data["_id"]=self::next();
        $this->MC->insert($data, $options);
        return $data["_id"];
    }

    // dot notation for insert
    // we do not want to add checks to insert (slow it down)
    // example:
    //   M("test.test")->dot_insert( ["a.b" => 1, "a.c.e" => 2] )
    function dot_insert(array $data, array $options=[]) { # ID
        $r=[];
        foreach($data as $k => $d) {
            if (!strpos($k, ".")) {
                $r[$k]=$d;
                continue;
            }
            $path = explode(".", $k);
            $v = array_pop($path); // leaf node
            $p = &$r;
            foreach($path as $pc) // nodes only
                $p = &$p[$pc];
            $p[$v] = $d;
        }
        return $this->insert($r);
    }

    // remove record(s) from collection
    // alternative: unset( M::Alias()[$id] )
    function remove($query, array $options=[]) {
        $query=$this->_query($query);
        return $this->MC->remove($query, $options);
    }

    // --------------------------------------------------------------------------------
    // Internals

    // called from internal method self::i(..)
    function __construct($server, $name) {
        $this->server = $server;
        $this->sdc = $server ? $server.":".$name : $name;
        $this->name = $name;
        list($db, $col)=explode(".", $name, 2);
        $this->MC=M::Mongo($server)->__get($db)->__get($col);
    }

    // --------------------------------------------------------------------------------
    // M_OBJECT

    // instantiate M_Object from ID
    // never call directly !! use:
    //   - M::Alias($id)
    //   - $Collection($id)  -- see __invoke
    // NEGATIVE ID - instantiate object with autoload=false
    function go(/*int*/ $id) { # M_Object
        $id = (int) $id;
        $class = NVL( $this->config("class"), "M_Object" );
        if ($id < 0)
            return $class::i($this, - $id, false);
        $al = $this->config("autoload");
        if ($al === null)
            $al = true; // default autoload is true
        return $class::i($this, (int) $id, $al);
    }

    // instantiate M_Object from loaded data
    function go_d(array $data) { # M_Object
        $class = NVL( $this->config("class"), "M_Object" );
        return $class::i_d($this, $data);
    }

    // clean up cache, disable M_Object caching
    // runtime-only
    function disableObjectCache() {
        $this->O_CACHE=[];
        $this->configSet("no-cache", 1);
    }
    function get_object($id) { # M_Object | null
        if (isset($this->O_CACHE[$id]))
            return $this->O_CACHE[$id];
    }
    function set_object($id, M_Object $o) { # M_Object
        if (! $this->config("no-cache"))
            $this->O_CACHE[$id]=$o;
        return $o;
    }

    // echo M::Alias(id)->_field
    function formatMagicField($field, $value, $set = false) {
        throw new Exception("Typed collection required for magic fields");
    }

    // M::Alias(id)->_field = "XXX";
    function setMagicField($field, $value) {
        throw new Exception("Typed collection required for magic fields");
    }

    // --------------------------------------------------------------------------------


    // DB.COLLECTION Config from config.yaml
    function config($node) {
        return M::_config($this->server, $this->name.".".$node);
    }

    // runtime modification of Collection config
    function configSet($node, $value) {
        M::_configSet($this->server, $this->name.".".$node, $value);
    }

    function __toString() { # current collection db.name
        return $this->name;
    }

    // basic query rewriting
    // rewrite scalar queries requests to ["_id" => (int) $q]
    function _query($q) { # q
        if (! is_array($q))
            return ["_id" => (int)$q];
        return $q;
    }

    // fields
    function _fields($fields) {
        if (is_array($fields))
            return $fields;
        if (! $fields)
            return array();
        return explode(" ", $fields);
    }

    function applyTypes(array $kv) {
        return $kv;
    }

    // proxy calls to MongoCollection
    public function __call($meth, $args) {
        return call_user_func_array( array($this->MC, $meth), $args);
    }

    public function __invoke($x) {
        return $this->go($x);
    }

    function MC() { return $this->MC; }

}
