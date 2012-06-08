<?php

/*

  SIMPLIFIED/stipped homebase CONFIG SYSTEM

  do not trust docs!!!
  format changed from yaml to yaml/json hybrid, check i/Config for details  

  main idea - 
   json/yaml-alike structure loaded, then flattened, then cached in shared memory
   every node can be accessed via *ONE* as in big-O(1) hash lookup
   echo "value = ".$data["node.node.node.node"]

  todo:
  * change caching to filesystem(/tmp) for cli mode
  * get rid of curly brackets in config, make it more yaml alike

*/


  // THIS FILE INCLUDED VIA DIRECT INCLUDE FROM init.php

  /**
   *
   *  SYNOPSIS:
   *
   *     Working with YAML configs
   *     Loading, Caching, Lazy-Loading
   *
   *     Config:
   *         $site/config.yaml + $project/config/config.yaml
   *     1st level nodes in site config takes precedence over project config
   *
   *     Custom Config ($node)
   *         $site/$node.yaml + $project/$node.yaml
   *     1st level nodes in site config takes precedence over project config
   *
   *     Accessing Config:
   *         upon loading config is additionally flattened to "node.node.key" structure
   *         so you can access nodes using fast dot notation
   *
   *         C("node")           - strict access node, node must exists, exception fired if there are no node
   *         CC("node")          - safe access node, node may be missing, returns false if node is missing
   *
   *         C("key")            - access global config node
   *         C("node.key")       - access global config node
   *         C("node")["key"]    - same as above
   *
   *
   *         C("key", "config")  - lazy load and access config node
   *
   *         C_set("node", "value", ["config"]) - sets value of config node
   *              attention, C_set do not do flattening, it does not remove subnodes,
   *              you can't access subnodes using dot notation
   *
   *     INCLUDES:
   *          You can include other configs files by using top-level "include" & "include_merge" node
   *          include       - included file top level nodes override currect config
   *          include_merge - Included file merged with original via array_merge
   *
   *
   *     HB.Framework specific config params
   *
   *          site             - site name     (basename(root.site))
   *          project          - project name  (basename(root.project))
   *
   *          .site        - site root path
   *          .project     - project root path (optional) default parent dir of site
   *          .log         - log files root    (optional)
   *          .fw          - framework root    (autodetermined by framework init.php)
   *
   *
   *     Updating cached values
   *         pass ?UPDATE=1 url paramether to force config update
   *
   *
   *
   *     PS: make sure you enabled apc for cli env
   *
  **/


  // PUBLIC PART

// Make "C" functions to be available anywhere
// Import Config Functions into global namespace
namespace {

    use hb\Config\Config;

    // $node:  Dot-separated path.
    function C($node, $file="main") { # config node | Exception
        return Config::get($node, $file);
    }

    // safe get config value
    function CC($node, $file="main") { # config node | false
        return Config::get($node, $file, false);
    }

    // Runtime-only config modification
    // $node - node
    function C_set($node, $value, $file="main") { # $value
        return Config::set($node, $value, $file);
    }

} // namespace


// --------------------------------------------------------------------------------
// PRIVATE / INTERNAL

namespace hb\config {

    /* internal */ class Config {

        const EXT = ".yaml"; // config file extension

        static $data = [];
        const TIMEOUT=30;      // re-read config files timeout
        const STAT_TIMEOUT=3;  // check config files for time change

        // setup framework specific config
        // site, project, framework
        static function init($site, $project=false) {
            self::_load($site, $project);
            self::set(".project", $project);
            self::set(".site", $site);
        }

        // load data from cache,
        // check for file modification (every STAT_TIMEOUT)
        // update cached data if needed
        // included files are not cached, use "?UPDATE=1" to update
        // never call directly. use C("node", ["file"])
        static function _load($site, $project=false, $file="config") {
            $key="config:$site/$file"; // configs are cached per-site / per file

            if (isset($_GET["UPDATE"]))
                apc_delete($key);

            $d = & self::$data[$file];
            $d = apc_fetch($key);
            $time=time();
            if ($d) {
                if ($time - $d[".time"] <= self::STAT_TIMEOUT)
                    return;
            }

            $fsc = "$site/$file".self::EXT;
            $fpc = $project ? "$project/$file".self::EXT : false;
            
            $sc = @filemtime($fsc);
            $pc = $project ? @filemtime($fpc) : 0;

            // check for file modification time
            if ($d && $d[".filetime"]=="$sc:$pc")
                return;

            if (! $sc && ! $pc) {
                $err="CONFIG file not found: $fsc";
                if ($project)
                    $err.=", $fpc";
                throw new \RuntimeException($err);
            }

            require __DIR__."/i/Config.php.inc"; // low level config

            $d = i\Config::load($site, $project, $file);
            
            if (! $d)
                $d = ["no-data" => 1];
            $d[".filetime"] = "$sc:$pc";
            $d[".time"] = $time;

            apc_store($key, $d, self::TIMEOUT);
        } // _load


        // --------------------------------------------------------------------------------
        // NEVER CALL THIS DIRECTLY

        // loads file from site or project directory
        // Config class must be initialized prior to this function call
        static /* private */ function load($file) {
            if (isset(self::$data[$file]))
                return;
            self::_load(self::$data["config"][".site"], self::$data["config"][".project"], $file);
        }

        // never call directly, use C($node, $file) || CC($node, $file)
        // fires an exception when accessing non-existent element
        static /* private */ function get($node, $file="config", $alert=true) { # config node | Exception
            if (! isset(self::$data[$file]))
                self::load($file);
            if (! isset(self::$data[$file][$node])) {
                if ($alert)
                    throw new \RuntimeException("key $node in file $file not found");
                return false;
            }
            return self::$data[$file][$node];
        }

        // never call directly, use C_set($node, $value, $file)
        static /* private */ function set($node, $value, $file="config") { #
            if (! isset(self::$data[$file]))
                self::load($file);
            self::$data[$file][$node]=$value;
        }

        // check loaded config files for expiration
        // used to update configs for long-running daemons
        static function tick() {
            // 
        }

    } // class

} // namespace


?>