<?

/*

Typed collection

Complex Type support for Mongo

*/

final class M_TypedCollection extends M_Collection {

    public $type; // field => type

    // public $T ;

    function __construct($server, $sdc, array $field2type) {
        $this->type = $field2type;
        $this->type['_id'] = 'int';  // general framework assumption
        // $this->T = new M_Type();
        parent::__construct($server, $sdc);
    }

    // find with Magic Fields Support ($fields)
    // "_alias" syntax is not supported
    // result will have all original find fields and all magic fields
    function fm($q, /*string*/ $fields) { # { _ID => { row_data, magic_fields }}
        if (! is_string($fields))
            throw new InvalidArgumentException("field list must be string");

        $fields = explode(" ", $fields);
        $mf = []; // magic fields
        foreach($fields as & $f) {
            if ($f[0]!='_')
                continue;
            $f = substr($f, 1);
            $mf[$f] = $this->type[$f];
        }
        if (! $mf)
            throw new InvalidArgumentException("no magic fields in queried");

        $z = $this->f($q, $fields); // result
        foreach($z as &$r) {
            foreach($mf as $f => $T)
                if (isset($r[$f]))
                    $r["_".$f] = M_Type::getMagic($r[$f], $T);
        }
        return $z;
    }

    // insert, $set
    // array type enforced
    function applyTypes(array $kv) {  # $kv
        $t = $this->type;
        foreach($kv as $k => &$v) {
            $T = isset($t[$k]) ? $t[$k] : 0;
            if (! $T)
                continue;
            $v=M_Type::apply($v, $T);
            if ($T=='array' && ! is_array($v)) {
                trigger_error("array expected. key: '$k'", E_USER_ERROR);
                die;
            }
        }
        return $kv;
    }

    // apply types for queries
    function _query($kv) { # $kv
        if (! is_array($kv))
            return ["_id" => (int)$kv];

        static $logic = array('$or'=>1, '$and'=>1, '$nor' =>1);

        if ($fa = $this->C("field-alias"))
            return $this->_kv_aliases($fa, $kv);

        // kv is an array
        foreach($kv as $k => &$v) {
            // logic: {$op: [$k,$k,...]}
            // $or, $and, $nor
            if ($k[0] == '$') { // $and $or $nor
                if (! $logic[$k])
                    Log::alert("unknown operation: $k");
                if (! is_array($v))
                    Log::alert("operator $k: array expected");
                foreach($v as &$t)
                    $t=$this->_query($t);
                continue;
            }

            $T = isset($this->type[$k]) ? $this->type[$k] : 0;
            if (! $T) {
                $T = isset($this->type["$k.*"]) ? $this->type["$k.*"] : 0;
                if (! $T)
                    continue;
            }

            if (is_array($v)) {
                $v=$this->applyTypeQuery($v, $T);
                continue;
            }

            $v=M_Type::apply($v, $T);
        }

        return $kv;
    }

    // find query
    //   {f: {$op:v}}        $ne, $gt, $gte, $lt, $lte
    //   {f: {$op:[vals]}},  $in, $nin, $all
    //   {f: {$op:{query}}   $not
    //   {f: {$op:{f:v}}}    $elemMatch  -- not supported - no translation made
    function applyTypeQuery($v, $T) { # $v
        static $o2=array('$ne'=>1, '$gt' =>1, '$gte'=>1, '$lt' =>1, '$lte' =>1);
        static $o3=array('$in'=>1, '$nin'=>1, '$all'=>1, '$mod'=>1);

        #v("atq: ".json_encode($v));
        // operation(s) on field $f, type $T
        foreach($v as $op => & $val) {
            if (isset($o2[$op])) // gt, lt, ne
                $val=M_Type::apply($val, $T);

            if (isset($o3[$op])) { // in, nin, all
                foreach($val as &$_)
                    $_=M_Type::apply($_, $T);
            }
            if ($op==='$not')
                $val = $this->applyTypeQuery(0, $val, $T);
            // $exists, size, $regex, $elemMatch, $where
        }
        return $v;
    }

    // UPDATE:
    //   {$op:{f:v}}     - $inc, $set, $addToSet, $push
    //   {$op:{f,[vals]} - $pushAll
    function applyTypeUpdate($op, $v) { # $v
         static $ops=array('$inc' => 1, '$set' =>1,
                          '$addToSet' => 2, '$push' => 2,  // array type support
                          '$pushAll' => 3);

        if (! isset($ops[$op]))
            return $v;

        $k=$ops[$op];

        if ($k==1)    // $inc, $set
            return $this->applyTypes($v);

        if ($k==2) {  // ARRAY(TYPE)
            foreach($v as $k => &$_) {
                if (! isset($this->type["$k.*"]))
                    continue;
                $_=M_Type::apply($_, $this->type["$k.*"]);
            }
            return $v;
        }

        if ($k==3) { // k => [...]
            foreach($v as $k => &$_) {
                if (! isset($this->type["$k.*"]))
                    continue;
                $T = $this->type["$k.*"];
                foreach($_ as &$__)
                    $__=M_Type::apply($__, $T);
            }
            return $v;
        }
        Log::alert("bad op");
    }

