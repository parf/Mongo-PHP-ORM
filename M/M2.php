<?
// autoload trick to load M2 instead of M (v1)

include __DIR__."/M.php";

class M2 extends M {
    static function load() {}
}

?>
