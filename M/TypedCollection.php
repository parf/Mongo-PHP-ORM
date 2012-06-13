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

    function insert(array $data, array $options=[]) { # ID
        parent::insert( $this->applyTypes($data) );
    }

    // insert that honors "node.field" notation
    function dotInsert(array $data, array $options=[]) { # ID
        parent::dotInsert( $this->applyTypes($data) );
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
                    $_ = M_Type::apply($T, $_);
        }
        call_user_func_array('parent::add', $a);
    }

    // echo M::Alias(id)->_field
    function formatMagicField($field, $value, $set = false) { // magic value
        $T = @$this->type[$field];
        if (! $T)
            throw new Exception("Type required for magic field $field");
        if ($set)
            return M_Type::setMagic($value, $T);
        return M_Type::getMagic($value, $T);
    }

    // M::Alias(id)->_field = "XXX";
    function setMagicField($field, $value) { // void
        return $this->formatMagicField($field, $value, 1);
    }


    // apply Mafic types to loaded data
    // kv - {key:value}
    function allMagic(array $kv) { // kv << magic representation when possible
        foreach($this->type as $F => $T) {
            if (strpos($F, ".")) {
                // TODO
                continue;
            }
            if (! isset($kv[$F]))
                continue;
            try {
                $kv[$F] = M_Type::getMagic($kv[$F], $T);
            } catch(Exception $ex) {
                1; // suppress exceptions
            }
        }
        return $kv;
    }

}

?>
