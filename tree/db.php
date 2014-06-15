<?php
include_once(dirname(__FILE__) . '/../bin/session.php');
include_once(dirname(__FILE__) . '/helpers.php');
if(isset($_REQUEST['operation'])) {
	$dir = dirname(__FILE__);
	require_once($dir . '/../conf/db.conf.php');
	require_once($dir . '/../bin/class.db.php');
	require_once($dir . '/class.tree.php');
				
	$fs = new tree(db::get(DBTYPE . '://' . DBUSER . ':' . DBPASSWORD . '@' . DBSERVER . ':' . DBPORT . '/' . DBNAME)
		, array(
			'structure_table' => 'tree_struct'
			, 'data_table' => 'tree_data'
			, 'data' => array('nm', 'typ', 'icon', 'color', 'design')
			, 'full' => array('ext', 'params', 'ulvl', 'user')
		));
	global $tree;
	$tree = $fs;
	
	if(isset($_GET['operation'])) { //dans l'URL
		$contentType = 'application/json';
		try {
			$rslt = null;
			switch($_REQUEST['operation']) {
				case 'get_node':
					$node = isset($_REQUEST['id']) && $_REQUEST['id'] !== '#' ? (int)$_REQUEST['id'] : 1;
					
					if($_REQUEST['id'] === '#'
					&& isset($_SESSION['tree-root'])
					&& (is_numeric( $_SESSION['tree-root'] ))
					) {//racine
						$node = $_SESSION['tree-root'];
						$temp = array( $fs->get_node((int)$node, array('with_path' => false, 'full' => false)) );
					}
					else //enfants 
						$temp = $fs->get_children($node);
					
					$rslt = array();
					foreach($temp as $v) {
						if( $v['icon'] == null)
							$icon = $v['rgt'] - $v['lft'] > 1 ? "jstree-folder" : "jstree-file";
						else
							$icon = $v['icon'];
						$rslt[] = array('id' => $v['id'], 'text' => $v['nm'], 'icon' => $v['icon'], 'design' => $v['design'], 'color' => $v['color'], 'type' => $v['typ'], 'children' => ($v['rgt'] - $v['lft'] > 1));
					}
					break;
				case 'edit_node':
					$node = isset($_REQUEST['id']) && $_REQUEST['id'] !== '#' ? $_REQUEST['id'] : 0;
					if(is_string($node))
						$node = explode(':', $node);
					if(count($node) > 1) {
						$rslt = array('content' => 'Multiple selected');
					}
					else {
						$node = $fs->get_node((int)$node[0], array('with_path' => false, 'full' => true));
						if( $node['icon'] == null)
							$icon = $node['rgt'] - $node['lft'] > 1 ? "jstree-folder" : "jstree-file";
						else
							$icon = $node['icon'];
						$rslt[] = array('id' => $node['id']
							, 'text' => $node['nm']
							, 'icon' => $node['icon']
							, 'type' => $node['typ']
							, 'ext' => $node['ext']
							, 'design' => $node['design']
							, 'params' => $node['params']
							, 'user' => $node['user']
							, 'ulvl' => $node['ulvl']
							, 'children' => ($node['rgt'] - $node['lft'] > 1)
						);
					}
					break;
				case "get_content":
					$node = isset($_REQUEST['id']) && $_REQUEST['id'] !== '#' ? $_REQUEST['id'] : 0;
					$node = explode(':', $node);
					if(count($node) > 1) {
						$rslt = array('content' => 'Multiple selected');
					}
					else {
						$temp = $fs->get_node((int)$node[0], array('with_path' => true, 'full' => true));
						$rslt = array(
							'title' => 'Selected: /' . implode('/',array_map(function ($v) { return $v['nm']; }, $temp['path'])). '/'.$temp['nm']
							, 'content' => '<pre style="line-height: 1em; text-align: left; padding-left: 1em;">'
								. print_r($temp, true) . '</pre>'
						);
					}
					break;
				case "get_view":
					require_once(dirname(__FILE__) . '/nodeViewer/_class.php');
				
					$vw = isset($_REQUEST['vw']) && $_REQUEST['vw'] !== null ? $_REQUEST['vw'] : '';
					$viewer = nodeViewer::fromClass($vw);
					
					$node = isset($_REQUEST['id']) && $_REQUEST['id'] !== '#' ? $_REQUEST['id'] : 0;
					$node = explode(':', $node);	

					if(count($node) > 1) {
						$rslt = array('content' => 'Multiple selected');
					}
					else {
						$temp = $fs->get_node((int)$node[0], array('with_path' => true, 'with_children' => $viewer->needChildren, 'full' => true));
						try {
							$rslt = $viewer->html($temp);
						}
						catch(Exception $ex){
							$rslt = array(
								"error" => $ex
								, "content" => '<div class="edq-error">' . $ex . '</div>'
							);
						}
					}
						
					if(isset($_REQUEST["get"]) && $_REQUEST["get"] != ''){
						$contentType = 'application/html';	
						if(is_array($rslt))
							$rslt = $rslt[$_REQUEST["get"]];
						else {
							$contentType = 'application/html';		
							$rslt = '<div class="edq-error">' . $rslt . '</div>';
						}
					}
					break;
				case 'create_node':
					$node = isset($_REQUEST['id']) && $_REQUEST['id'] !== '#' ? (int)$_REQUEST['id'] : 0;
					$temp = $fs->mk($node, isset($_REQUEST['position']) ? (int)$_REQUEST['position'] : 0, array('nm' => isset($_REQUEST['text']) ? $_REQUEST['text'] : 'New node'));
					$rslt = array('id' => $temp);
					break;
				case 'rename_node':
					$node = isset($_REQUEST['id']) && $_REQUEST['id'] !== '#' ? (int)$_REQUEST['id'] : 0;
					$rslt = $fs->rn($node, array('nm' => isset($_REQUEST['text']) ? $_REQUEST['text'] : 'Renamed node'));
					break;
				case 'update_node':
					$node = isset($_REQUEST['id']) && $_REQUEST['id'] !== '#' ? (int)$_REQUEST['id'] : 0;
					$params = array();
					if(isset($_REQUEST['text']))
						$params["nm"] = $_REQUEST['text'];
					if(isset($_REQUEST['type']))
						$params["typ"] = $_REQUEST['type'];
					if(isset($_REQUEST['icon']))
						$params["icon"] = $_REQUEST['icon'];
					if(isset($_REQUEST['ext']))
						$params["ext"] = $_REQUEST['ext'];
					if(isset($_REQUEST['params']))
						$params["params"] = $_REQUEST['params'];
					if(isset($_REQUEST['ulvl']))
						$params["ulvl"] = $_REQUEST['ulvl'];
					if(isset($_REQUEST['design']))
						$params["design"] = $_REQUEST['design'] == 'on' || $_REQUEST['design'] == 'true' || $_REQUEST['design'] == '1' ? 1 : 0;
					else
						$params["design"] = 0;
					if(isset($_REQUEST['color']))
						$params["color"] = $_REQUEST['color'];
					if(isset($_REQUEST['user']))
						$params["user"] = $_REQUEST['user'];
					$rslt = $fs->rn($node, $params, true);
					break;
				case 'delete_node':
					$node = isset($_REQUEST['id']) && $_REQUEST['id'] !== '#' ? (int)$_REQUEST['id'] : 0;
					$rslt = $fs->rm($node);
					break;
				case 'move_node':
					$node = isset($_REQUEST['id']) && $_REQUEST['id'] !== '#' ? (int)$_REQUEST['id'] : 0;
					$parn = isset($_REQUEST['parent']) && $_REQUEST['parent'] !== '#' ? (int)$_REQUEST['parent'] : 0;
					$rslt = $fs->mv($node, $parn, isset($_REQUEST['position']) ? (int)$_REQUEST['position'] : 0);
					break;
				case 'copy_node':
					$node = isset($_REQUEST['id']) && $_REQUEST['id'] !== '#' ? (int)$_REQUEST['id'] : 0;
					$parn = isset($_REQUEST['parent']) && $_REQUEST['parent'] !== '#' ? (int)$_REQUEST['parent'] : 0;
					$rslt = $fs->cp($node, $parn, isset($_REQUEST['position']) ? (int)$_REQUEST['position'] : 0);
					break;
				default:
					throw new Exception('[tree/db] operation inconnue : ' . $_REQUEST['operation']);
					break;
			}
			header('Content-Type: ' . $contentType . '; charset=utf8');
			if($contentType == 'application/json')	
				echo json_encode($rslt);
			else
				echo ($rslt);
		}
		catch (Exception $e) {
			header($_SERVER["SERVER_PROTOCOL"] . ' 500 Server Error');
			header('Status:  500 Server Error');
			echo $e->getMessage();
		}
		die();
	}
}
?>