<?

/*

  Bundled Types
  implement M_Type by extending this class ( see M/Type.php) to add your classes

 */

class M_TypeBase {

    /*

      3 method per type:

      Most important: used for inserts, updates, queries
  
        apply$type($value) => $value

  
      M_Object's Magic Fields getter and setter        

        // magic field read access
        get_$type($value) => $value

        // magic field write access
        set_$type($value) => $value

    */


    // field type support
    // $v - value, $T - type (not null)
    PUBLIC static function apply($v, $T) { // $v
        if (is_array($T)) { // complex type
            $t = array_shift($T);
            if (! $t)
                throw new DomainException("apply: NULL type");
            $m = ["M_Type", "apply".$t];
            if ( is_callable($m) )
                return $m($v, $T);
            throw new DomainException("apply: unknown type $t");
        }
        $m = ["M_Type", "apply".$T]; // ucfirst(strtolower($T));
        if ( is_callable($m) )
            return $m($v);
        throw new DomainException("apply: unknown type $T");
    }

    // M_Object magic field formatting
    PUBLIC static function getMagic($v, $T, $exception=true) { // $v
        if (is_array($T)) { // complex type
            $t = array_shift($T);
            $m = ["M_Type", "get_".$t];
            if ( is_callable($m) )
                return $m($v, $T);
            throw new DomainException("get_Magic: unknown type $t");
        }
        $m = ["M_Type", "get_".$T]; // ucfirst(strtolower($T));
        if ( is_callable($m) )
            return $m($v);
        if (! $exception)
            return $v;
        throw new DomainException("get_Field: unknown magic type: $T");
    }

