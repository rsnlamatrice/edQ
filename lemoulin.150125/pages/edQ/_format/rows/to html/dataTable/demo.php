TODO
<?php
$sql = "SELECT IdContact, Name, Enabled  
	FROM contact
	LIMIT 20";
$db = get_db('/_System/dataSource');

$arguments = array(
	'rows' => $db->all( $sql )
	, 'columns' => array(
		'*' => true
		, 'Enabled' => array(
			'type' => 'boolean'
		)
	)
);
page::call('/_html/table/rows/dataTable', $arguments);
?>