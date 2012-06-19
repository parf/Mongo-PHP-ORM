<?
// autoload trick to load M2 instead of M (v1)

/*
 
 include M2.php to load M2 system in one include

 or

 if you have autoload
   1. place your files in appropriate places
   2. use M2::load();

*/


require __DIR__."/M.php";
require __DIR__."/Helper.php"; // M_Helper

class M2 extends M {
    static function load() {}
}

?>
