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
            if ($f=='_id')
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
    function applyTypes(array $kv, $obj=null) {  # $kv
        $t = $this->type;
        $rename = [];
        foreach($kv as $k => &$v) {
            if ($k[0]=='_') { // magic field save
                if ($k == '_id') {
                    $v = (int) $v;
                    continue;
                }
                $f = substr($k, 1);
                $rename[$k] = $f;
                $T = $t[$f];
                if (! $T)
                    throw new InvalidArgumentException("Type required for magic field $f");
                $v = M_Type::setMagic($v, $T);
                continue;
            }
            if (! isset($t[$k]))
                continue;
            $T = $t[$k];
            if ($obj && is_array($T) && $T[0]=='method') {
                $m = [$obj, "set$k"];
                if (is_callable($m))
                    $v = $m($v);
                continue;
            }
            if (is_array($T) && $T[0]=='alias') {
                $rename[$k]  = $T[1];
                $T = @$t[$T[1]];
                if (! $T)
                    continue;
            }

            $v = M_Type::apply($v, $T);
            $want_array = ($T=='array' || (is_array($T) && $T[0]=='array')); // typed or untyped array
            if ($want_array && ! is_array($v))
                throw new InvalidArgumentException("trying to set scalar to array element. key: '$k'");
        }
        foreach ($rename as $from => $to) {
            $kv[$to] = $kv[$from];
            unset($kv[$from]);
        }
        return $kv;
    }

    // apply types for queries
    // processes aliases
    function _query($kv) { # $kv
        if (! is_array($kv))
            return ["_id" => (int)$kv];

        static $logic = array('$or'=>1, '$and'=>1, '$nor' =>1);

        // $kv = $this->_kv_aliases($kv); - simplified version integrated
        // kv is an array
        $rename = [];
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
            if (! $T)
                continue;

            if (is_array($T) && $T[0]=='alias') {
                $rename[$k] = $T[1];
                $k = $T[1];
                $T = isset($this->type[$k]) ? $this->type[$k] : 0;
                if (! $T)
                    continue;
            }

            if (is_array($v)) {
                $v = $this->applyTypeQuery($v, $T);
                continue;
            }
            $v = M_Type::apply($v, $T);
        }

        if ($rename) {
            foreach ($rename as $from => $to) {
                $kv[$to] = $kv[$from];
                unset($kv[$from]);
            }
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
        if ($k==2) {  // apply ARRAY of $T
            foreach($v as $k => &$_) {
                $T = @$this->type[$k];
                if (! is_array($T) || $T[0]!='array')
                    continue;
                $_=M_Type::apply($_, $T[1]);
            }
            return $v;
        }
        if ($k==3) { // $op => [v1, v2, ...]
            foreach($v as $k => &$_) {
                $T = @$this->type[$k];
                if (! is_array($T) || $T[0]!='array')
                    continue;
                foreach($_ as &$__)
                    $__=M_Type::apply($__, $T[1]);
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
        $data = $this->_kv_aliases($data);
        $data = $this->applyTypes($data);
        if ($this->C("strict")) {
            foreach($data as $k => $v)
                if (! @$this->type[$k])
                    throw new DomainException("unknown field $this.$k");
        }
        if (! isset($data["_id"]))
            $data["_id"]=self::next();
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
        $T = @$this->type[$a[1]];
        if (is_array($T) && $T[0]=='array') {
            $T = $T[1]; // array of type
            foreach($a as $k => &$_)
                if ($k > 1) // $a[2], ... are fields
                    $_ = M_Type::apply($_, $T);
        }
        call_user_func_array('parent::add', $a);
    }

    // echo M::Alias(id)->_field
    function formatMagicField($field, $value, $set = false) { // magic value
        $op = ["M_Type", $set ? "setMagic" : "getMagic"];
        $T = @$this->type[$field];
        if (! $T)
            throw new Exception("Type required for magic field $field");
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
        $rename = [];
        foreach ($kv as $f => $v) {
            if ($f[0]=='_' && $f!='_id') { // magic field
                // magic field or alias
                $f2 = substr($f, 1);
                $t=@$T[$f2];
                if (! $t)
                    throw new DomainException("type required for magic field ".$this.".$f2");

                if (is_array($t) && $t[0]=='alias')
                    $rename[$f] = "_".$t[1];
                continue;
            }

            if (! isset($T[$f]))
                continue;

            $t = $T[$f];
            if (is_array($t) && $t[0]=='alias')
                $rename[$f] = $t[1];
        }
        if (! $rename)
            return $kv;
        foreach ($rename as $from => $to) {
            $kv[$to] = $kv[$from];
            unset($kv[$from]);
        }
        return $kv;
    }

    // INSERT / SET / $OP
    // all-in-one key=>value type support
    // 1. resolve aliases
    // 2. apply types
    // 3. magic fields (_magic = 'value' resolved via M_Type::setMagic)
    // 4. strict field check (when needed) (only defined fields allowed)
    // 5. resolve method field (M_Object based)
    function _kv(array $kv, $obj=null, $array_assign_check=false) { // $kv
        $strict = $this->C("strict");
        $T = $this->type; // FIELD => TYPE
        $rename = [];
        foreach ($kv as $f => &$v) {
            if ($f=='_id') {
                $v = (int) $v;
                continue;
            }
            if ($f[0]=='_') { // MAGIC field or alias
                $f0 = $f;
                $f = substr($f, 1);
                $t = @$T[$f];
                if (is_array($t) && $t[0]=='alias') {
                    $f = $t[1];
                    $t = @$T[$f];
                }
                if (! $t)
                    throw new DomainException("type required for magic field $this.$f");
                $v = M_Type::setMagic($v, $t);
                $rename[$f0] = $f;
                continue;
            }

            $t = @$T[$f]; // current field type

            // METHODS && ALIASES
            if ($t && is_array($t)) {
                if ($t[0]=='alias') {
                    $rename[$f]  = $t[1];
                    $f = $t[1];
                    $t = @ $T[$f];
                }
                if ($obj && $t[0]=='method') {
                    $m = [$obj, "set$f"];
                    if (is_callable($m))
                        $v = $m($v);
                    continue;
                }
            }

            if (! $t) { // untyped
                if ($strict)
                    throw new DomainException("unknown field $this.$f");
                continue;
            }

            $v = M_Type::apply($v, $t);

            if (! $array_assign_check)
                continue;

            $want_array = ($t=='array' || (is_array($t) && $t[0]=='array')); // typed or untyped array
            if ($want_array && ! is_array($v))
                throw new InvalidArgumentException("trying to set scalar to array field $this.$f");
        } // foreach
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
