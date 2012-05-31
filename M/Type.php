<?

/*

 Type Support for Mongo
 Class for overload

 Use self::e( $message, $value, $type) to report errors (it will throw InvalidArgumentException)

 Check M_TypeBase (located in Collection.php) for more examples and details

*/

class M_Type extends M_TypeBase {

    // overload me -
    // place M_Type class in your include path before me


    //  Apply Type - MOST IMPORTANT part!
    //  Used in non-ORM and ORM (M_Collection and M_Object)
    //  Used for queries and updates/inserts

    /*

      static function apply$Type($value) {  # $value
          return do_something($value);
      }

      static function applyInt($v) { return (int) $v; }

      static function applyDateTime($v) {
        if (is_int($v))
            return $v;
        if (is_string($v))
            return strtotime($v);
        self::e("", $v, "date");
    }

    */


    // Magic Fields
    // Available only for ORM model (M_Object)
    // Access via "_field"

    // M::Alias($id)->_$field
    /*
        static function get_Array($v) {
        return json_encode((array)$v);
    }
    */
 
}
