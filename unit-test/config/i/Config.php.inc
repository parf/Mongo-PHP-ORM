<?php

// DO NOT TRUST DOC !!
// file was modified and simplified

  /**
   *
   *  SYNOPSIS:
   *      Internal Config Functions
   *
   * loads and parse & postprocess YAML files
   * loads and parse and postprocess remote yaml|json configs
   *
   * INCLUDES - "include" & "merge" TAGS
   * if config have top-level "include" node (array or value)
   * Config will load included file, included file take precedence over config variables
   * as in array("included" => x)+array("loaded" => x)
   *
   * if config have top-level "merge" node (array or value)
   * Config will merge included file
   * as in php array_recursive_merge
   *
   * uses php-pecl-yaml to parse yaml files
   * uses curl to get remote configs
   *
   * REMOTE CONFIGS
   * if config have a remote="true|url" keyword
   * config will be loaded from remote, local config entries will override remote entries
   * remote=true  - config will be loaded from http://config/$project/$site/$file?host=$host
   * remote=https - config will be loaded from https://config/$project/$site/$file?host=$host
   * remote=https://host - config will be loaded from https://host/$project/$site/$file?host=$host
   * remote=https://host/$path -
   * you should specify $site, $project, $file, $host keywords inside your path
   *
   **/

namespace hb\config\i;

include __DIR__."/../../yaml/Yaml.php"; // yaml parser

class Config {

    // CACHING IS IMPLEMENTED in hb\config
    // loads file from site or project directory
    // Config class must be initialized prior to this function call
    // site    - site path
    // project - project path
    static PUBLIC function load($site, $project, $file) { # hash (flattened data added)
        $s = $site    ? self::_load($site, $file)    : [];
        $p = $project ? self::_load($project, $file) : [];
        $r = $s+$p; // site take precedence

        if (isset($r["remote"]) && $remote = $r["remote"]) {
            unset($r["remote"]);
            $r+=self::remote_load($remote);
        }

        if (isset($r["include"]) && $include=$r["include"]) {
            unset($r["include"]);
            foreach( (array) $include as $f)
                $r=self::load($site, $project, $f)+$r;
        }

        // array_merge_recursive
        if (isset($r["merge"]) && $include=$r["merge"]) {
            unset($r["merge"]);
            foreach( (array) $include as $f)
                $r=array_merge_recursive($r, self::load($site, $project, $f));
        }

        return $r;
    }

    static PUBLIC function remote_load($url, $file) { # hash
        // CUT
        return array();
    }

    // load data from cache,
    // check for file modification
    // update cached data if needed
    // included files are not cached, use "?UPDATE=1" to update
    // called from init.php only!!
    static /* private */ function _load($path, $file) {
        // php-pecl-yaml sucks - can't parse my yaml config
        // Spyc workds - but it is 1k+ loc

        // config data
        $cd = \hb\yaml\Yaml::parse($path."/".$file.".yaml");
        if (! $cd) {
            trigger_error("can't read config $path/$file.config");
            die;
        }
        $d = [];
        self::_flatten($cd, '', $d, $d);
        return $d;
    } // _load

    // flatten tree to allow O(1) lookups
    // convert a:b:c:v to a:b:c:v, a.b:c:v, a.b.c:v
    static protected function _flatten($x_node, $prefix, array &$d_root, array &$d_node) { # void
        if (! is_array($x_node))
            return;
        if (count($x_node) == 1) { //scalar with attrs ???
            $v = reset($x_node);
            $k = key($x_node);
            if (is_array($v) && count($v) == 1 && isset($v['@'])) { //scalar with attrs !!!!
                $d_node = array('@'=>$v['@'], '#'=>$k);
                return;
            }
        }
        foreach ($x_node as $k => $v){
            if (('@' !== $k) && is_array($v)) {
                $d_node[$k] = array();
                self::_flatten($v, $prefix ? "$prefix.$k" : $k, $d_root, $d_node[$k]);
                $d_root[$prefix ? "$prefix.$k" : $k] = $d_node[$k];
            } else {
                $d_node[$k] = $v;
                $d_root[$prefix ? "$prefix.$k" : $k] = $v;
            }
        }
    }

} // class