    function update($query, array $newobj, array $options = []) {
        foreach($newobj as $k => &$v) {
            if ($k[0] === '$')
                $v = $this->applyTypeUpdate($k, $v);
        }
        return parent::update($query, $newobj, $options);
    }

    function insert(array $data, array $options=[]) { // ID
        if (! isset($data["_id"]))
            $data["_id"]=self::next();
        if ($fa = $this->C("field-alias"))
            $data = $this->_kv_aliases($fa, $data);
        $data = $this->applyTypes($data);
        $this->MC->insert($data, $options);
        return $data["_id"];
    }

    // insert that honors "node.field" notation
    function dotInsert(array $data, array $options=[]) { // ID
        return parent::dotInsert( $this->applyTypes($data) );
    }

    // smart addToSet
    // add($q, "key", v1, v2, v3, ...)
    // add one or more values to set
    function add($q, $field /* value, value, value */) {
        $a = func_get_args();
        $T = isset($this->type[$a[1].".*"]) ? $this->type[$a[1].".*"] : null;
        if ($T) {
            foreach($a as $k => &$_)
                if ($k > 1)
                    $_ = M_Type::apply($_, $T);
        }
        call_user_func_array('parent::add', $a);
    }

    // echo M::Alias(id)->_field
    function formatMagicField($field, $value, $set = false) { // magic value
        $op = ["M_Type", $set ? "setMagic" : "getMagic"];
        $T = @$this->type[$field];
        if (! $T) {
            if ($T = @$this->type["$field.*"]) {
                if (! is_array($value))
                    return (array) $value;
                $r=[];
                foreach($value as $k => $v)
                    $r[$k] = $op($v, $T);
                return $r;
            }
            throw new Exception("Type required for magic field $field");
        }
        return $op($value, $T);
    }

    // M::Alias(id)->_field = "XXX";
    function setMagicField($field, $value) { // void
        return $this->formatMagicField($field, $value, 1);
    }

    // Human format of already loaded data
    // apply Mafic types to loaded data
    // kv - {key:value}
    function allMagic(array $kv, $prefix="") { // kv << magic representation when possible
        foreach($kv as $k => & $v) {
            $p = $prefix.$k; // dot separated string path
            // ARRAY OF {TYPE}
            if ($T = @$this->type["$p.*"]) {
                if (! is_array($v)) {
                    trigger_error("".$this.": node $p must be an array of $T");
                    die;
                }
                $r=[];
                foreach($v as $_k => $_v)
                    $r[$_k] = M_Type::getMagic($_v, $T, false);
                $v = $r;
                continue;
            }

            if ($T = @$this->type[$p]) {
                $v = M_Type::getMagic($v, $T, false);
                continue;
            }

            // RECURSION
            if (is_array($v) && ! isset($v[0])) { // assoc array
                $v = $this->allMagic($v, "$k.");
            }
        }
        return $kv;
    }

    // -------------------------------------------------------------------------------------
    // 2.1 =================================================================================

    // support for aliases in {KEY => QUERY} data
    // magic field aliases are supported as well
    //
    // used for:
    //   finders
    //   insert
    //   update ops (set, inc, addToSet, ...)
    //
    // not used in generic update ??? why???
    /* internal */ function _kv_aliases(array $kv) { // $kv
        $T = $this->type;
        $f = 0; // alias found flag
        $rename = [];
        foreach ($kv as $f => $v) {
            if (is_array($T[$f]) && $T[$f][0]=='alias') {
                $rename[$f] = $T[$f][1];
                continue;
            }
            if ($f[0]=='_') { // magic alias
                $f2 = substr($f, 1);
                $t=@$T[$f2];
                if (! $t)
                    throw new DomainException("type required for magic field ".$this.".$f2");
                if (is_array($t) && $t[0]=='alias')
                    $rename[$f] = "_".$t[1];
            }
        }
        if (! $rename)
            return $kv;
        foreach ($rename as $from => $to) {
            $kv[$to] = $kv[$from];
            unset($kv[$from]);
        }
        return $kv;
    }


    // fields - space delimited string, array of fields, array of key => (1 | -1)
    // convert to array, process aliases
    function _fields($fields) { // fields as array
        if (! $fields)
            return [];
        if (! is_array($fields))
            $fields = explode(" ", $fields);
        $T = $this->type;
        if (! $T)
            return $fields;
        if (isset($fields[0])) { // list of fields
            $r=[];
            foreach($fields as $f) {
                if (isset($T[$f]) && ($t = $T[$f]) && is_array($t) && $t[0]=='alias')
                    $r[]=$t[1];
                else
                    $r[] = $f;
            }
            return $r;
        }
        // field => 1,0 case
        $r=[];
        foreach($fields as $f => $v) {
            if (isset($T[$f]) && ($t = $T[$f]) && is_array($t) && $t[0]=='alias')
                $r[$t[1]] = $v;
            else
                $r[$f] = $v;
        }
        return $r;
    }




}

?>
