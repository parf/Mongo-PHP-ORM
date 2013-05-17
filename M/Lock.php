<?php
class M_Lock {
    const TIMEOUT = 3600; // 1 hour locks
    private static $locks = [];
    private static $stack = [];
    
    public static function lock($key, $timeout=5, $throw_exception=true, $lock_time_mul=2) {
        // check for double locking
        if (isset(self::$locks[$key]))
            Log::alert("Cache::lock: Double Lock($key)");
        
        // loop until aquire lock
        $start = time();
        $i = 0;
        $timeouts = [10, 101, 1002, 5003, 10004, 25005, 50006, 75007, 100008];
        while (self::acquire($key) == false) {
            // timeout?
            if (time() - $start > $timeout) {
                if ($throw_exception)
                    throw new Exception("LOCK_FAIL");
                return false;
            }
            
            // sleep some
            if (isset($timeouts[$i]))
                sleep($i++);
            else
                sleep(1);
        }
        
        // aquired lock
        self::$stack[] = $key;
        self::$locks[$key] = true;
        
        return true;
    }
    
    public static function unlock($key='', $lock=false) {
        if (!$key)
            $key = array_pop(self::$stack);
        
        $criteria = ['key'=>$key];
        $update   = ['$set'=>['lock'=>$lock,'time'=>time()]];
        $options  = ['upsert'=>true];
        
        if (self::mc()->update($criteria, $update, $options))
            unset(self::$locks[$key]);
        return true;
    }
    
    /**
     * return MongoCollection
     */
    private static function mc() {
        return M()->sequence->lock;
    }
    
    private static function acquire($key) {
        // check if lock lock
        $criteria = ['key'=>$key];
        $lock = self::mc()->findOne($criteria);
        
        if (empty($lock) || ($lock['lock'] == true && $lock['time']+self::TIMEOUT < time()))
            return self::unlock($key, true);
            
        // aquire lock
        $criteria = ['key'=>$key,'lock'=>false];
        $options = ['new'=>true];
        
        return self::mc()->findAndModify(
                $criteria, // query
                ['$set'=>['lock'=>true,'time'=>time()]+$criteria], // update
                ['lock'=>1],
                $options
        )['lock'];
    }
}