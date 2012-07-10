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
  Supports field aliases


  Better way to call exising functions:

  * pass id as scalar instead of array in all queries
    M::Alias()->findOne($id) instead of M::Alias()->findOne( ["_id" => (int) $id] )
    note: scalar ids will be converted to int.

  * Above example can be simplified even more as:
    (load autoloaded fields, load all if no autoload fields defined)
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

  * fields aliases
  * Types - see TypedCollection

Use:
  M::Alias()->method(..)               << recommended
  M("account.account")->method(..)

  M::Alias()[$id]  << PK lookup - get all (autoload) fields
  M::Alias()->MC() << get original MongoCollection

  * Instead of ["_id" => (int) $id] you can just pass $id


See also:
  M_TypedCollection

*/

class M_Collection implements ArrayAccess {

    const VERSION=2.1;

    public $name;       // db.col
    public $sdc;        // server:db.col | db.col
    public $server;     // as-is

    protected $MC;         // MongoCollection
    private $O_CACHE=[];   // ID => M_Object

    // NEVER CALL DIRECTLY
    //  - use M("server:db.col") | M("db.col") | M("Alias") | M::Alias()
    //
    // sdc = server:db.col | db.col
    static function i($sdc) {
        if (strpos($sdc, ":"))
            list($server, $name) = explode(":", $sdc, 2);
        else
            list($server, $name) = ["", $sdc];

        if ($f = M::C($server, $name.".field"))
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
        Profiler::in("M:one", [$this->sdc, $id, $field]);
        $r = $this->MC->findOne($q, [$field]);
        Profiler::out();
        if (! $r)
            return null;
        if (strpos($field,".")) {
            $p=explode(".", $field);
            foreach ($p as $k) {
                if (isset($r[$k]))
                    $r=$r[$k];
                else
                    $r=null;
            }
            return $r;
        }
        return $r[$field];
    }

    // find wrapper - returns array instead of MongoCollection
    function f($query, $fields="") { // Array
        Profiler::in_off("M::f", ["".$this, $query, $fields]);
        $r = iterator_to_array( $this->find($query, $fields) );
        Profiler::out();
        return $r;
    }

    // Safe Find
    // $query is name=>value only !!, no mongo operations allowed
    // scalar test for value is enough ($and, $or requires arrays)
    // NEVER trust users
    function sf($query, $fields="") { # Array | error+null
        if (! is_array($query)) {
            if (is_scalar($query))
                return $this->find($query, $fields);
            trigger_error("query must be scalar or array");
            die;
        }
        foreach($query as $k => $v) {
            if (is_array($v)) {
                trigger_error(sprintf("M2::sf key: %s must be scalar", $k));
                die;
            }
        }
        return $this->f($query, $fields);
    }

    // alias of f
    function findA($query, $fields="") { # Array
        return iterator_to_array( $this->find($query, $fields) );
    }

    // find wrapper - return array of {_id => M_Object}
    function fo($query, $fields="", $how="f") { # [M_Object, ...]
        $r = [];
        $fields = $this->_fields($fields);

        $qf = []; // queried fields
        if (isset($fields[0])) {
            foreach($fields as $f)
                $qf[$f]=1;
        }
        if (! $fields)
            $qf=true;  // all fields loaded

        foreach ($this->$how($query, $fields) as $e)
            $r[$e["_id"]]=$this->go_d($e, $qf);
        return $r;
    }

    // Safe find Objects
    // find wrapper - return array of M_Object
    function sfo($query, $fields="") { # [M_Object, ...]
        return $this->fo($query, $fields, "sf");
    }

    // alias of fo - findObjects
    function findO($query, $fields="") { # [M_Object, ...]
        return $this->fo($query, $fields);
    }

    // find records with specified ids and return array of Objects.
    // _id: { $in:[$ids] }
    function findOIn(array $ids, $fields="") { # array
        Profiler::in_off("M:findOIn", [$this->sdc, $ids, $fields]);
        $r = $this->fo(["_id" => ['$in' => $ids]], $fields);
        Profiler::out();
        return $r;
    }

