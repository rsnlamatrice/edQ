<?php
$node = node($node, __FILE__);

$sql = 'SELECT * FROM "public"."ccompt00002" AS "ccompt00002"';

$rows = node('..postgresql', $node, 'call', array(
	'sql' => $sql
));


node('/_html/table/rows/dataTable', $node, 'call', array('rows' => $rows));

?>