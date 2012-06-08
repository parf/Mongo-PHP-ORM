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

        // $yd=yaml_parse_file($path."/".$file.".yaml");  // php-pecl-yaml sucks

        // config data
        $cd = self::parse_config($path."/".$file.".config");
        // $cd = SimpleYaml::parse($path."/".$file.".config");

        if (! $cd) {
            trigger_error("can't read config $path/$file.config");
            die;
        }
        $d = [];
        self::_flatten($cd, '', $d, $d);
        return $d;
    } // _load

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

    // json / yaml hybrid
    // good yaml parsers are too heavy, php-pecl-yaml sucks
    // json is too verbose
    //
    // lines started with # - comments
    // no commas at the end of the line
    // do not quote strings
    // no multyline constructs
    /*

    SYNTAX example

    # Comment
    node: {
       node: val
       node: val
       node: {
           node: [1,2,3]
           node: {"a":1,"b":"b"}
       }
       node: this is a string
       node: "this is too"
    }


    // todo - get rid of curly brackets

    */
    static function parse_config($file) { # hash
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $r = []; // result
        $sp = 0; // stack position
        $stack = [0];
        $line=0; // line number
        foreach($lines as $l) {
            $line++;
            $l = trim($l);
            if (!$l || $l[0]=='#')  // comments and empty lines
                continue;
            if ($l == '}') {
                $sp--;
                $r[] = $l;
                continue;
            }
            list($k, $v) = explode(":", $l, 2);
            $v = trim($v);
            if (! $v) {
                trigger_error("key:value expected line $line: $l");
                die;
            }
            if ($k[0]!=='"')
                $k='"'.$k.'"';

            $c = $stack[$sp] ? "," : "";
            $stack[$sp]++;

            if (is_numeric($v)) {
                $r[] = "$c$k:$v";
                continue;
            }
            $e = substr($v,-1); // end of line
            if ($e == '{') {
                $r[] = "$c$k:$v";
                $stack[++$sp]=0;
                continue;
            }
            if ($e==']' || $e=='}' || $e=='"') {  // inline [] or {} or "xxx"
                $r[] = "$c$k:$v";
                continue;
            }
            $r[] = "$c$k:\"$v\"";  // value is string - add ""
        }

        // echo join("\n", $r);
        return json_decode("{".join("", $r)."}", true);
    }


    /*
   YAML subset / YAML alike syntax.

   limitations:
      value can be numbers and strings and json([...] and {...})
      no multiline constructs

   SYNTAX example

    # Comment
    node:
       node: int/float
       node: 1
       node: 3.141
       node: text
       node: "text"
       node: json  # any_valid_one_line_json
       node:
           node: [1,2,3]
           node: {"a":1,"b":"b","c":true}
       node: xxx
       node:
       - list item
       - list item


    */
    static function parse_yaml($file) { # hash
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $r = []; // result
        $line=0; // line number

        $sp = 0; // stack position
        $stack = [0];

        // types - 1=hash, 2=list

        foreach($lines as $l) {
            $line++;
            $l = rtrim($l);
            $l0 = strlen($l);
            $l = ltrim($l);
            if (!$l || $l[0]=='#')  // comments and empty lines
                continue;
            $indent = $l0 - strlen($l);
            if ($l[0]=='-') {
                // todo
                continue;
            }
            if ($sp && $indent == $stack[$sp])
                $r[]=",";

            if ($indent < $stack[$sp]) {
                while($sp>0) {
                    $sp--;
                    if ($indent==$stack[$sp]) {
                        $r[]="}";
                        break;
                    }
                    if ($indent>$stack[$sp]) {
                        trigger_error("indentation problem. line $line: $l");
                        die;
                    }
                }
            }

            list($k, $v) = explode(":", $l, 2);
            if ($v === NULL) {
                trigger_error("key:[value] expected line $line: $l");
                die;
            }
            $k = trim($k);
            if (! $k) {
                trigger_error("key missing at $line: $l");
                die;
            }
            $v = trim($v);
            $k = '"'.$k.'"';

            // going deep
            if (! $v) {
                $r[]="$k:{";
                $stack[++$sp] = $indent;
                continue;
            }
            // escape strings
            if (! is_numeric($v)) {
                $_ = $v[0];
                if ($_!='"' && $_!='[' && $_!='{')
                    $v = '"'.$v.'"';
            }
            $r[]="$k:$v";

        }
        return $r;
    }
}