    // find records with specified ids
    // _id: { $in:[$ids] }
    function findIn(array $ids, $fields="") { # array
        Profiler::in_off("M:find_in", [$this->sdc, $ids, $fields]);
        $r = $this->f(["_id" => ['$in' => $ids]], $fields);
        Profiler::out();
        return $r;
    }

    // alias of findIn, legacy syntax support
    function find_in(array $ids, $fields="") { # array
        return $this->findIn($ids, $fields);
    }

    // 1  field  - field => {fields}
    // 2  fields - hash f1  => f2
    // 3+ fields - hash f1  => {f1, f2:, ....}
    // Ex: M("user.user")->hash(0, "_id name")   => hash {_id => name}
    //     M("user.user")->hash(0, "email name") => hash {email => name}
    //     M("user.user")->hash(0, "email")      => hash {email => {....}}
    function hash($query, $fields) { # hash K=>V | K => [V,V,..]
        if (! $query) $query=array();
        $query=$this->_query($query);
        $f = explode(" ", $fields);
        $c = count($f);
        $r = [];
        $k = $f[0];
        if ($c==1)
           $f=array();
        if (! isset($query[$k]))
            $query[$k]=array('$exists' => true);
        if ($c==2) {
            $vf=$f[1];
            if (! isset($query[$vf]))
                $query[$vf] = ['$exists' => true];
            Profiler::in("M:hash2", [$this->sdc, $query, $fields]);
            foreach ($this->MC->find($query, $f) as $e) {
                $r[(int)$e[$k]] = $e[$vf];
            }
            Profiler::out();
            return $r;
        }
        Profiler::in("M:hash", [$this->sdc, $query, $fields]);
        foreach ($this->MC->find($query, $f) as $e)
            $r[$e[$k]] = $e;
        Profiler::out();
        return $r;
    }


    // Update or Insert
    function upsert($query, array $newobj, array $options = array() ) {
        $options["upsert"]=true;
        return $this->update($query, $newobj, $options);
    }

    function updateMultiple($q, $newobj, array $options = array() ) {
        return $this->update($q, $newobj, array("multiple" => true) + $options);
    }

