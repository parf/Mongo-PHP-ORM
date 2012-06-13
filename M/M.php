<?php
/**
 *
 #  HB FRAMEWORK: mongo-php-orm M2

 *      SYNOPSIS: MongoDB extensions

Implements:
* core M2 classes load
* classes Mongo, M_Collection instantiation and caching
* M_Object instantiation
* Aliases for collections

function M($sdc, $id) is an alias to M::i($sdc, $id)
sdc stands for server-db-collection
id is collection primary key (_id)

M()                  => Mongo (default server)
M("server:")         => Mongo

M("alias")           => M_Collection   // recommended
M("db.name")         => M_Collection
M("server:db.name")  => M_Collection
M::$alias()          => M_Collection   // recommended

M("server:db.name", $id)    => M_Object
M("db.name", $id)    => M_Object
M("alias", $id)      => M_Object
M::$alias($id)       => M_Object

Aliases (case sensitive)
mongo:
  connect: connect_string      see  http://www.mongodb.org/display/DOCS/Connections
  alias:
    Alias: db.collection
    Alias: server:db.collection
    Account: account.account
    User:    user.user

mongo-$server:


 *
 **/

include __DIR__."/Collection.php"; // M_Collection
include __DIR__."/TypeBase.php";    // M_TypeBase
include __DIR__."/TypedCollection.php"; // M_Collection
include __DIR__."/Object.php"; // M_Object
include __DIR__."/Sequence.php"; // M_Sequence
include __DIR__."/Helper.php"; // M_Helper

class M {

    // collections and connections cache
    protected static $CACHE=[]; // (sdc|alias => M_Collection) or (server => Mongo)

    // NEVER CALL DIRECTLY: Use wrapper M()
    // mongo instantiator
    //
    // sdc is Server:Db:Collection
    // sdc: "" | "server" | "server:db.collection" | "db.collection"
    // default server host is "localhost"
    static function i($sdc='', $id=false) { # Mongo | M_Collection
        if ($id!==false)
            return self::i($sdc)->go($id);

        if (! $sdc)
            $sdc="";
        if (isset(M::$CACHE[$sdc]))
            return M::$CACHE[$sdc];

        if ($sdc) {
            // M("server:db.col"), M("db.col")
            if (strpos($sdc, ".") )
                return M::$CACHE[$sdc]=M_Collection::i($sdc);

            // M("Alias")
            if (substr($sdc, -1)!=":") {
                $t = M::C("", "alias.$sdc");
                if (! $t) {
                    trigger_error("alias $sdc not defined");
                    die;
                }
                return M::$CACHE[$t]=self::i($t);
            }

            // sdc is "server:"
            $server = substr($sdc, 0, -1);
        } else {
            $server = "";
        }

        $connect = M::C($server, "connect");
        if (! $connect) {
            trigger_error("config 'mongo.connect' required");
            die;
        }

        // connect string format:
        //  http://www.mongodb.org/display/DOCS/Connections
        //  http://php.net/manual/en/mongo.construct.php

        // add PHP support options via "?" (as in mongo), if ( php_supports it ) use; else: emulate

        $params=[];
        //if (strpos($connect,","))
        //        $params=["replicaSet" => $alias];

        Profiler::in("M::connect", $connect);
        $mongo=new Mongo("mongodb://$connect", $params);
        Profiler::out();

        return M::$CACHE[$sdc] = $mongo;
    } // function i(...)

    // legacy !!
    static function mongo($sdc) {
        return M::i($sdc);
    }

    // Config - C("xxx") wrapper
    static function C($server, $node) {
        if (! $server)
            return CC("m2.".$node);
        return CC("m2-$server.$node");
    }

    // runtime-only config change
    static function C_set($server, $node, $value) {
        if (! $server)
            return C_set("m2.".$node, $value);
        C_set("m2-$server.".$node, $value);
    }