/*
  YAML subset / YAML alike syntax.

  limitations:
  value can be numbers and strings and json([...] and {...})
  no multiline constructs

  SYNTAX example

  # Comment
  node:
       node: 1        # int
       node: 3.141    # float
       node: text     # unquoted text
       node: "text"   # quoted text
       node: json  # any_valid_one_line_json
       node:
           node: [1,2,3]
           node: {"a":1,"b":"b","c":true}

       node:    # list of items
       - list item
       - list item

       node:    # list of hashes
       - node:
         node:
         node:
       - node:
         node:
         node:

    */

class SimpleYaml {

    public static function parse($file) {
        $p = new static($file);
        return $p->doit();
    }

    // --------------------------------------------------------------------------------
    // PRIVATE / INTERNAL

    protected $lines;           // line buffer
    protected $line = 0;        // line number
    protected $lines_total = 0; // lines in file

    protected function __construct($file) {
        $this->lines = file($file, FILE_IGNORE_NEW_LINES);
        $this->lines_total = count($this->lines);
    }


    function doit($ident=0) {
        $r = [];
        $p = 0; // same-ident position 
        while($ikv=$this->next()) {
            list($id, $k, $v)=$ikv; // ident, key, value

            echo "D($ident) ".json_encode($ikv)."\n";

            // node: x1
            //   node: y
            // node: x2 << this case
            if ($id<$ident) {
                $this->redo = $ikv;
                break;
            }

            // bad identation
            // node: v
            //  node: v
            if ($id>$ident && $v!=='') {
                trigger_error("Bad ident($id) expected: $ident. Line $this->line '$k:$v'");
                die;
            }

            // node: << opening node
            if ($v==='') {
                $next = $this->next();
                if (! $next) {
                    trigger_error("child node expected. Line $this->line '$k:$v'");
                    die;
                }
                $next_id=$next[0];
                $this->redo = $next;
                $v=$this->doit($next_id);
            }
            
            $r[]="\"$k\":$v";
            $p++;
        }
        return "{".join(",", $r)."}";
    }

    private $redo;

    // get next useful line
    // skip empty lines and comments
    function next() {           // [ident, key, value]  | false
        if ($this->redo) {
            $v = $this->redo;
            $this->redo = null;
            return $v;
        }
        if ($this->lines_total == $this->line)
            return false; // EOF

        $l = $this->lines[$this->line];
        $this->line++;

        $l = rtrim($l);
        $l0 = strlen($l);
        $l = ltrim($l);
        if (!$l || $l[0]=='#')  // comments and empty lines
            return $this->next();
        $ident = $l0 - strlen($l);

        // same-line '#' comments
        if ($o=strrpos($l, "#")) {
            // do we have quotes in value
            if (strpos(substr($l, 0, $o-1), '"')!==false) {
                if (substr_count($l, '"', 0, $o-1) & 1)
                    $o=0; // uneven number of quotes, this is not a comment
            }
            if ($o)
                $l = rtrim(substr($l, $o-1));
        }

        // if (! preg_match('/^[\w ]+:/', $l))
        //    return [$ident, '', $l];  // no KEY

        list($k, $v) = explode(":", $l, 2);
        $k = trim($k);
        $v = trim($v);

        // escape strings
        if ($v && ! is_numeric($v)) {
            $_ = $v[0];
            if ($_!='"' && $_!='[' && $_!='{')
                $v = '"'.$v.'"';
        }
        return [$ident, $k, $v];  // $k - unescaped, $v escaped
    }


}

?>
