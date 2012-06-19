<?
/**
 * MongoDB-based sequence generator
 * Similar to lib.framework/Sequence/Sequence.php
 *
 * Sequence name must be in db.mycol format
 * Sequence is located in the same database as original db

 * @author parf <parf@comfi.com>

 */

class M_Sequence {

    /**
     * Get next increment
     * @param string $name        db.mycol
     * @param int $inc
     * @param bool $autocreate
     */
    static function next($name, $inc=1, $autocreate=false) { # id || ids
        $db = self::MC($name)->db; // just get db
        $r  = $db->command(['findAndModify' => 'sequence',
                            'query'  => ['_id' => (string) $name],
                            'update' => ['$inc' => ['val' => $inc]],
                            'new'    => true
                            ]);
        if ($r["ok"]==1 && $r["value"]!==NULL) {
            $v = $r["value"]["val"];
            if ($inc==1)
                return $v;
            return range($v-$inc+1,$v);
        }
        if ( ($r["value"]===NULL || $r["errmsg"]=='No matching object found') && $autocreate) {
            self::create($name);
            return self::next($name, $inc, false);
        }
        trigger_error("SEQUENCE: Unexpected result from sequence '$name': ".json_encode($r), E_USER_ERROR);
        die;
    }

    // MC(name) - sequence MongoCollection
    // sequence is always located in the same db
    static function MC($name) { # MongoCollection
        list($db, $col)=explode(".", $name, 2);
        return M()->$db->sequence;
    }

    /**
     * Manually set sequence
     * @param string $name
     * @param int $val
     */
    static function set($name, $val) {
        $last = self::last($name);
        if ($last === null)
            self::create($name, 0);

        if ($last >= $val) {
            throw new RuntimeException("SEQUENCE: $name must be larger than $last");
            die;
        }

        self::reset($name, $val);
        return (int)$val;
    }

    /**
     * Get last increment
     * @param string $name        db.mycol
     */
    static function last($name) { # value
        $r = self::MC($name)->findOne(["_id" => (string)$name]);
        return $r["val"];
    }

    /**
     * Reset sequence to $val
     * @param string $name
     * @param int $val
     */
    static function reset($name, $val=false) { # null
        if (!self::last($name)) {
            trigger_error("SEQUENCE: no such sequence: $name", E_USER_ERROR);
            die;
        }

        if ($val===false)
            $val=self::lastDb($name);

        self::MC($name)->update(
                               ["_id" => (string) $name],
                               ['$set' => ["val" => (int)$val]],
                               ["safe" => true, "fsync" => true]
                          );
    }

    /**
     * Start sequence from $val
     *                   - default last "_id" from database or 1
     * @param string $name
     * @param int $val
     */
    static function create($name, $val=false) { # void
        self::enforce_namespace($name);
        if ($val === false)
            $val=self::lastDb($name)+1;
        self::MC($name)->insert(["_id" => $name, "val" => (int)$val-1],
                               ["safe" => true, "fsync" => true]);
        #return M()->getLastError();
    }

    // last id from $name db.collection
    static function lastDb($name) { # last id
        $a=iterator_to_array( M($name)->find( [], ["_id"=>1] )->sort(["_id" => -1])->limit(1) );
        if (! $a)
            return 0;
        return key($a);
    }

    /**
     * Enforce db.mycol format to prevent collisions
     * @param string $name
     */
    private static function enforce_namespace($name) { # bool
        list($db,$mycol) = explode('.', $name, 2);
        if (!$mycol) {
            trigger_error('SEQUENCE: sequence name must be in "db.col" format', E_USER_ERROR);
            die;
        }
    }

}
