<?
include __DIR__."/config/Config.php.inc";
hb\config\Config::init(__DIR__);

class Profiler {
    //  static function in($f, $p) { echo $f."(".json_encode($p).")\n"; }
    PUBLIC static function __callStatic($name, $arg) {} 
}

// Mongo ORM M2
// M() - Mongo default server 
// M("server:") - Mongo
// M("alias") | M("server:db.collection") | M("db.collection") - M_Collection
// M("alias", $id) | M("[server:]db.collection", $id) - M_Object
function M($sdc='', $id=false) { # Mongo | M_Collection | M_Object
    return M::i($sdc, $id);
}


// You do not need this if you have autoload
// Place classes in appropriate directories

include __DIR__."/../M/M.php";

# we are using stock types
class M_Type extends M_TypeBase {}

include __DIR__."/M_Something.php"; // sample M_Object extension
