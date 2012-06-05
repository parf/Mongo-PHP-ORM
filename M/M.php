<?php
/**
 *
 #  HB FRAMEWORK: mongo-php-orm M2

 *      SYNOPSIS: MongoDB extensions

Implements:

*
* mongo severs switching (see M("server:"), M("server:db.collection")
* wrapper for M_Collection, M_Object
* Aliases support
*

M($x) is an alias to M::i(x)

M()                  => Mongo (default server)
M("server:")         => Mongo

M("alias")           => M_Collection   // reccomended
M("db.name")         => M_Collection
M("server:db.name")  => M_Collection
M::$alias()          => M_Collection   // reccomended

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

if (! class_exists("M_Type", false))
    include __DIR__."/Type.php"; // M_Type

include __DIR__."/TypedCollection.php"; // M_Collection
include __DIR__."/Object.php"; // M_Object
include __DIR__."/Sequence.php"; // M_Sequence
include __DIR__."/Helper.php"; // M_Helper

class M {

    static $CACHE=[]; // (sdc|alias => M_Collection) or (server => Mongo)

    // NEVER CALL DIRECTLY: Use wrapper M()
    // mongo instantiator
    //
    // sdc is Server:Db:Collection
    // sdc: "" | "server" | "server:db.collection" | "db.collection"
    // default server host is "localhost"
    static function i($sdc='') { # Mongo | M_Collection
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
                $t = M::_config("", "alias.$sdc");
                if (! $t)
                    trigger_error("alias $sdc not defined");
                return M::$CACHE[$t]=self::i($t);
            }

            // sdc is "server:"
            $server = substr($sdc, 0, -1);
        } else {
            $server = "";
        }

        $connect = M::_config($server, "connect");
        if (! $connect)
            trigger_error("config 'mongo.connect' required");

        // connect string format:
        //  http://www.mongodb.org/display/DOCS/Connections
        //  http://php.net/manual/en/mongo.construct.php

        // add PHP support options via "?" (as in mongo), if ( php_supports it ) use; else: emulate

        $params=[];
        //if (strpos($connect,","))
        //        $params=["replicaSet" => $alias];

        Profiler::in("mongo::connect", $connect);
        $mongo=new Mongo("mongodb://$connect", $params);
        Profiler::out();

        return M::$CACHE[$sdc] = $mongo;
    } // function i(...)

    // legacy !!
    static function mongo($sdc) {
        return M::i($sdc);
    }

    static function _config($server, $node) {
        if (! $server)
            return CC("m2.".$node);
        return CC("m2-$server.$node");
    }

    // runtime-only config change
    static function _configSet($server, $node, $value) {
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


}