    // M_Object magic field set
    PUBLIC static function setMagic($v, $T) { # $v
        if (is_array($T)) {
            $t = array_shift($T);
            $m = ["M_Type", "set_".$t];
            if ( is_callable($m) )
                return $m($v, $T);
            throw new DomainException("set_Magic: unknown type $t");            
        }
        $m = ["M_Type", "set_".$T]; // ucfirst(strtolower($T));
        if ( is_callable($m) )
            return $m($v);
        $m = ["M_Type", "apply".$T]; // ucfirst(strtolower($T));
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
    static function applyBitmask($v)    { return (int)$v; }


    // T = ["ENUM", {}]
    static function applyEnum($v, $p) {
        if (! is_scalar($v)) {
            if (null === $v) {
                static::e("bad enum key: $v", 0, "[ENUM]");
            }
            return static::e("scalar expected", $v, "[ENUM]");
        }
        $p = $p[0];
        if ( ($_ = array_search($v, $p)) !== false)
            return $_;
        if (isset($p[$v]))
            return $v;
        return static::e("bad enum key: $v", 0, "[ENUM]");
    }

    // T = ["ARRAY", "TYPE"]
    // no type enforcement, you can query array with scalar
    // it is OK to query array with scalar !!!
    static function applyArray($v, $t="")  {
        if (! $t)
            return $v ? $v : [];
        if (! is_array($v))
            return self::apply($v, $t);
        // array of $t case
        foreach ($v as &$_)
            $_ = self::apply($_, $t);
        return $v;
    }
    
    // T = ["HASH", "TYPES"]
    // no type enforcement, you can query array with scalar
    // it is OK to query array with scalar !!!
    static function applyHash($v, $t="")  {
        if (! $t)
            return $v ? $v : [];
        if (! is_array($v))
            return self::apply($v, $t);
        // array of $t case
        foreach ($v as &$_)
            $_ = self::apply($_, $t);
        return $v;
    }
    
    
    // Anti CSS (cross-site scripting) type - text only
    // all html entities are escaped
    /*
      note:
          keeping escaped text in database is not a best practice,
          but this may be useful in cases when field should never have html inside

          with some hassle you can achieve similar behaviour with regular strings
          via magic get: check get_String 
    */
    static function applyText($v) {
        return M::qh((string) $v);
    }

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
    // internally stored without 'http://' prefix
    // use magic access "_$url" to auto-add http:// (when needed)
    static function applyURL($v0) {
        if (! is_string($v0)) {
            self::e("", $v0, "url");
        }
        // $v = filter_var($v0, FILTER_SANITIZE_URL);
        // ^^^ sanitize is stupid
        $v = trim($v0);
        if (! $v)
            return null;
        if (! preg_match('!^https?://!', $v))
            $v="http://".$v;

        $v = str_replace(["<", ">",'"',"'"], "", $v); // avoid css
        $v = filter_var($v, FILTER_VALIDATE_URL);
        
        // we do not store 'http://' prefix
        if (substr($v, 0, 7)=='http://')
            $v = substr($v, 7);
        if (! $v)
            self::e("bad url: $v0", $v0, "url");        
        
        return $v;
    }

    // "First Last", word characters only, capitalisation
    static function applyName($v) {
        if (! is_string($v))
            self::e("", $v, "url");
        $v = preg_replace("![^\w ]!", "", trim($v));
        $v = preg_replace("!\d!", "", $v);
        $v = preg_replace("!\s+!", " ", $v);
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
    // getMagic - human readable representation of a type
    //

    // T = ["ENUM", $p]
    static function get_ENUM($v, $p) {
        if (! is_scalar($v)) {
            if (null === $v) {
                return null;
            }
            return static::e("scalar expected", $v, "get_Magic [ENUM]");
        }
        $T = $p[0];
        return isset($T[$v]) ? $T[$v] : null;
    }

    // ARRAY OF type $T[0]
    // or just an array
    static function get_Array($v, $T=false) { //
        if ($T === false) {
            // static::e("no type defined, no magic for untyped arrays");
            return $v;
        }
        if (! $v)
            return [];
        // array of $t case
        $r = [];
        foreach ($v as $_)
            $r[] = self::getMagic($_, $T[0]);
        return $r;
    }
    
    // ARRAY OF type $T[0]
    // or just an array
    static function get_Hash($v, $T=false) { //
        if ($T === false) {
            // static::e("no type defined, no magic for untyped arrays");
            return $v;
        }
        if (! $v)
            return [];
        // array of $t case
        $r = [];
        foreach ($v as $_)
            $r[] = self::getMagic($_, $T[0]);
        return $r;
    }

    static function get_Bitmask($v) { // 99,999,999
        return (int)$v;
    }
    
    static function get_Int($v) { // 99,999,999
        return number_format((int)$v);
    }

    static function get_Float($v) { // 999,999.00
        return number_format((float)$v, 2);
    }

    // Anti CSS (cross-site scripting) string representation
    // all html entities are escaped
    static function get_String($v) { // &ltscript
        return M::qh($v);
    }

    // all html entities are escaped at the applyText
    static function get_Text($v) { // &ltscript
        return $v;
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
        if ( $p3=='011' || $p3=='001') $ph=substr($ph,3);
        if ( substr($ph,0,2)=='00') $ph=substr($ph,2);
        $pl=strlen($ph);

        if ($pl<5) return $ph;
        if ($pl<7) return substr($ph,0,-4)."-".substr($ph,-4);
        switch($pl) {
        case 7: return substr($ph,0,3)."-".substr($ph,3);
        case 8:
        case 9: return substr($ph,0,-4)."-".substr($ph,-4);
            // case 10: return substr($ph,0,3)."-".self::phone_format(substr($ph,3));
        }
        return self::_international_phone_format($ph);
    }
    
    // auto-add http:// prefix
    // https urls are always stored with prefix
    static function get_URL($v) {
        if (! $v)
            return "";
        if (substr($v, 0, 4)!=='http')
            return "http://$v";
        return $v;
    }


    // US Numbers : 1-XXX-XXXX
    // NON US Numbers: +Country_Code XXX-XXX-rest
    private static function _international_phone_format($ph) {
        $short_prefix=[
                            20=>1,27=>1,
                            30=>1,31=>1,32=>1,33=>1,34=>1,36=>1,39=>1,
                            40=>1,41=>1,43=>1,44=>1,45=>1,46=>1,47=>1,48=>1,49=>1,
                            51=>1,52=>1,53=>1,54=>1,55=>1,56=>1,57=>1,58=>1,
                            60=>1,61=>1,62=>1,63=>1,64=>1,65=>1,66=>1,
                            76=>1,77=>1,
                            81=>1,82=>1,84=>1,86=>1,
                            90=>1,91=>1,92=>1,93=>1,94=>1,95=>1,98=>1,
                            ];

        $long_prefix=[
                           1242=>1,1246=>1,1264=>1,1268=>1,1284=>1,
                           1340=>1,1345=>1,1441=>1,1473=>1,
                           1649=>1,1664=>1,1670=>1,1671=>1,1684=>1,
                           1721=>1,1758=>1,1767=>1,1784=>1,1787=>1,
                           1808=>1,1809=>1,1829=>1,1849=>1,1868=>1,1869=>1,1876=>1,
                           1939=>1,
                           5399=>1,
                           7840=>1,7940=>1,
                           8810=>1,8811=>1,8812=>1,8813=>1,8816=>1,8817=>1,8818=>1,8819=>1];

        if (isset($short_prefix[$ph[0].$ph[1]]))
            $p=2;
        else
            $p=3; // no 4d code support
        if ($p==3 && $ph[0]=='7')
        $p=1;
        if ($p==3 && $ph[0]=='1')
            $p=1;

        if ( isset($long_prefix[substr($ph,0,4)] ) )
            $p=4;

        $pref=substr($ph,0,$p);
        $post=substr($ph,-4);
        $ph=substr($ph,$p,-4);
        $ph=substr($ph,0,-3)."-".substr($ph,-3);
        if ($ph[0]=='-') $ph=substr($ph,1);
        if ($pref=='1')
            return "(".substr($ph,0,3).")".substr($ph,4)."-".$post;
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

    // complex type: ["ENUM", {db => expanded}]
    static function set_ENUM($v, $T) {
        $t = $T[0];
        if (! is_scalar($v)) {
            if (null === $v) {
                static::e("bad enum VALUE: $v");
            }
            return static::e("scalar expected", $v, "set_ENUM");
        }
        if ( ($_ = array_search($v, $t)) !== false)
                return $_;
        return static::e("bad enum VALUE: $v");
    }

    static function set_IP($v) {
        return ip2long($v);
    }

    // all non-numeric chars are removed
    static function set_Int($v) {
        return (int) preg_replace("![^\d]!", "", $v);
    }
    
    static function set_Bitmask($v) {
        return (int) preg_replace("![^\d]!", "", $v);
    }

    // all non-numeric chars are removed
    static function set_Float($v) {
        return (float) preg_replace("![^\d\.]!", "", $v);
    }

    static function set_Date($v) {
        return strtotime($v);
    }

    // no exception for bad urls:
    // saves null instead of bad urls
    static function set_URL($v) {
        try {
            $v = static::applyURL($v);
        } catch(InvalidArgumentException $ex) {
            $v = "";
        }
        return $v;
    }
    
    // integer representation of date support
    // so you can index on day field and group by day
    static function applyDay($v) { # int
        if (is_string($v))
            return HB::time2day(strtotime($v));

        if (is_int($v))
            return $v;

        self::e("", $v, "date");
    }

    static function get_Day($v) { # "May 25, 2012"
        return date("M d, Y", HB::day2time($v));
    }
    
    
    // mixed value type. Actualy being used as bypass for magic and strict logic.
    // Means that any type of data could be used.
    static function applyMixed($v) {
        return $v;
    }

    static function get_Mixed($v) {
        return $v;
    }

    
    // integer representation of month and year without date.
    // so you can index on ym field and group by month
    // $v supposed to be any int like 1209, string '1209' or array [12, 9]
    static function applyYm($v) { #int
        return HB::yms($v);
    }

    static function get_Ym($v) { # [y,m]
        return HB::ym($v);
    }
    
} // M_TypeBase