    // Reccomended WAY for accessing Mongo Collections
    //
    // M::Alias($id) == M_Alias
    // M::Alias()    == M("db.collection")
    //
    // define aliases in config.yaml:
    //
    // mongo:
    //      alias:
    //         $alias: "db.collection"
    //         $alias: "server:db.collection"
    //
    // you can define aliases only for main mongo server
    //
    static function __callStatic($alias, $args) { # M_Object | M_Collection
        $mc=M::i($alias);
        return $args ? $mc->go($args[0]) : $mc;
    }

    // as is
    static function listDatabases($server='', $raw=false) { # [db, db, ... ]
        $dbs=M::i($server ? "$server:" : "")->admin->command(["listDatabases" => 1]);
        if ($raw)
            return $dbs;
        $r=[];
        foreach($dbs["databases"] as $d)
            $r[]=$d["name"];
        return $r;
    }

    // serverDB is "db" or "server:db"
    static function listCollections($sdb) { # [ "db.col", "db.col", ... ]
        $server = "";
        $db = $sdb;
        if ($p=strpos($sdb, ":")) {
            $server = substr($sdb, 0, $p+1);
            $db     = substr($sdb, $p+1);
        }
        $a=[];
        foreach(M::i($server)->__get($db)->listCollections() as $c)
            $a[]=$db.".".$c->getName();
        return $a;
    }

    // legacy
    static function index($dbc, $fields, $echo=1) {
        return M_Helper::index($dbc, $fields, $echo);
    }

    // report fatal error -
    //    ex: missing required configuration item
    //        incorrect parameters to the function (programmer fault)
    // terminate application
    private static function fatal($msg, $level=E_USER_ERROR) {
        list($c, $b) = debug_backtrace(false); // $c - caller; $b - c-of-c
        // $d = first_non_framework LoC
        trigger_error("$msg\n".
                      "    $b[class]$b[type]$b[function](".substr(substr(json_encode($b["args"]),1,-1),0, 240).")\n".
                      "    $c[file]:$c[line]\n".
                      "    $b[file]:$b[line]\n",
                      $level);
        die;
    }

    // Some functions

    // unset key in hash, return unsetted key
    static function hash_unset(&$hash, $key) { # NULL | value
        if (! array_key_exists($key,$hash)) return;
        $vl=$hash[$key];
        unset($hash[$key]);
        return $vl;
    }

    /*
      Perls qw ( Quote Words ) with extended
      Any/String to Array convertor ( non string returned back w/o processing )
      d_e - entry delimiter
      d_v - name/value delimiter

      example: qw("a b c>Data") == array( "a", "b" , "c" => "Data")
    */
    static function qw($data, $d_e=" ", $d_v=">") {
        if (! is_string($data))
            return $data;
        if (! $data )
            return array();
        $res= $d_e == ' ' ? preg_split('/\s+/',trim($data)) : explode($d_e,$data);
        if(! strpos($data,$d_v)) return $res;
        $ret=array();
        foreach($res as $r) {
            if($p=strpos($r,$d_v))
                $ret[substr($r,0,$p)]=substr($r,$p+1);
            else
                $ret[]=$r;
        }
        return $ret;
    }

    /*
      qw like function, Quote Keys
      example: qw("a b c>Data") == array( "a" =>true, "b"=>true , "c" => "Data")
    */
    static function qk($data, $d_e=" ", $d_v=">") {
        if (! is_string($data))
            return $data;
        if (! $data )
            return array();
        $res = $d_e == ' ' ? preg_split('/\s+/', trim($data)) : explode($d_e,$data);
        $ret = array();
        foreach($res as $r) {
            if($p=strpos($r,$d_v))
                $ret[substr($r,0,$p)]=substr($r,$p+1);
            else
                $ret[$r]=true;
        }
        return $ret;
    }

    // quote HTML
    static function qh($text) {
        return htmlspecialchars($text, ENT_QUOTES);
    }


} // class M
