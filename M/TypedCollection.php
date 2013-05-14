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
        if (! isset($this->type["_id"]))
            $this->type['_id'] = 'int';  // general framework assumption
        parent::__construct($server, $sdc);
    }

    // find with Magic Fields Support and clever Aliases in ($fields)

    // result will have all original find fields and all magic fields
    /* legacy */ function fm($q, /*string*/ $fields) { # { _ID => { row_data, magic_fields, aliases }}
        if (! is_string($fields))
            throw new InvalidArgumentException("field list must be string");
        return $this->f($q, $fields);
    }

    // result will have all original find fields and all magic fields
    // IMPORTANT - aliases and magic fields ONLY supported when fields is string
    // in order to exclude specific fields from result use "-field" notation
    // example: M::Merchant()->f(['_id'=>5094], "site -_id")
    //          M::Merchant()->f(['_id'=>5094], "-site -uri")
    function f($q, /*string*/ $fields='') { # { _ID => { row_data, magic_fields, aliases }}
        if (! is_string($fields) || ! $fields)
            return parent::f($q, $fields);

        $fields = explode(" ", $fields);
        $mf = [];    // magic fields
        $copy = [];  // aliases
        $exclude = [];  // exclude fields
        $ex_id = 0;      // exclude _id
        foreach ($fields as & $f) {
            if ($f=='_id')
                continue;
            if ($f[0]=='-') {
                if ($f=='-_id') {
                    $ex_id = 1;
                    continue;
                }
                $f = substr($f, 1);
                $exclude[] = $f;
                continue;
            }
            $t = @$this->type[$f];
            if ($t && is_array($t) && $t[0]=='alias') { // ALIASES
                $copy[$t[1]] = $f;
                $f = $t[1];
                continue;
            }
            if ($f[0]!='_')
                continue;
            // MAGIC FIELD
            $f = substr($f, 1);
            $t = $this->type[$f];
            if (is_array($t) && $t[0]=='alias') { // ALIASES
                $copy["_".$t[1]] = "_".$f;
                $f = $t[1];
                $t = $this->type[$f];
            }
            $mf[$f] = $t;
        }

        if ($exclude) { // excluded fields
            $f2 = [];
            // convert fields to ["field" => true / false]
            foreach ($fields as $f)
                $f2[$f] = true;
            foreach ($exclude as $f)
                $f2[$f] = false;
            $fields = $f2;
        }

        // do we have magic or aliases
        if (! $mf && ! $copy && ! $ex_id)
            return parent::f($q, $fields);

        $z = parent::f($q, $fields); // result
        foreach($z as &$r) {
            foreach($mf as $f => $t)
                if (isset($r[$f]))
                    $r["_".$f] = M_Type::getMagic($r[$f], $t);
            foreach($copy as $from => $to) {
                if (isset($r[$from])) {
                    $r[$to]=$r[$from];
                    unset($r[$from]);
                }
            }
            if ($ex_id)
                unset($r["_id"]);
        }
        return $z;
    }

    // apply types for queries
    // processes aliases
    function _query($kv) { # $kv
        if (! is_array($kv))
            return ["_id" => (int)$kv];

        static $logic = ['$or'=>1, '$and'=>1, '$nor' =>1];

        $strict = $this->C("strict");

        // kv is an array
        $rename = [];
        foreach($kv as $k => &$v) {
            // logic: {$op: [$k,$k,...]}
            // $or, $and, $nor
            if ($k[0] == '$') { // $and $or $nor
                if (! $logic[$k])
                    throw new RuntimeException("$this unknown operation: $k");
                if (! is_array($v))
                    throw new RuntimeException("$this operator $k: array expected");
                foreach($v as &$t)
                    $t=$this->_query($t);
                continue;
            }
            if($k[0]==':')
                continue;
            $t = @$this->type[$k];
            if (! $t) {
                if ($strict) {
                    #check that no upper fields has type "mixed"
                    $parts = explode(".", $k);
                    $ff = '';
                    $bypass = 0;
                    $t=@$this->type;
                    foreach($parts as $part){
                        if ($ff) {
                            $ff .= '.';
                        }
                        $ff .= $part;
                        if ($t=@$t[ $part ]) {
                            if ((is_array($t) && $t[0] == 'array' && $t[1] == 'mixed') || $t == 'mixed') {
                                $bypass = 1;
                                break;
                            }
                        }
                    }
                    if (!$t && !$bypass) {
                        throw new DomainException("unknown field: $this.$k");
                    }
                }
                continue;
            }

            if (is_array($t) && $t[0]=='alias') {
                $rename[$k] = $t[1];
                $k = $t[1];
                $t = @$this->type[$k];
                if (! $t)
                    continue;
            }

            if (is_array($v)) {
                $v = $this->applyTypeQuery($v, $t);
                continue;
            }
            $v = M_Type::apply($v, $t);
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
        static $o2=['$ne'=>1, '$gt' =>1, '$gte'=>1, '$lt' =>1, '$lte' =>1];
        static $o3=['$in'=>1, '$nin'=>1, '$all'=>1, '$mod'=>1];

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
         static $ops=['$inc' => 1, '$set' =>1,
                          '$addToSet' => 2, '$push' => 2,  // array type support
                          '$pushAll' => 3];

        if (! isset($ops[$op]))
            return $v;
        $k=$ops[$op];
        if ($k==1)    // $inc, $set
            return $this->_kv($v);
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
        $data = $this->_kv($data);
        if (! isset($data["_id"]))
            $data["_id"]=self::next();
        $this->MC->insert($data, $options);
        return $data["_id"];
    }

    // insert that honors "node.field" notation
    function dotInsert(array $data, array $options=[]) { // ID
        return parent::dotInsert( $this->_kv($data) );
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

    // INSERT / SET / $OP
    // all-in-one key=>value type support
    // 1. resolve aliases
    // 2. apply types
    // 3. resolve magic fields (_magic = 'value' resolved via M_Type::setMagic)
    // 4. strict field check (when needed) (only defined fields allowed)
    // 5. resolve method field (M_Object based)
    function _kv(array $kv, $obj=null, $array_assign_check=false) { // $kv
        $strict = $this->C("strict");
        $T = $this->type; // FIELD => TYPE
        $rename = [];
        foreach ($kv as $f => &$v) {
            if ($f=='_id') {
                if (! isset($T[$f]))
                    $v = (int) $v;
                else
                    $v = M_Type::apply($v, $T[$f]);
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
            // METHODS && ALIASES && SUB_ARRAYS
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
                if (is_array($v) && $t[0] == 'hash') {
                    //Assign array of $sub_field=>value to parent node.
                    //Both $v and type are arrays
                    foreach ($v as $sub_f=>&$sub_v){
                        //recursively call _kv
                        $this->_kv(["$f.$sub_f"=>$sub_v], $obj, $array_assign_check);
                    }
                    continue;
                }
            }

            if (! $t) { // untyped
                // node.INDEX (node.123) support

                if ($p=strrpos($f, '.')) {
                    if (is_numeric(substr($f, $p+1))) {
                        $t=@$T[ substr($f, 0, $p) ];
                        // looking for array of $type
                        if ($t/* && is_array($t)*/) {
                            if ($t[0]=='alias') {
                                $rename[$f] = $t[1].".".substr($f, $p+1);
                                $t=@$T[ $t[1] ];
                            }
                            if ($t[0]=='array') {
                                $v = M_Type::apply($v, $t[1]);
                                continue;
                            }
                            throw new DomainException("array type expected for $this.$f");
                        }
                   }
                }
                
                if ($strict) {
#v("=======>$f");
                    #check that no upper fields has type "mixed"
                    $parts = explode(".", $f);
                    $ff = '';
                    $bypass = 0;
                    $t=@$T;
                    foreach($parts as $part){
                        if ($ff) {
                            $ff .= '.';
                        }
                        $ff .= $part;
                        $t = $t[$part];
                        if ($t) {
                            if (is_array($t)) {
                                if ($t[0] == 'array' && $t[1] == 'mixed') {
                                    $bypass = 1;
                                    break;
                                }
                                if ($t[0] == 'hash') {
                                    continue;
                                }
                                $v = M_Type::apply($v, $t);
                            }
                            $v = M_Type::apply($v, $t);
#v("treat as ".x2s($t));
                            $bypass = 1;
                            continue;
                        }
                    }
                    if (!$bypass) {
                        throw new DomainException("unknown field $this.$f");
                    }
                }
                    
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


    // fields - space delimited string, array of fields, array of key => (true | false)
    // convert to array, process aliases
    function _fields($fields) { // fields as array
        if (! $fields)
            return [];
        if (! is_array($fields))
            $fields = explode(" ", $fields);
        $T = $this->type;
        // if (! $T)
        //    return $fields;
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
        // field => t/f case
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