    // update + set/unset | insert
    function upsertSet($id, array $set, $unset="") {
        $wh = ["_id" => (int) $id];
        $ts = $set ? ['$set' => $set] : [];
        if ($unset)
            $ts['$unset']= is_array($unset) ? $unset : M::qk($unset);
        if ($this->one($id)) {
            Profiler::in("M2:update", [$this->sdc, $wh, $ts]);
            $this->MC->update($wh, $ts);
            Profiler::out();
            return;
        }
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
        if (! isset($r[1])) {
            trigger_error("not enough params");
            die;
        }
        if (array_key_exists(2, $r)) {
            if (is_array($r[2])) {
                trigger_error("can't mix KV-Array and 'key, value' syntax");
                die;
            }
            $r[1] = [$r[1] => $r[2]];
        }

        $r[1] = $this->_kv($r[1]);

        Profiler::in_off("M2:$op", [$this->sdc, $r[1]]);
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
    function add($q, $field /*, value, value, value */) {
        $a=func_get_args();
        array_shift($a);
        array_shift($a);
        return $this->addAll($q, $field, $a);
    }

    // addToSetAll
    function addAll($q, $field, array $values) {
        $q = $this->_query($q);
        Profiler::in("M::add", ["".$this, $q, $field, $values]);
        if (count($values) == 1)
            $this->MC->update($q, ['$addToSet' => [$field => $values[0]]]);
        else
            $this->MC->update( $q,
                               ['$addToSet' => [$field => ['$each' => $values]]]
                               );
        Profiler::out();
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
    // * tracking collection "sequence" located in the same db as collection
    function next($inc=1) { //
        $buffer=$this->C("insert-buffer");
        if (! $buffer)
            return M_Sequence::next($this->name, $inc, true); // autocreate
        if ($inc>1) {
            // even we can - we'll not - you should use one approach only
            throw new RuntimeException("can't mix insert-buffer and inc!=1");
        }
        return $this->buffered_next($buffer);
    }
    
    // APC based sequence caching
    // buffer - number of IDs to buffer
    // will perform one mongo request per $buffer queries
    protected function buffered_next($buffer) {
        $KEY = "insert-buffer".$this->sdc;
        $c = apc_dec($KEY."-");
        $v = apc_fetch($KEY);
        if ($c === -1 || $c === false) {
            $v = M_Sequence::next($this->name, $buffer, true);
            apc_store($KEY, $v);
            apc_store($KEY."-", $buffer-1);
            $c = $buffer-1;
        }
        if ($c < -1) { // concurrency conflict
            $w = 10;
            $ok = 0;
            foreach(range(1, 14) as $r) {
                usleep($w);
                $c = apc_dec($KEY."-");
                if ($c >= 0) {
                    $ok = 1;
                    break;
                }
                $w <<= 1;
            }
            // fallback to original method if can't solve it right way
            if (! $ok)
                return M_Sequence::next($this->name, 1, true);
        }
        return $v-$c;
    }    

    function lastId() { # get last id from collection
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
    function groupBy($field_op, $group_by="", array $where=[]) { # { $group_fields, $sum_fields }
        return M_Helper::groupBy($this->MC, $field_op, $group_by, $where);
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
        $this->update($offset, ['$set' => $kv]);
    }

    // unset( M::Alias()[$id] )
    function offsetUnset($offset) {
        $this->remove($offset);
    }

    // isset( M::Alias()[$id] )
    function offsetExists($offset) { # id of found record
        return $this->one($offset);
    }

    // M::Alias()[$id] == M::Alias()->findOne((int)$id, M::Alias()->C("autoload"))
    function offsetGet($offset) { # findOne
        return $this->findOne((int) $offset, (string) $this->C("autoload"));
    }

    // --------------------------------------------------------------------------------
    // EXISTING MONGO FUNCTIONS

    // see also: one, offsetGet (M::Alias()[$where])
    // Ex: M("account.account")->findOne(1006)
    // Ex: M("account.account")->findOne(1006, "balance date_created")
    function findOne($query, $fields="") { # data
        Profiler::in("M:findOne", [$this->sdc, $query, $fields]);
        $query=$this->_query($query);
        $fields=$this->_fields($fields);
        $r = $this->MC->findOne($query, $fields);
        Profiler::out();
        return $r;
    }

    // SPECIAL query fields: ":sort", ":skip", ":limit"
    //    :sort - hash or "space delimited field list" (if field starts with "-" - sort descending)
    // SPECIAL query field: ":pager" - pointer to Pager class instance.
    // IMPORTANT. $pager->page_size has priority over :limit as well as $pager->start has priority over :skip-
    function find($query=[], $fields="") { # MongoCursor
        Profiler::in("M2::find", [$this->sdc, $query, $fields]);
        $query  = $this->_query($query);
        $fields = $this->_fields($fields);

        $sort  = M::hash_unset($query, ":sort");
        $skip  = M::hash_unset($query, ":skip");
        $limit = M::hash_unset($query, ":limit");
        $pager = M::hash_unset($query, ":pager");

        if ($pager) {
            $skip  = $pager->start;
            $limit = $pager->page_size;
            Profiler::info("find/pager", ["skip" => $skip, "limit" => $limit]);
        }

        $mc=$this->MC->find($query, $fields); // MongoCursor
        if ($sort) {
            if (! is_array($sort)) { # space delimited fields. if field starts with "-" - sort desc
                $_sort=array();
                foreach (explode(' ', $sort) as $s) {
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
            $mc = $mc->skip($skip);
        if ($limit)
            $mc = $mc->limit($limit);

        Profiler::out();
        return $mc;
    }


    // IF query  is NOT AN ARRAY - $query  - array("_id" => $query)
    function update($query, array $newobj, array $options = []) {
        Profiler::in("M2:update", [$this->sdc, $query, $newobj, $options]);
        $query = $this->_query($query);
        $r = $this->MC->update($query, $newobj, $options);
        Profiler::out();
        return $r;
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
    //   M("test.test")->dotInsert( ["a.b" => 1, "a.c.e" => 2] )
    function dotInsert(array $data, array $options=[]) { // ID
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
        Profiler::in("M:remove", [$this->sdc, $query]);
        $query=$this->_query($query);
        $r = $this->MC->remove($query, $options);
        Profiler::out();
        return $r;
    }

    // remove all data, keep indexes, reset sequences
    // keeps indexes in place
    function reset() {
        $this->remove([]);
        M_Sequence::reset("".$this);
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

    // base class for M_Object
    function _class() { # M_Object or C("class")
        return ($c = $this->C("class")) ? $c : "M_Object";
    }

    // instantiate M_Object from ID
    // never call directly !! use:
    //   - M::Alias($id)
    //   - $Collection($id)  -- see __invoke
    // NEGATIVE ID - instantiate object with autoload=false
    function go(/*int*/ $id) { # M_Object
        $id = (int) $id;
        $class = $this->_class();
        if ($id < 0)
            return $class::i($this, - $id, false);
        $al = $this->C("autoload"); // config
        return $class::i($this, (int) $id, $al === null ? true : $al);
    }

    // instantiate M_Object from loaded data
    // see M_Object::i_d for details
    function go_d(array $data, $loaded_fields=false) { # M_Object
        $class = $this->_class();
        return $class::i_d($this, $data, $loaded_fields);
    }

    // clean up cache, disable M_Object caching
    // runtime-only
    function disableObjectCache() {
        $this->O_CACHE=[];
        $this->C_set("no-cache", 1);
    }
    // for use by M_Object ONLY
    /*PRIVATE*/ function _getObject($id) { # M_Object | null
        if (isset($this->O_CACHE[$id]))
            return $this->O_CACHE[$id];
    }
    // for use by M_Object ONLY
    /*PRIVATE*/ function _setObject($id, M_Object $o) { # M_Object
        if (! $this->C("no-cache"))
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
    // Config - C("xxx") wrapper
    function C($node) {
        // return M::C($this->server, $this->name.".".$node);
        $node = $this->name.".".$node;
        if (! $this->server)
            return CC("m2.".$node);
        return CC("m2-".$this->server.".".$node);
    }

    // runtime modification of Collection config
    function C_set($node, $value) {
        M::C_set($this->server, $this->name.".".$node, $value);
    }

    function __toString() { # current collection db.name
        return $this->name;
    }

    // basic query rewriting
    // rewrite scalar queries requests to ["_id" => (int) $q]
    // see TypedCollection for more complex version
    function _query($q) { # q
        if (! is_array($q))
            return ["_id" => (int)$q];
        return $q;
    }

    // only for typed collections
    // aliases + magic fields + types + more
    /* internal */ function _kv(array $kv) { // $kv
        return $kv;
    }

    // fields - space delimited string, array of fields, array of key => (1 | -1)
    // aliases are supported
    function _fields($fields) { // fields as array
        if (! $fields)
            return [];
        if (! is_array($fields))
            $fields = explode(" ", $fields);
        return $fields;
    }

    // proxy calls to MongoCollection or to static methods in M_Object(or class that extends it)
    public function __call($meth, $args) {
        if ( method_exists($this->MC, $meth) ) {
            Profiler::in("M2:MC:$meth", [$this->sdc, $args]);
            $r = call_user_func_array([$this->MC, $meth], $args);
            Profiler::out();
            return $r;
        }
        return call_user_func_array([$this->_class(), $meth], $args);
    }

    public function __invoke($x) {
        return $this->go($x);
    }

    function MC() { return $this->MC; }

}
