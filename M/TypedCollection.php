<?

/*

Typed collection

Complex Type support for Mongo

*/

class M_Type extends M_TypeBase {
    // overload me -
    //     place M_Type class in your include path before me


    /*
      // used for queries and sets
      static function apply$Type($value) {  # $value

      }

      example:
      static function applyInt($v) {
      return (int) $v;
    }

    */

}

class M_TypeBase {

    /*

      3 method per type:

      apply$type($value) => $value

      // magic field read access
      get_$type($value) => $value

      // magic field write access
      set_$type($value) => $value

    */


    // field type support
    // $v - value, $T - type (not null)
    static final function apply($v, $T) { # value
        if (is_array($T)) { // $T = "ENUM"
            if (! is_scalar($v))
                return static::e("scalar expected", $v, "[ENUM]");
            if (isset($T[$v]))
                return $v;
            return static::e("bad enum key: $v", 0, "[ENUM]");
        }
        $m = ["M_Type", "apply".$T]; // ucfirst(strtolower($T));
        if ( is_callable($m) )
            return $m($v);
        throw new DomainException("apply: unknown type $T");
    }

    // M_Object magic field formatting
    static function getMagic($v, $T) { # $v
        if (is_array($T)) {
            if (! is_scalar($v))
                return static::e("scalar expected", $v, "getMagic [ENUM]");
            return isset($T[$v]) ? $T[$v] : null;
        }
        $m = ["M_Type", "get_".$T]; // ucfirst(strtolower($T));
        if ( is_callable($m) )
            return $m($v);
        throw new DomainException("get_Field: unknown magic type: $T");
    }

    // M_Object magic field formatting
    static function setMagic($v, $T) { # $v
        if (is_array($T)) {
            if (! is_scalar($v))
                return static::e("scalar expected", $v, "setMagic [ENUM]");
            if ( ($_ = array_search($v, $T)) !== false)
                return $_;
            return static::e("bad enum VALUE: $v");
        }
        $m = ["M_Type", "set_".$T]; // ucfirst(strtolower($T));
        if ( is_callable($m) )
            return $m($v);
        throw new DomainException("set_Field: unknown magic type: $T");
    }


    // error - when value is not compatible with type
    static function e($msg, $v=0, $T="") { # throws InvalidArgumentException
        if ($v)
            $msg.=" value type: ".gettype($T);
        if ($T)
            $msg.=" type: $T";

        // trigger_error("Invalid Argument: ".$msg);
        // throw new DomainException($msg);
        throw new InvalidArgumentException($msg);
    }

    // --------------------------------------------------------------------------------
    // data quering and validating
    // data sanitized, then validated

    // basic types
    static function applyInt($v)    { return (int)$v; }
    static function applyFloat($v)  { return (float)$v; }
    static function applyString($v) { return (string)$v; }
    static function applyBool($v)   { return (bool)$v; }

    // no type enforcement, you can query array with scalar
    static function applyArray($v)  { return $v ? $v : []; }


    // fancy types
    static function applyPrice($v) { return round($v, 2); }

    // Dates always ceiled to day
    // so you can index on date field and group by date
    static function applyDate($v) {
        if (is_string($v))
            $v = strtotime($v);
        if (! is_int($v))
            self::e("", $v, "date");
        $v = strtotime( date("Y-m-d", $v) );
        return $v;
    }

    static function applyDateTime($v) {
        if (is_int($v))
            return $v;
        if (is_string($v))
            return strtotime($v);
        self::e("", $v, "date");
    }

    // numeric and dot delimited formats only
    static function applyIp($v) { // ipv4 only
        if (is_numeric($v)) {
            if ($v>=4278190080 || $v<=16777216)  // 1/24 .. 255/24
                return false;
            return $v;
        }
        if (is_string($v))
            return ip2long(trim($v));  // false = bad ip
        if (! $v)
            return false;
        self::e("", $v, "ip");
    }

    static function applyEmail($v)  {
        if (! is_string($v))
            self::e("", $v, "email");
        $v=trim($v);
        preg_match("!<(.+)>!", $v, $a);
        if (isset($a[1]))
            $v=$a[1];
        $v = filter_var($v, FILTER_SANITIZE_EMAIL);
        $v = filter_var($v, FILTER_VALIDATE_EMAIL);
        return $v;
    }

    // add http:// if not present
    // no <>'" in URLs : css
    // does not check for domain existance
    static function applyURL($v) {
        if (! is_string($v))
            self::e("", $v, "url");
        $v = filter_var($v, FILTER_SANITIZE_URL);
        if (! preg_match('!^https?://!', $v))
            $v="http://".$v;
        $v = str_replace(array("<", ">",'"',"'"), "", $v); // avoid css
        $v = filter_var($v, FILTER_VALIDATE_URL);
        return $v;
    }

    // "First Last", word characters only, capitalisation
    static function applyName($v) {
        if (! is_string($v))
            self::e("", $v, "url");
        $v=preg_replace("![^\w ]!", "", trim($value));
        return ucwords(strtolower($v));
    }


    // numbers are stored as numeric "country-code,phone"
    // ten digit numbers are treated as US number (we add 1 in front)
    // ten digit numbers with +, 011, 00 are treated as intl numbers
    // 64bit mongo = must have
    static function applyPhone($v) {
        $v=trim($v, "() \n");
        if ($v[0]=="+")
            $v="0".substr($v, 1);
        if (substr($v, 0, 2)=='00')
            $v="0".substr($v, 2);
        if (substr($v, 0, 3)=='011')
            $v="0".substr($v, 3); // leading 0 == "+"
        $v=preg_replace("![^\d]!","", $v); // numbers only
        if (strlen($v) == 10 && $v[0]!='0')  // add 1 for 10 digit numbers
            $v = "1".$v;
        if ($v[0]=="0")
            $v=substr($v, 1); // final format
        return (int) $v;
    }

