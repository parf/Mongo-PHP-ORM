<?
include __DIR__."/config/Config.php.inc";
hb\config\Config::init(__DIR__);

class Profiler {
//  static function in($f, $p) { echo $f."(".json_encode($p).")\n"; }
  PUBLIC static function __callStatic($name, $arg) {} 
}

// MONGODB Wrapper
// ATTENTION - we use ":" as mongo.cmd
// See M::mongo for parameters details.
// ex:
//   $my_mongo = M();
//   $my_mongo = M('gfs');
//
//   sdc - server, database, collection
//   format:
//     server
//     db.collection
//     server:db.collection
//  In case when db.collection is present M_Collection is returned !!
//  Check M_Collection
function M($sdc='', $id=false) { // Mongo | M_Collection | M_Object
    if ($id!==false)
        return M($sdc)->go($id);
    return M::i($sdc);
}

// one key version of hash_cut
// unset key in hash, return unsetted key
function hash_unset(&$hash, $key) { # NULL | value
    if (! array_key_exists($key,$hash)) return;
    $vl=$hash[$key];
    unset($hash[$key]);
    return $vl;
}

include __DIR__."/../M/M.php";
?>
