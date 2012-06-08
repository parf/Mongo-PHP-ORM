<?

include "Parser.php";

$a = hb\yaml\Parser::parse(__DIR__."/test.yaml");
echo json_encode($a)."\n";