    // --------------------------------------------------------------------------------
    // getMagic
    //

    static function get_Array($v) {
        return json_encode((array)$v);
    }

    static function get_Int($v) {
        return number_format((int)$v);
    }

    static function get_Float($v) {
        return number_format((float)$v, 2);
    }

    static function get_Price($v) {
        return number_format((float)$v, 2);
    }

    static function get_Bool($v) { # Yes | No
        return $v ? "Yes" : "No";
    }

    static function get_Date($v) { # "May 25, 2012"
        return date("M d, Y", $v);
    }

    static function get_DateTime($v) { # "May 25, 2012"
        return date("M d, Y h:iA", $v);
    }

    static function get_IP($v) {
        return $v ? long2ip($v) : "";
    }

    static function get_Phone($ph) {
        $ph=preg_replace("![^\d]!","",$ph);
        $pl=strlen($ph);

        $p3=substr($ph,0,3);
        if( $p3=='011' || $p3=='001') $ph=substr($ph,3);
        if( substr($ph,0,2)=='00') $ph=substr($ph,2);
        $pl=strlen($ph);

        if($pl<5) return $ph;
        if($pl<7) return substr($ph,0,-4)."-".substr($ph,-4);
        switch($pl) {
        case 7: return substr($ph,0,3)."-".substr($ph,3);
        case 8:
        case 9: return substr($ph,0,-4)."-".substr($ph,-4);
        case 10: return substr($ph,0,3)."-".phone_format(substr($ph,3));
        }
        return self::_international_phone_format($ph);
    }


    // US Numbers : 1-XXX-XXXX
    // NON US Numbers: +Country_Code XXX-XXX-rest
    private static function _international_phone_format($ph) {
        $short_prefix=array(
                            20=>1,27=>1,
                            30=>1,31=>1,32=>1,33=>1,34=>1,36=>1,39=>1,
                            40=>1,41=>1,43=>1,44=>1,45=>1,46=>1,47=>1,48=>1,49=>1,
                            51=>1,52=>1,53=>1,54=>1,55=>1,56=>1,57=>1,58=>1,
                            60=>1,61=>1,62=>1,63=>1,64=>1,65=>1,66=>1,
                            76=>1,77=>1,
                            81=>1,82=>1,84=>1,86=>1,
                            90=>1,91=>1,92=>1,93=>1,94=>1,95=>1,98=>1,
                            );

        $long_prefix=array(
                           1242=>1,1246=>1,1264=>1,1268=>1,1284=>1,
                           1340=>1,1345=>1,1441=>1,1473=>1,
                           1649=>1,1664=>1,1670=>1,1671=>1,1684=>1,
                           1721=>1,1758=>1,1767=>1,1784=>1,1787=>1,
                           1808=>1,1809=>1,1829=>1,1849=>1,1868=>1,1869=>1,1876=>1,
                           1939=>1,
                           5399=>1,
                           7840=>1,7940=>1,
                           8810=>1,8811=>1,8812=>1,8813=>1,8816=>1,8817=>1,8818=>1,8819=>1);

        if( isset($short_prefix[$ph[0].$ph[1]]) )
            $p=2;
        else
            $p=3; // no 4d code support
        if($p==3 && $ph[0]=='7')
        $p=1;
        if($p==3 && $ph[0]=='1')
            $p=1;

        if( isset($long_prefix[substr($ph,0,4)] ) )
            $p=4;

        $pref=substr($ph,0,$p);
        $post=substr($ph,-4);
        $ph=substr($ph,$p,-4);
        $ph=substr($ph,0,-3)."-".substr($ph,-3);
        if ($ph[0]=='-') $ph=substr($ph,1);
        if ($pref=='1')
            return "$pref-$ph-$post";
        /*
        if ($pref=='86') { // China
            $p = str_replace("-", "", $ph.$post);
            $p2 = substr($p, 0, 2);
            $p3 = join("-", str_split(substr($p, 2), 4));
            return "+$pref $p2 $p3";
        }
        */
        return "+$pref $ph-$post";
    }

    // --------------------------------------------------------------------------------
    // getMagic
    // we highly discouraging you to repeat apply$Type
    // provide set_ methods only when they are different from apply$Type

    static function set_IP($v) {
        return ip2long($v);
    }

    static function set_Date($v) {
        return strtotime($v);
    }


} // M_TypeBase

class M_TypedCollection extends M_Collection {

    public $type; // field => type

    public $T ;

    function __construct($server, $sdc, $field) {
        $this->type = $field;
        $this->type['_id'] = 'int';  // general framework assumption
        $this->T = new M_Type();
        parent::__construct($server, $sdc);
    }

    // insert, $set
    // arrays are enforced
    function applyTypes(array $kv) {  # $kv
        $t = $this->type;
        foreach($kv as $k => &$v) {
            $T = isset($t[$k]) ? $t[$k] : 0;
            if (! $T)
                continue;
            $v=M_Type::apply($v, $T);
            if ($T=='array' && ! is_array($v))
                trigger_error("array expected. key: '$k'", E_USER_ERROR);
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

            $v=$this->applyType($v, $T);
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
    function dot_insert(array $data, array $options=[]) { # ID
        parent::dot_insert( $this->applyTypes($data) );
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
    function formatMagicField($field, $value, $set = false) {
        $T = @$this->type[$field];
        if (! $T)
            throw new Exception("Type required for magic field $field");
        if ($set)
            return M_Type::setMagic($value, $T);
        return M_Type::getMagic($value, $T);
    }

    // M::Alias(id)->_field = "XXX";
    function setMagicField($field, $value) {
        return $this->formatMagicField($field, $value, 1);
    }

}

?>