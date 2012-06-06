<?
include __DIR__."/config/Config.php.inc";
hb\config\Config::init(__DIR__);

class Profiler {
//  static function in($f, $p) { echo $f."(".json_encode($p).")\n"; }
  PUBLIC static function __callStatic($name, $arg) {} 
}

// unset key in hash, return unsetted key
function hash_unset(&$hash, $key) { # NULL | value
    if (! array_key_exists($key,$hash)) return;
    $vl=$hash[$key];
    unset($hash[$key]);
    return $vl;
}

// Mongo ORM M2
// M() - Mongo default server 
// M("server:") - Mongo
// M("alias") | M("server:db.collection") | M("db.collection") - M_Collection
// M("alias", $id) | M("[server:]db.collection", $id) - M_Object
function M($sdc='', $id=false) { // Mongo | M_Collection | M_Object
    if ($id!==false)
        return M($sdc)->go($id);
    return M::i($sdc);
}

include __DIR__."/../M/M.php";
# we are using stock types

include __DIR__."/../M/Type.php";

include __DIR__."/M_Something.php"; // sample M_Object extension
?>
