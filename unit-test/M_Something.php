<?
/*
  Sample class 
  Example how you can extend M_Object

  You can map collection to specific class 
  or you can setup a router and map collection to any class based on content

*/

// sample router class 
class M_Something extends M_Router {

    function name() {
        return substr(get_class($this), 2);
    }

    function doit() {
        return "some business function associated with collection";
    }
 
} // M_Something


class M_Car extends M_Something {

    const KM2MILE = 0.62137;

    function getMph() {
        return round($this->kmh * self::KM2MILE, 2);
    }

    function setMph($mph) { # false
        $this->kmh = round($mph / self::KM2MILE, 2);
        // return false  - means no futher operations will be performed
    }

} // M_Car

class M_Person extends M_Something {
 
    const SALT = "sugar";

    function name() {
        return parent::name()." ".$this->name;
    }

    function doit() {
        return "Jawohl";
    }

    function setPassword($v) {
        return md5($v.self::SALT); // never keep password as plain text
    }

} // M_Person

class M_Manager extends M_Person {

    function doit() {
        return "Do it yourself, i am the one who tells u what to do";
    }

} // M_Manager

//  alternative M_Router implementation
class M_RouterAlternative extends M_Object {
    static function i($C, $id, $autoload=true) {
        $o = parent::i($C, $id, $autoload);
        if (! $o->class)
            return $o;
        $C = ["M_".$o->class, 'i_o'];
        return $C($o);
    }
} // M_RouterAlternative
