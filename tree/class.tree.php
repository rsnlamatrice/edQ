<?php
/***
 * class tree
 * 	gestion des noeuds en base de données
 */
// TODO: better exceptions, use params
class tree {
	protected $db = null;
	protected $options = null;
	protected $default = array(
		'structure_table'	=> 'structure',		// the structure table (containing the id, left, right, level, parent_id and position fields)
		'data_table'			=> 'structure',		// table for additional fields (apart from structure ones, can be the same as structure_table)
		'plugins'				=> array(	//plugins
			'param' => array(				// table des parametres de noeuds
				'table' =>'node_param',			//
				'structure'	=> array(	// champs
					'id'			=> 'id',
					'param'			=> 'param',
					'domain'			=> 'domain',
					'value'			=> 'value',
					'sortIndex'			=> 'sortIndex'
				)
			),
			'comment' => array(				// table des commentaires de noeuds
				'table' =>'node_comment',			//
				'structure'	=> array(	// champs
					'id'			=> 'id',
					'value'			=> 'value'
				)
			)
		),
		'data2structure'	=> 'id',			// which field from the data table maps to the structure table
		'structure'			=> array(			// which field (value) maps to what in the structure (key)
			'id'			=> 'id',			// which field (value) maps to what in the structure (key)
			'left'			=> 'lft',
			'right'			=> 'rgt',
			'level'			=> 'lvl',
			'parent_id'		=> 'pid',
			'position'		=> 'pos'
		),
		'data'				=> array(			// array of additional fields from the data table
		)
	);

	public function __construct(\vakata\database\IDB $db, array $options = array()) {
		$this->db = $db;
		$this->options = array_merge($this->default, $options);
	}

	/* get_node
		recherche le noeud dans la table tree_data

		$id : integer
	*/
	public function get_node($id, $options = array(), $errorIfNotExists = true) {
		// $id est un noeud
		if(is_array($id) && isset($id['id'])){
			// sauf si on veut la parenté et qu'on ne le connait pas, retourne $id
			if(isset($options['with_path']) && $options['with_path']
			&& !isset($id['path']))
				$id['path'] = $this->get_path($id['id']);
			if(isset($options['with_children']) && $options['with_children']
			&& !isset($id['children']))
				$id['children'] = $this->get_children($id['id'], isset($options['deep_children']) && $options['deep_children']);
			return $id;
		}

		$notDesign_only = isset($options['design']) && !$options['design'];

		$node = $this->db->one("
			SELECT
				s.".implode(", s.", $this->options['structure']).",
				d.".implode(", d.", $this->options['data'])."
				". (isset($options['full']) && $options['full'] ? ", d.".implode(", d.", $this->options['full']) : "")."
			FROM
				".$this->options['structure_table']." s
			JOIN
				".$this->options['data_table']." d
				ON
					s.".$this->options['structure']['id']." = d.".$this->options['data2structure']."
			WHERE
				s.".$this->options['structure']['id']." = ?"
				. ($notDesign_only ? ' AND d.design = 0' : '')
			, array( (int)$id )
		);
		if(!$node) {
			if(!$errorIfNotExists)
				return false;
			throw new Exception('Node does not exist : ' . print_r($id, true));
		}
		if(isset($options['with_children']) && $options['with_children']) {
			$node['children'] = $this->get_children($id, isset($options['deep_children']) && $options['deep_children']);
		}
		if(isset($options['with_path']) && $options['with_path']) {
			$node['path'] = $this->get_path($id);
		}
		return $node;
	}

	/* get_children
		$options : {
			$recursive : boolean = false
			$full : boolean = false
			$filter_name : string
		}
		$options as boolean : $recursive = $options;
	*/
	public function get_parent($id, $options = false) {
		//echo('get_parent entree $id = '); var_dump($id);
		if(!$id){
			node('/_System/debug/callstack', null, 'call');
			throw new Exception('tree::get_parent : $id est incorrect');
		}

		// $id est un noeud
		//echo('get_parent is_array($id) = '); var_dump(is_array($id));
		if(is_array($id) && isset($id['id'])){
			if(isset($id['path'])){
				if($id['path'][ count($id['path']) - 1 ]['id'] != $id['id'])
					return $this->get_node($id['path'][ count($id['path']) - 1 ], $options);
				else
					return $this->get_node($id['path'][ count($id['path']) - 2 ], $options);
			}
			if($id['pid'])
				if(($id['id'] == $id['pid']) || ($id['id'] === TREE_ROOT ))
					throw new Exception('tree::get_parent : $id est la racine');
				else
					return $this->get_node($id['pid'], $options);

		}
		return $this->get_parent($this->get_node($id, array( 'with_path' => true )), $options);
	}
	/* get_children
		$options : {
			$recursive : boolean = false
			$full : boolean = false
			$filter_name : string
		}
		$options as boolean : $recursive = $options;
	*/
	public function get_children($id, $options = false) {

		if(is_array($id) && $id['id'])
			$node = $id;
		else
			$node = $this->get_node($id);

		// recherche d'un enfant nommé
		// si contient /, boucle sur chacune des parties de la recherche
		if(is_array($options)
		&& isset($options['f--name'])
		&& strpos($options['f--name'], '/') !== FALSE){
			$parent_node = $node;
			$path = explode( '/', $options['f--name'] );
			foreach($path as $short_name){
				//var_dump($short_name);
				$options['f--name'] = $short_name;
				$child = $this->get_children($parent_node, $options);
				if(!$child || count($child) == 0)
					return $child;
				$parent_node = $child[0];
			}
			return $child;
		}

		if(is_bool($options)){
			$recursive = $options;
			$options = array();
		}
		else
			$recursive = isset($options['recursive']) && $options['recursive'];
		$notDesign_only = isset($options['design']) && ($options['design'] == false);
		$sql = false;

		if($recursive) {
			$sql = "
				SELECT
					s.".implode(", s.", $this->options['structure']).",
					d.".implode(", d.", $this->options['data'])."
					". (isset($options['full']) && $options['full'] ? ", d.".implode(", d.", $this->options['full']) : "").",
					s.rgt - s.lft - 1 AS has_children
				FROM
					".$this->options['structure_table']." s,
					".$this->options['data_table']." d
				WHERE
					s.".$this->options['structure']['id']." = d.".$this->options['data2structure']." AND
					s.".$this->options['structure']['left']." > ".(int)$node[$this->options['structure']['left']]." AND
					s.".$this->options['structure']['right']." < ".(int)$node[$this->options['structure']['right']]."
					" . ( isset($options['f--name']) ? " AND d.nm = '" . str_replace("'", "\\'", $options['f--name']) . "'" : "" ) . "
					" . ($notDesign_only ? ' AND d.design = 0' : '') ."
				ORDER BY
					s.".$this->options['structure']['left']."
			";
		}
		else {
			$sql = "
				SELECT
					s.".implode(", s.", $this->options['structure']).",
					d.".implode(", d.", $this->options['data'])."
					". (isset($options['full']) && $options['full'] ? ", d.".implode(", d.", $this->options['full']) : "")."
					, ". ($notDesign_only
						? "EXISTS (SELECT 1
						FROM ".$this->options['structure_table']." schildren,
							".$this->options['data_table']." dchildren
						WHERE
							schildren.".$this->options['structure']['id']." = dchildren.".$this->options['data2structure']." AND
							schildren.".$this->options['structure']['parent_id']." = s.".$this->options['structure']['id']." AND
							dchildren.design = 0)"
						: "s.rgt - s.lft - 1")
					." AS has_children
				FROM
					".$this->options['structure_table']." s,
					".$this->options['data_table']." d
				WHERE
					s.".$this->options['structure']['id']." = d.".$this->options['data2structure']." AND
					s.".$this->options['structure']['parent_id']." = ".$node['id']."
					" . ( $notDesign_only ? 'AND d.design = 0' : '') . "
					" . ( isset($options['f--name']) ? " AND d.nm = '" . str_replace("'", "\\'", $options['f--name']) . "'" : "" ) . "
				ORDER BY
					s.".$this->options['structure']['position']."
			";
		}

		return $this->db->all($sql);
	}
	/* static get_child_by_name
		$options : {
			$recursive : boolean = false
			$full : boolean = false
		}
		$options as boolean : $recursive = $options;
		ED140723
	*/
	public static function get_child_by_name($name, $refersTo, $options = false) {
		global $tree;
		if(is_array($refersTo))
			$refersTo = $refersTo['id'];
		if(is_bool($options))
			$options = array();
		$options['f--name'] = $name;
		$children = $tree->get_children($refersTo, $options);
		if(count($children) != 1)
			return count($children);
		return $children[0];
	}
	/* static get_node_by_name
		$options : {
			$recursive : boolean = false
			$full : boolean = false
		}
		$options as boolean : $recursive = $options;
		ED140723
	*/
	public static function get_node_by_name($name, $refersTo = false, $options = false) {
		global $tree;
		if(is_bool($options) || $options == null)
			$options = array();

		global $tree;

		/* deja un noeud */
		if(is_array($name) && isset($name['id'])){
			return $tree->get_node($name, array_merge(array('with_path' => false, 'with_children' => false, 'full' => true), $options));
		}

		/* identifiant */
		if(is_numeric($name)){
			return $tree->get_node($name, array_merge(array('with_path' => false, 'with_children' => false, 'full' => true), $options));
		}

		/* chemin absolu */
		if($name[0] == '/'){
			$names = explode('/', substr($name, 1));
			$sql = '';
			$depth = 0;
			$root = TREE_ROOT;
			foreach($names as $shortName){
				$sqln = 
					"SELECT "
						. ( $depth == (count($names)-1) ? "d.*" : "s." . $tree->options['structure']['id'] ) 
					." FROM "
						.$tree->options['structure_table']." s "
					." JOIN "
						.$tree->options['data_table']." d "
					." ON "
						." s.".$tree->options['structure']['id']." = d.".$tree->options['data2structure']
					." WHERE "
						." d.nm = '" . str_replace("'", "\\'", $shortName) . "'"
				;
				if($depth > 0){ // descendant
					$sql = $sqln . 
						" AND s.".$tree->options['structure']['parent_id']." IN "
						."(" . $sql . ")";
				}
				else // racine
					$sql = $sqln . 
						" AND s.".$tree->options['structure']['parent_id']." = ". $root;
				$depth++;
			}
			/*var_dump(($sql));
			$sql = str_replace('&#10;', '', $sql);
			var_dump(($sql));*/
			$nodes = $tree->db->all($sql);
			if(is_array($options)
			   && ($options['with_path'] || $options['with_children']))
				return $tree->get_node($nodes[0], array_merge(array('with_path' => false, 'with_children' => false, 'full' => true), $options));
		}
		/* chemin relatif */
		else {
			if(is_bool($refersTo))
				throw new Exception('tree::get_node_by_name : argument 2, $refersTo, is missing');

			global $tree;

			$search = $name;
			//var_dump($ref);
			//var_dump($search);
			if($search[0] == '.'){
				if($search[1] == '.'){ // eg : '..dataSource' on cherche chez les parents
					if(strlen($search) == 2){ // ..
						$parent = $tree->get_parent($refersTo, array_merge(array('with_path' => false, 'with_children' => false, 'full' => true), $options));
						/*print_r($parent['id']);
						print_r($refersTo['id']);*/
						return $parent;
					}
					else {
						$refersTo = $tree->get_parent($refersTo, array('with_path' => false, 'with_children' => false, 'full' => false));
						if(!is_array($refersTo)
						|| ($refersTo['nm'] == substr($search, 2)))
							return $refersTo;
						$parent = $tree->get_parent($refersTo, array('with_path' => false, 'with_children' => false, 'full' => false));
						if($parent['id'] == $refersTo['id']) {

							helpers::callstack();

							var_dump($refersTo);
							var_dump($parent);
							echo 'Erreur d\'ascendant ' . print_r($search, true);
							return null;
						}
						if(!is_array($parent)
						|| ($parent['nm'] == substr($search, 2)))
							return $parent;
						$child = self::get_child_by_name(substr($search, 2), $parent, $options);
						if(is_array($child))
							return $child;
						if(!is_array($parent) || ($parent['id'] == TREE_ROOT)) {
							echo 'Aucun ascendant ' . print_r($search);
							return null;
						}
						var_dump($parent);
						return self::get_node_by_name($search, $parent, $options);
					}
				}
				else { // eg : '.dataSource' : on cherche ici et chez les parents
					$parent = $tree->get_parent($refersTo, array_merge(array('with_path' => false, 'with_children' => false, 'full' => false), $options));
					$options['f--name'] = substr($name, 1);
					$nodes = $tree->get_children($parent, $options);
					if(count($nodes) == 0)
						return self::get_node_by_name( '.' . $search, $parent); //recursive chez les parents
				}
			}
			else if($search[0] == ':'){ // eg : ':Liste' : dans la descendance
				//$file = helpers::combine(substr($__FILE__, 0, strlen($__FILE__) - 4), substr($search, 1), $extension); // $__FILE__ moins l'extension .php
				$options['f--name'] = substr($name, 1);
				$nodes = $tree->get_children($refersTo, $options);
			}
			else {// eg : 'dataSource'
				$parent = $tree->get_parent($refersTo, array('with_path' => false, 'with_children' => false, 'full' => false));
				if(!$parent)
					throw new Exception('tree:get_node_by_name : $parent est introuvable. ' . print_r($refersTo, true));
				$options['f--name'] = $name;
				$nodes = $tree->get_children($parent, $options);
			}


		}
		//return 1 only
		if(count($nodes) != 1)
			return count($nodes);//error
		return $nodes[0];
	}
	/* static get_id_by_name
		$options : {
			$recursive : boolean = false
			$full : boolean = false
		}
		$options as boolean : $recursive = $options;
		ED140723
	*/
	public static function get_id_by_name($name, $refersTo = false, $options = false) {
		$node = self::get_node_by_name($name, $refersTo, $options);
		if(is_array($node))
			return $node['id'];
		return $node;
	}

	/* get_path
		return array;
	*/
	public function get_path($id) {
		$node = $this->get_node($id);
		$sql = false;
		if($node) {
			$sql = "
				SELECT
					s.".implode(", s.", $this->options['structure']).",
					d.".implode(", d.", $this->options['data'])."
				FROM
					".$this->options['structure_table']." s,
					".$this->options['data_table']." d
				WHERE
					s.".$this->options['structure']['id']." = d.".$this->options['data2structure']." AND
					s.".$this->options['structure']['left']." < ".(int)$node[$this->options['structure']['left']]." AND
					s.".$this->options['structure']['right']." > ".(int)$node[$this->options['structure']['right']]."
				ORDER BY
					s.".$this->options['structure']['left']."
			";
		}
		return $sql ? $this->db->all($sql) : false;
	}
	/* get_path_string
		return string;
		ED140723
	*/
	public function get_path_string($id, $joinChar = '/') {
		$node = $this->get_node($id, array('with_path' => true, 'full' => false));
		return $joinChar . implode($joinChar, array_map(function ($v) { return $v['nm']; }, $node['path'])). $joinChar .$node['nm'];
	}

	/* get_unique_name
		"dossier" devient "dossier_1" si un noeud existe déjà
		"dossier_1" devient "dossier_2" si un noeud existe déjà
		retourne le nouveau nom disponible
		ED170726
	*/
	public function get_unique_name($parent, $name, $excude_id = 0){
		if(is_array($parent))
			$parent = (int)$parent[$this->options['structure']['id']];
		else
			$parent = (int)$parent;
		$nTest = 0;
		do {
			$sql = "
				SELECT d.nm
				FROM
					".$this->options['structure_table']." s
				JOIN
					".$this->options['data_table']." d
					ON s.".$this->options['structure']['id']." = d.".$this->options['data2structure']."
				WHERE
					s.".$this->options['structure']["parent_id"]." = ? AND
					d.nm = ? AND
					s.".$this->options['structure']['id']." <> ?
				";
			//Etend le nom avec l'index
			//TODO trier les noms existants plutot que de boucher les trous d'index
			if( $nTest == 0)
				$new_name = $name;
			else
				$new_name = preg_replace('/(.+)_\d+$/', '$1', $name) . '_' . $nTest;
			$par = array(
				$parent
				, $new_name
				, $excude_id
			);
			try {
				$existing = $this->db->all($sql, $par);
				if(count($existing) == 0){
					return $new_name;
				}
				$nTest++;
			} catch(Exception $e) {
				throw new Exception('Could not create ' . $name);
			}
		}
		while(true);
	}

	/* mk
		nouveau noeud
	*/
	public function mk($parent, $position = 0, $data = array()) {
		if(is_array($parent))
			$parent = (int)$parent[$this->options['structure']['id']];
		else
			$parent = (int)$parent;
		if($parent == 0) { throw new Exception('Parent is 0'); }
		$parent = $this->get_node($parent, array('with_children'=> true));
		if(!$parent['children']) { $position = 0; }
		if($parent['children'] && $position >= count($parent['children'])) { $position = count($parent['children']); }

		$data['ulvl'] = 256; //TODO GLOBAL CONST

		// CHECK NAME
		// unique name only
		$data['nm'] = $this->get_unique_name($parent, $data['nm']);

		$sql = array();
		$par = array();

		// PREPARE NEW PARENT
		// update positions of all next elements
		$sql[] = "
			UPDATE ".$this->options['structure_table']."
				SET ".$this->options['structure']["position"]." = ".$this->options['structure']["position"]." + 1
			WHERE
				".$this->options['structure']["parent_id"]." = ".(int)$parent[$this->options['structure']['id']]." AND
				".$this->options['structure']["position"]." >= ".$position."
			";
		$par[] = false;

		// update left indexes
		$ref_lft = false;
		if(!$parent['children']) {
			$ref_lft = $parent[$this->options['structure']["right"]];
		}
		else if(!isset($parent['children'][$position])) {
			$ref_lft = $parent[$this->options['structure']["right"]];
		}
		else {
			$ref_lft = $parent['children'][(int)$position][$this->options['structure']["left"]];
		}
		$sql[] = "
			UPDATE ".$this->options['structure_table']."
				SET ".$this->options['structure']["left"]." = ".$this->options['structure']["left"]." + 2
			WHERE
				".$this->options['structure']["left"]." >= ".(int)$ref_lft."
			";
		$par[] = false;

		// update right indexes
		$ref_rgt = false;
		if(!$parent['children']) {
			$ref_rgt = $parent[$this->options['structure']["right"]];
		}
		else if(!isset($parent['children'][$position])) {
			$ref_rgt = $parent[$this->options['structure']["right"]];
		}
		else {
			$ref_rgt = $parent['children'][(int)$position][$this->options['structure']["left"]] + 1;
		}
		$sql[] = "
			UPDATE ".$this->options['structure_table']."
				SET ".$this->options['structure']["right"]." = ".$this->options['structure']["right"]." + 2
			WHERE
				".$this->options['structure']["right"]." >= ".(int)$ref_rgt."
			";
		$par[] = false;

		// INSERT NEW NODE IN STRUCTURE
		$sql[] = "INSERT INTO ".$this->options['structure_table']." (".implode(",", $this->options['structure']).") VALUES (?".str_repeat(',?', count($this->options['structure']) - 1).")";
		$tmp = array();
		foreach($this->options['structure'] as $k => $v) {
			switch($k) {
				case 'id':
					$tmp[] = null;
					break;
				case 'left':
					$tmp[] = (int)$ref_lft;
					break;
				case 'right':
					$tmp[] = (int)$ref_lft + 1;
					break;
				case 'level':
					$tmp[] = (int)$parent[$v] + 1;
					break;
				case 'parent_id':
					$tmp[] = $parent[$this->options['structure']['id']];
					break;
				case 'position':
					$tmp[] = $position;
					break;
				default:
					$tmp[] = null;
			}
		}
		$par[] = $tmp;

		foreach($sql as $k => $v) {
			try {
				$this->db->query($v, $par[$k]);
			} catch(Exception $e) {
				$this->reconstruct();
				throw new Exception('Could not create');
			}
		}
		if($data && count($data)) {
			$node = $this->db->insert_id();
			if(!$this->rn($node,$data)) {
				$this->rm($node);
				throw new Exception('Could not rename after create');
			}
		}
		return $node;
	}

	/* mv
		déplace
	*/
	public function mv($id, $parent, $position = 0) {
		$id			= (int)$id;
		//ED140608
		$idId		= $id;

		$parent		= $parent == '#' ? TREE_ROOT : (int)$parent;
		if($parent == 0 || $id == 0 || $id == 1) {
			throw new Exception('Cannot move inside 0, or move root node');
		}

		$parent		= $this->get_node($parent, array('with_children'=> true, 'with_path' => true));
		$id			= $this->get_node($id, array('with_children'=> true, 'deep_children' => true, 'with_path' => true));
		if(!$parent['children']) {
			$position = 0;
		}
		if($id[$this->options['structure']['parent_id']] == $parent[$this->options['structure']['id']] && $position > $id[$this->options['structure']['position']]) {
			$position ++;
		}
		if($parent['children'] && $position >= count($parent['children'])) {
			$position = count($parent['children']);
		}
		if($id[$this->options['structure']['left']] < $parent[$this->options['structure']['left']] && $id[$this->options['structure']['right']] > $parent[$this->options['structure']['right']]) {
			throw new Exception('Could not move parent inside child');
		}

		//ED140608
		$oldName 	= $id['nm'];
		$oldPath 	= implode('/', array_map(function ($v) { return $v['nm']; }, $id['path']));

		$tmp = array();
		$tmp[] = (int)$id[$this->options['structure']["id"]];
		if($id['children'] && is_array($id['children'])) {
			foreach($id['children'] as $c) {
				$tmp[] = (int)$c[$this->options['structure']["id"]];
			}
		}
		$width = (int)$id[$this->options['structure']["right"]] - (int)$id[$this->options['structure']["left"]] + 1;

		// CHECK NAME
		// unique name only
		$id['nm'] = $this->get_unique_name($parent, $id['nm'], $id['id']);

		$sql = array();

		// PREPARE NEW PARENT
		// update positions of all next elements
		$sql[] = "
			UPDATE ".$this->options['structure_table']."
				SET ".$this->options['structure']["position"]." = ".$this->options['structure']["position"]." + 1
			WHERE
				".$this->options['structure']["id"]." != ".(int)$id[$this->options['structure']['id']]." AND
				".$this->options['structure']["parent_id"]." = ".(int)$parent[$this->options['structure']['id']]." AND
				".$this->options['structure']["position"]." >= ".$position."
			";

		// update left indexes
		$ref_lft = false;
		if(!$parent['children']) {
			$ref_lft = $parent[$this->options['structure']["right"]];
		}
		else if(!isset($parent['children'][$position])) {
			$ref_lft = $parent[$this->options['structure']["right"]];
		}
		else {
			$ref_lft = $parent['children'][(int)$position][$this->options['structure']["left"]];
		}
		$sql[] = "
			UPDATE ".$this->options['structure_table']."
				SET ".$this->options['structure']["left"]." = ".$this->options['structure']["left"]." + ".$width."
			WHERE
				".$this->options['structure']["left"]." >= ".(int)$ref_lft." AND
				".$this->options['structure']["id"]." NOT IN(".implode(',',$tmp).")
			";
		// update right indexes
		$ref_rgt = false;
		if(!$parent['children']) {
			$ref_rgt = $parent[$this->options['structure']["right"]];
		}
		else if(!isset($parent['children'][$position])) {
			$ref_rgt = $parent[$this->options['structure']["right"]];
		}
		else {
			$ref_rgt = $parent['children'][(int)$position][$this->options['structure']["left"]] + 1;
		}
		$sql[] = "
			UPDATE ".$this->options['structure_table']."
				SET ".$this->options['structure']["right"]." = ".$this->options['structure']["right"]." + ".$width."
			WHERE
				".$this->options['structure']["right"]." >= ".(int)$ref_rgt." AND
				".$this->options['structure']["id"]." NOT IN(".implode(',',$tmp).")
			";

		// MOVE THE ELEMENT AND CHILDREN
		// left, right and level
		$diff = $ref_lft - (int)$id[$this->options['structure']["left"]];

		if($diff > 0) { $diff = $diff - $width; }
		$ldiff = ((int)$parent[$this->options['structure']['level']] + 1) - (int)$id[$this->options['structure']['level']];
		$sql[] = "
			UPDATE ".$this->options['structure_table']."
				SET ".$this->options['structure']["right"]." = ".$this->options['structure']["right"]." + ".$diff.",
					".$this->options['structure']["left"]." = ".$this->options['structure']["left"]." + ".$diff.",
					".$this->options['structure']["level"]." = ".$this->options['structure']["level"]." + ".$ldiff."
				WHERE ".$this->options['structure']["id"]." IN(".implode(',',$tmp).")
		";
		// position and parent_id
		$sql[] = "
			UPDATE ".$this->options['structure_table']."
				SET ".$this->options['structure']["position"]." = ".$position.",
					".$this->options['structure']["parent_id"]." = ".(int)$parent[$this->options['structure']["id"]]."
				WHERE ".$this->options['structure']["id"]."  = ".(int)$id[$this->options['structure']['id']]."
		";

		/* ED140726 */
		if($oldName != $id['nm']){
			// changement de nom ( + _%index%)
			$sql[] = "
				UPDATE ".$this->options['data_table']."
					SET nm = '" . str_replace( "'", "\\'", $id['nm'] ) . "'
					WHERE ".$this->options['structure']["id"]."  = ".(int)$id[$this->options['structure']['id']]."
			";
			//var_dump($sql[count($sql)-1]);
		}

		// CLEAN OLD PARENT
		// position of all next elements
		$sql[] = "
			UPDATE ".$this->options['structure_table']."
				SET ".$this->options['structure']["position"]." = ".$this->options['structure']["position"]." - 1
			WHERE
				".$this->options['structure']["parent_id"]." = ".(int)$id[$this->options['structure']["parent_id"]]." AND
				".$this->options['structure']["position"]." > ".(int)$id[$this->options['structure']["position"]];
		// left indexes
		$sql[] = "
			UPDATE ".$this->options['structure_table']."
				SET ".$this->options['structure']["left"]." = ".$this->options['structure']["left"]." - ".$width."
			WHERE
				".$this->options['structure']["left"]." > ".(int)$id[$this->options['structure']["right"]]." AND
				".$this->options['structure']["id"]." NOT IN(".implode(',',$tmp).")
		";
		// right indexes
		$sql[] = "
			UPDATE ".$this->options['structure_table']."
				SET ".$this->options['structure']["right"]." = ".$this->options['structure']["right"]." - ".$width."
			WHERE
				".$this->options['structure']["right"]." > ".(int)$id[$this->options['structure']["right"]]." AND
				".$this->options['structure']["id"]." NOT IN(".implode(',',$tmp).")
		";

		foreach($sql as $k => $v) {
			//echo preg_replace('@[\s\t]+@',' ',$v) ."\n";
			try {
				$this->db->query($v);
			} catch(Exception $e) {
				$this->reconstruct();
				throw new Exception('Error moving ' . $v);
			}
		}

		//ED140608
		$id			= $this->get_node($idId, array('with_children'=> false, 'deep_children' => false, 'with_path' => true, 'full' => false));
		$new_name 	= $id['nm'];
		$new_path 	= implode('/', array_map(function ($v) { return $v['nm']; }, $id['path']));
		helpers::nodeFile_mv($oldPath, $oldName, $new_path, $new_name, true);

		return array(
			'id' => $id['id']
			, 'nm' => $new_name
		);
	}

	/* copy
	*/
	public function cp($id, $parent, $position = 0) {
		$id			= (int)$id;

		$parent		= (int)$parent;
		if($parent == 0 || $id == 0 || $id == 1) {
			throw new Exception('Could not copy inside parent 0, or copy root nodes');
		}

		$parent		= $this->get_node($parent, array('with_children'=> true, 'with_path' => true));
		$id			= $this->get_node($id, array('with_children'=> true, 'deep_children' => true, 'with_path' => true, 'full' => true));
		$old_nodes	= $this->db->get("
			SELECT * FROM ".$this->options['structure_table']." /*TODO optimize without '*' */
			WHERE ".$this->options['structure']["left"]." > ".$id[$this->options['structure']["left"]]." AND ".$this->options['structure']["right"]." < ".$id[$this->options['structure']["right"]]."
			ORDER BY ".$this->options['structure']["left"]."
		");
		if(!$parent['children']) {
			$position = 0;
		}
		if($id[$this->options['structure']['parent_id']] == $parent[$this->options['structure']['id']] && $position > $id[$this->options['structure']['position']]) {
			//$position ++;
		}
		if($parent['children'] && $position >= count($parent['children'])) {
			$position = count($parent['children']);
		}

		//ED140608
		$oldName 	= $id['nm'];
		$oldPath 	= implode('/', array_map(function ($v) { return $v['nm']; }, $id['path']));

		// CHECK NAME
		// unique name only
		$id['nm'] = $this->get_unique_name($parent['id'], $id['nm']);

		$tmp = array();
		$tmp[] = (int)$id[$this->options['structure']["id"]];
		if($id['children'] && is_array($id['children'])) {
			foreach($id['children'] as $c) {
				$tmp[] = (int)$c[$this->options['structure']["id"]];
			}
		}
		$width = (int)$id[$this->options['structure']["right"]] - (int)$id[$this->options['structure']["left"]] + 1;

		$sql = array();

		// PREPARE NEW PARENT
		// update positions of all next elements
		$sql[] = "
			UPDATE ".$this->options['structure_table']."
				SET ".$this->options['structure']["position"]." = ".$this->options['structure']["position"]." + 1
			WHERE
				".$this->options['structure']["parent_id"]." = ".(int)$parent[$this->options['structure']['id']]." AND
				".$this->options['structure']["position"]." >= ".$position."
			";

		// update left indexes
		$ref_lft = false;
		if(!$parent['children']) {
			$ref_lft = $parent[$this->options['structure']["right"]];
		}
		else if(!isset($parent['children'][$position])) {
			$ref_lft = $parent[$this->options['structure']["right"]];
		}
		else {
			$ref_lft = $parent['children'][(int)$position][$this->options['structure']["left"]];
		}
		$sql[] = "
			UPDATE ".$this->options['structure_table']."
				SET ".$this->options['structure']["left"]." = ".$this->options['structure']["left"]." + ".$width."
			WHERE
				".$this->options['structure']["left"]." >= ".(int)$ref_lft."
			";
		// update right indexes
		$ref_rgt = false;
		if(!$parent['children']) {
			$ref_rgt = $parent[$this->options['structure']["right"]];
		}
		else if(!isset($parent['children'][$position])) {
			$ref_rgt = $parent[$this->options['structure']["right"]];
		}
		else {
			$ref_rgt = $parent['children'][(int)$position][$this->options['structure']["left"]] + 1;
		}
		$sql[] = "
			UPDATE ".$this->options['structure_table']."
				SET ".$this->options['structure']["right"]." = ".$this->options['structure']["right"]." + ".$width."
			WHERE
				".$this->options['structure']["right"]." >= ".(int)$ref_rgt."
			";

		// MOVE THE ELEMENT AND CHILDREN
		// left, right and level
		$diff = $ref_lft - (int)$id[$this->options['structure']["left"]];

		if($diff <= 0) { $diff = $diff - $width; }
		$ldiff = ((int)$parent[$this->options['structure']['level']] + 1) - (int)$id[$this->options['structure']['level']];

		// build all fields + data table
		$fields = array_combine($this->options['structure'], $this->options['structure']);
		unset($fields['id']);
		$fields[$this->options['structure']["left"]] = $this->options['structure']["left"]." + ".$diff;
		$fields[$this->options['structure']["right"]] = $this->options['structure']["right"]." + ".$diff;
		$fields[$this->options['structure']["level"]] = $this->options['structure']["level"]." + ".$ldiff;
		$sql[] = "
			INSERT INTO ".$this->options['structure_table']." ( ".implode(',',array_keys($fields))." )
			SELECT ".implode(',',array_values($fields))." FROM ".$this->options['structure_table']." WHERE ".$this->options['structure']["id"]." IN (".implode(",", $tmp).")
			ORDER BY ".$this->options['structure']["level"]." ASC";

		foreach($sql as $k => $v) {
			try {
				$this->db->query($v);
			} catch(Exception $e) {
				$this->reconstruct();
				throw new Exception('Error copying');
			}
		}
		$iid = (int)$this->db->insert_id();

		try {
			$this->db->query("
				UPDATE ".$this->options['structure_table']."
					SET ".$this->options['structure']["position"]." = ".$position.",
						".$this->options['structure']["parent_id"]." = ".(int)$parent[$this->options['structure']["id"]]."
					WHERE ".$this->options['structure']["id"]."  = ".$iid."
			");
		} catch(Exception $e) {
			$this->rm($iid);
			$this->reconstruct();
			throw new Exception('Could not update adjacency after copy');
		}

		// data2structure
		$fields = array_merge(
			$this->options['data']
			, $this->options['full']
		);
		unset($fields['id']);
		$update_fields = array();
		foreach($fields as $f) {
			$update_fields[] = $f.'=VALUES('.$f.')';
		}
		$update_fields = implode(',', $update_fields);
		if(count($fields)) {
			try {
				$this->db->query("
						INSERT INTO ".$this->options['data_table']." (".$this->options['data2structure'].",".implode(",",$fields).")
						SELECT ".$iid.",".implode(",",$fields)." FROM ".$this->options['data_table']." WHERE ".$this->options['data2structure']." = ".$id[$this->options['data2structure']]."
						ON DUPLICATE KEY UPDATE ".$update_fields."
				");
			}
			catch(Exception $e) {
				$this->rm($iid);
				$this->reconstruct();
				throw new Exception('Could not update data after copy');
			}
		}

		/* ED140726 renamed due to existing name */
		if($id['nm'] != $oldName){
			try {
				$this->db->query("
					UPDATE ".$this->options['data_table']."
						SET nm = ?
						WHERE ".$this->options['structure']["id"]."  = ?
					", array( $id['nm'], $iid ));
			} catch(Exception $e) {
				$this->rm($iid);
				$this->reconstruct();
				throw new Exception('Could not update adjacency after copy');
			}
		}

		//ED140518
		// copy node_param rows
		foreach($this->options['plugins'] as $key => $plugin){
			$plgFieldsAll = array_combine($plugin['structure'], $plugin['structure']);
			$plgFields = $plgFieldsAll;
			unset($plgFields['id']);
			try {
				$this->db->query("
					INSERT INTO ". $plugin['table'] ." (" . implode(',', $plgFieldsAll) . ")
					SELECT ?, " . implode(',', $plgFields) ."
					FROM ". $plugin['table'] . "
					WHERE id = ?
				", array($iid, $tmp[0]));
			}
			catch(Exception $e) {
				$this->rm($iid);
				$this->reconstruct();
				throw new Exception('Impossible de copier les propriétés "' . $key . '" du noeud : ' . $e->getMessage());
			}
		}
		// manually fix all parent_ids and copy all data
		$new_nodes = $this->db->get("
			SELECT * FROM ".$this->options['structure_table']."
			WHERE ".$this->options['structure']["left"]." > ".$ref_lft." AND ".$this->options['structure']["right"]." < ".($ref_lft + $width - 1)." AND ".$this->options['structure']["id"]." != ".$iid."
			ORDER BY ".$this->options['structure']["left"]."
		");
		$parents = array();
		foreach($new_nodes as $node) {
			if(!isset($parents[$node[$this->options['structure']["left"]]])) { $parents[$node[$this->options['structure']["left"]]] = $iid; }
			for($i = $node[$this->options['structure']["left"]] + 1; $i < $node[$this->options['structure']["right"]]; $i++) {
				$parents[$i] = $node[$this->options['structure']["id"]];
			}
		}
		$sql = array();
		foreach($new_nodes as $k => $node) {
			$sql[] = "
				UPDATE ".$this->options['structure_table']."
				SET ".$this->options['structure']["parent_id"]." = ".$parents[$node[$this->options['structure']["left"]]]."
				WHERE ".$this->options['structure']["id"]." = ".(int)$node[$this->options['structure']["id"]]."
			";
			if(count($fields)) {
				$sql[] = "
					INSERT INTO ".$this->options['data_table']." (".$this->options['data2structure'].",".implode(",",$fields).")
					SELECT ".(int)$node[$this->options['structure']["id"]].",".implode(",",$fields)." FROM ".$this->options['data_table']."
						WHERE ".$this->options['data2structure']." = ".$old_nodes[$k][$this->options['structure']['id']]."
					ON DUPLICATE KEY UPDATE ".$update_fields."
				";
			}
		}
		//ED140518
		// copy children node_param rows
		foreach($this->options['plugins'] as $key => $plugin){
			$plgFieldsAll = array_combine($plugin['structure'], $plugin['structure']);
			$plgFields = $plgFieldsAll;
			unset($plgFields['id']);
			foreach($new_nodes as $k => $node) {
				$sql[] = "
					INSERT INTO ". $plugin['table'] ." (" . implode(',', $plgFieldsAll) . ")
					SELECT " . (int)$node['id'] . ", " . implode(',', $plgFields) . "
					FROM ". $plugin['table'] . "
					WHERE id = ". $old_nodes[$k]['id'] ."
				";
				//var_dump($sql[count($sql)-1]);
			}
		}
		//var_dump($sql);
		foreach($sql as $k => $v) {
			try {
				$this->db->query($v);
			} catch(Exception $e) {
				echo 'Erreur durant la copie : ' . $e->getMessage() . '\nRequête : ' . $v;
				$this->rm($iid);
				$this->reconstruct();
				throw new Exception('Erreur durant la copie : ' . $e->getMessage() . '\nRequête : ' . $v);
			}
		}

		//ED140608
		$newNode	= $this->get_node($iid, array('with_children'=> false, 'deep_children' => false, 'with_path' => true, 'full' => false));
		$new_name 	= $newNode['nm'];
		$new_path 	= implode('/', array_map(function ($v) { return $v['nm']; }, $newNode['path']));
		helpers::nodeFile_cp($oldPath, $oldName, $new_path, $new_name, true);

		return $iid;
	}

	/* rm
		suppression
	*/
	public function rm($id) {
		$id = (int)$id;
		if(!$id || $id === 1) { throw new Exception('Could not create inside roots'); }
		$data = $this->get_node($id, array('with_path' => true, 'with_children' => true, 'deep_children' => true));
		$lft = (int)$data[$this->options['structure']["left"]];
		$rgt = (int)$data[$this->options['structure']["right"]];
		$pid = (int)$data[$this->options['structure']["parent_id"]];
		$pos = (int)$data[$this->options['structure']["position"]];
		$dif = $rgt - $lft + 1;

		$sql = array();

		//ED140608
		$oldName 	= $data['nm'];
		$oldPath 	= implode('/', array_map(function ($v) { return $v['nm']; }, $data['path']));

		//ED140518 : suppression préalable poour maintenir les contraintes
		// delete node_param rows
		foreach($this->options['plugins'] as $key => $plugin){
			$sql[] = "
				DELETE FROM ". $plugin['table'] . "
				WHERE id IN (
					SELECT id FROM ".$this->options['structure_table']."
					WHERE ".$this->options['structure']["left"]." >= ".(int)$lft." AND ".$this->options['structure']["right"]." <= ".(int)$rgt."
				)
			";
		}

		// deleting node and its children from structure
		$sql[] = "
			DELETE FROM ".$this->options['structure_table']."
			WHERE ".$this->options['structure']["left"]." >= ".(int)$lft." AND ".$this->options['structure']["right"]." <= ".(int)$rgt."
		";
		// shift left indexes of nodes right of the node
		$sql[] = "
			UPDATE ".$this->options['structure_table']."
				SET ".$this->options['structure']["left"]." = ".$this->options['structure']["left"]." - ".(int)$dif."
			WHERE ".$this->options['structure']["left"]." > ".(int)$rgt."
		";
		// shift right indexes of nodes right of the node and the node's parents
		$sql[] = "
			UPDATE ".$this->options['structure_table']."
				SET ".$this->options['structure']["right"]." = ".$this->options['structure']["right"]." - ".(int)$dif."
			WHERE ".$this->options['structure']["right"]." > ".(int)$lft."
		";
		// Update position of siblings below the deleted node
		$sql[] = "
			UPDATE ".$this->options['structure_table']."
				SET ".$this->options['structure']["position"]." = ".$this->options['structure']["position"]." - 1
			WHERE ".$this->options['structure']["parent_id"]." = ".$pid." AND ".$this->options['structure']["position"]." > ".(int)$pos."
		";
		// delete from data table
		if($this->options['data_table']) {
			$tmp = array();
			$tmp[] = (int)$data['id'];
			if($data['children'] && is_array($data['children'])) {
				foreach($data['children'] as $v) {
					$tmp[] = (int)$v['id'];
				}
			}
			$sql[] = "DELETE FROM ".$this->options['data_table']." WHERE ".$this->options['data2structure']." IN (".implode(',',$tmp).")";
		}

		// Execution des requetes
		foreach($sql as $v) {
			try {
				$this->db->query($v);
			} catch(Exception $e) {
				$this->reconstruct();
				throw new Exception('Could not remove');
			}
		}

		//ED140608
		helpers::nodeFile_rm($oldPath, $oldName);

		return true;
	}

	/* rename and update
	*/
	public function rn($id, $data, $fullUpdate = false) {
		//ED140608
		$node	= $this->get_node($id, array('with_children'=> false, 'deep_children' => false, 'with_path' => true, 'full' => false), false);
		if(!$node) {
			//throw new Exception('Could not rename non-existing node');
			$oldName 	= false;
		}
		else {
			$oldName 	= $node['nm'];
			$oldPath 	= implode('/', array_map(function ($v) { return $v['nm']; }, $node['path']));
		}
		if(isset($data['name']) && !isset($data['nm']))
			$data['nm'] = $data['name'];

		$tmp = array();
		foreach($this->options['data'] as $v) {
			if(isset($data[$v])) {
				$tmp[$v] = $data[$v];
			}
		}
		if($fullUpdate)
			foreach($this->options['full'] as $v) {
				if(isset($data[$v])) {
					$tmp[$v] = $data[$v];
				}
			}
		if(count($tmp) == 0)
			return true;

		// CHECK NAME
		// unique name only
		if(isset($tmp['nm']) && $tmp['nm'] != $oldName){
			$tmp['nm'] = $this->get_unique_name($node['path'][ count($node['path']) - 1 ]['id'], $data['nm'], $node['id']);
		}

		$tmp[$this->options['data2structure']] = $id;
		$sql = "
			INSERT INTO
				".$this->options['data_table']." (".implode(',', array_keys($tmp)).")
				VALUES(?".str_repeat(',?', count($tmp) - 1).")
			ON DUPLICATE KEY UPDATE
				".implode(' = ?, ', array_keys($tmp))." = ?";
		$par = array_merge(array_values($tmp), array_values($tmp));
		try {
			$this->db->query($sql, $par);
		}
		catch(Exception $e) {
			throw new Exception('Impossible de mettre à jour');
		}

		//ED140608
		$new_name 	= $tmp['nm'];
		if($oldName !== false && ($new_name != null) && ($new_name != $oldName)) {
			helpers::nodeFile_mv($oldPath, $oldName, $oldPath, $new_name, true);
		}

		return array(
			'id' => $id
			, 'nm' => $new_name
		);
	}

	public function analyze($get_errors = false) {
		$report = array();
		if((int)$this->db->one("SELECT COUNT(".$this->options['structure']["id"].") AS res FROM ".$this->options['structure_table']." WHERE ".$this->options['structure']["parent_id"]." = 0") !== 1) {
			$report[] = "No or more than one root node.";
		}
		if((int)$this->db->one("SELECT ".$this->options['structure']["left"]." AS res FROM ".$this->options['structure_table']." WHERE ".$this->options['structure']["parent_id"]." = 0") !== 1) {
			$report[] = "Root node's left index is not 1.";
		}
		if((int)$this->db->one("
			SELECT
				COUNT(".$this->options['structure']['id'].") AS res
			FROM ".$this->options['structure_table']." s
			WHERE
				".$this->options['structure']["parent_id"]." != 0 AND
				(SELECT COUNT(".$this->options['structure']['id'].") FROM ".$this->options['structure_table']." WHERE ".$this->options['structure']["id"]." = s.".$this->options['structure']["parent_id"].") = 0") > 0
		) {
			$report[] = "Missing parents.";
		}
		if(
			(int)$this->db->one("SELECT MAX(".$this->options['structure']["right"].") AS res FROM ".$this->options['structure_table']) / 2 !=
			(int)$this->db->one("SELECT COUNT(".$this->options['structure']["id"].") AS res FROM ".$this->options['structure_table'])
		) {
			$report[] = "Right index does not match node count.";
		}
		if(
			(int)$this->db->one("SELECT COUNT(DISTINCT ".$this->options['structure']["right"].") AS res FROM ".$this->options['structure_table']) !=
			(int)$this->db->one("SELECT COUNT(DISTINCT ".$this->options['structure']["left"].") AS res FROM ".$this->options['structure_table'])
		) {
			$report[] = "Duplicates in nested set.";
		}
		if(
			(int)$this->db->one("SELECT COUNT(DISTINCT ".$this->options['structure']["id"].") AS res FROM ".$this->options['structure_table']) !=
			(int)$this->db->one("SELECT COUNT(DISTINCT ".$this->options['structure']["left"].") AS res FROM ".$this->options['structure_table'])
		) {
			$report[] = "Left indexes not unique.";
		}
		if(
			(int)$this->db->one("SELECT COUNT(DISTINCT ".$this->options['structure']["id"].") AS res FROM ".$this->options['structure_table']) !=
			(int)$this->db->one("SELECT COUNT(DISTINCT ".$this->options['structure']["right"].") AS res FROM ".$this->options['structure_table'])
		) {
			$report[] = "Right indexes not unique.";
		}
		if(
			(int)$this->db->one("
				SELECT
					s1.".$this->options['structure']["id"]." AS res
				FROM ".$this->options['structure_table']." s1, ".$this->options['structure_table']." s2
				WHERE
					s1.".$this->options['structure']['id']." != s2.".$this->options['structure']['id']." AND
					s1.".$this->options['structure']['left']." = s2.".$this->options['structure']['right']."
				LIMIT 1")
		) {
			$report[] = "Nested set - matching left and right indexes.";
		}
		if(
			(int)$this->db->one("
				SELECT
					".$this->options['structure']["id"]." AS res
				FROM ".$this->options['structure_table']." s
				WHERE
					".$this->options['structure']['position']." >= (
						SELECT
							COUNT(".$this->options['structure']["id"].")
						FROM ".$this->options['structure_table']."
						WHERE ".$this->options['structure']['parent_id']." = s.".$this->options['structure']['parent_id']."
					)
				LIMIT 1") ||
			(int)$this->db->one("
				SELECT
					s1.".$this->options['structure']["id"]." AS res
				FROM ".$this->options['structure_table']." s1, ".$this->options['structure_table']." s2
				WHERE
					s1.".$this->options['structure']['id']." != s2.".$this->options['structure']['id']." AND
					s1.".$this->options['structure']['parent_id']." = s2.".$this->options['structure']['parent_id']." AND
					s1.".$this->options['structure']['position']." = s2.".$this->options['structure']['position']."
				LIMIT 1")
		) {
			$report[] = "Positions not correct.";
		}
		if((int)$this->db->one("
			SELECT
				COUNT(".$this->options['structure']["id"].") FROM ".$this->options['structure_table']." s
			WHERE
				(
					SELECT
						COUNT(".$this->options['structure']["id"].")
					FROM ".$this->options['structure_table']."
					WHERE
						".$this->options['structure']["right"]." < s.".$this->options['structure']["right"]." AND
						".$this->options['structure']["left"]." > s.".$this->options['structure']["left"]." AND
						".$this->options['structure']["level"]." = s.".$this->options['structure']["level"]." + 1
				) !=
				(
					SELECT
						COUNT(*)
					FROM ".$this->options['structure_table']."
					WHERE
						".$this->options['structure']["parent_id"]." = s.".$this->options['structure']["id"]."
				)")
		) {
			$report[] = "Adjacency and nested set do not match.";
		}
		if(
			$this->options['data_table'] &&
			(int)$this->db->one("
				SELECT
					COUNT(".$this->options['structure']["id"].") AS res
				FROM ".$this->options['structure_table']." s
				WHERE
					(SELECT COUNT(".$this->options['data2structure'].") FROM ".$this->options['data_table']." WHERE ".$this->options['data2structure']." = s.".$this->options['structure']["id"].") = 0
			")
		) {
			$report[] = "Missing records in data table.";
		}
		if(
			$this->options['data_table'] &&
			(int)$this->db->one("
				SELECT
					COUNT(".$this->options['data2structure'].") AS res
				FROM ".$this->options['data_table']." s
				WHERE
					(SELECT COUNT(".$this->options['structure']["id"].") FROM ".$this->options['structure_table']." WHERE ".$this->options['structure']["id"]." = s.".$this->options['data2structure'].") = 0
			")
		) {
			$report[] = "Dangling records in data table.";
		}
		return $get_errors ? $report : count($report) == 0;
	}

	public function reconstruct($analyze = true) {
		if($analyze && $this->analyze()) { return true; }

		if(!$this->db->query("" .
			"CREATE TEMPORARY TABLE temp_tree (" .
				"".$this->options['structure']["id"]." INTEGER NOT NULL, " .
				"".$this->options['structure']["parent_id"]." INTEGER NOT NULL, " .
				"". $this->options['structure']["position"]." INTEGER NOT NULL" .
			") "
		)) { return false; }
		if(!$this->db->query("" .
			"INSERT INTO temp_tree " .
				"SELECT " .
					"".$this->options['structure']["id"].", " .
					"".$this->options['structure']["parent_id"].", " .
					"".$this->options['structure']["position"]." " .
				"FROM ".$this->options['structure_table'].""
		)) { return false; }

		if(!$this->db->query("" .
			"CREATE TEMPORARY TABLE temp_stack (" .
				"".$this->options['structure']["id"]." INTEGER NOT NULL, " .
				"".$this->options['structure']["left"]." INTEGER, " .
				"".$this->options['structure']["right"]." INTEGER, " .
				"".$this->options['structure']["level"]." INTEGER, " .
				"stack_top INTEGER NOT NULL, " .
				"".$this->options['structure']["parent_id"]." INTEGER, " .
				"".$this->options['structure']["position"]." INTEGER " .
			") "
		)) { return false; }

		$counter = 2;
		if(!$this->db->query("SELECT COUNT(*) FROM temp_tree")) {
			return false;
		}
		$this->db->nextr();
		$maxcounter = (int) $this->db->f(0) * 2;
		$currenttop = 1;
		if(!$this->db->query("" .
			"INSERT INTO temp_stack " .
				"SELECT " .
					"".$this->options['structure']["id"].", " .
					"1, " .
					"NULL, " .
					"0, " .
					"1, " .
					"".$this->options['structure']["parent_id"].", " .
					"".$this->options['structure']["position"]." " .
				"FROM temp_tree " .
				"WHERE ".$this->options['structure']["parent_id"]." = 0"
		)) { return false; }
		if(!$this->db->query("DELETE FROM temp_tree WHERE ".$this->options['structure']["parent_id"]." = 0")) {
			return false;
		}

		while ($counter <= $maxcounter) {
			if(!$this->db->query("" .
				"SELECT " .
					"temp_tree.".$this->options['structure']["id"]." AS tempmin, " .
					"temp_tree.".$this->options['structure']["parent_id"]." AS pid, " .
					"temp_tree.".$this->options['structure']["position"]." AS lid " .
				"FROM temp_stack, temp_tree " .
				"WHERE " .
					"temp_stack.".$this->options['structure']["id"]." = temp_tree.".$this->options['structure']["parent_id"]." AND " .
					"temp_stack.stack_top = ".$currenttop." " .
				"ORDER BY temp_tree.".$this->options['structure']["position"]." ASC LIMIT 1"
			)) { return false; }

			if($this->db->nextr()) {
				$tmp = $this->db->f("tempmin");

				$q = "INSERT INTO temp_stack (stack_top, ".$this->options['structure']["id"].", ".$this->options['structure']["left"].", ".$this->options['structure']["right"].", ".$this->options['structure']["level"].", ".$this->options['structure']["parent_id"].", ".$this->options['structure']["position"].") VALUES(".($currenttop + 1).", ".$tmp.", ".$counter.", NULL, ".$currenttop.", ".$this->db->f("pid").", ".$this->db->f("lid").")";
				if(!$this->db->query($q)) {
					return false;
				}
				if(!$this->db->query("DELETE FROM temp_tree WHERE ".$this->options['structure']["id"]." = ".$tmp)) {
					return false;
				}
				$counter++;
				$currenttop++;
			}
			else {
				if(!$this->db->query("" .
					"UPDATE temp_stack SET " .
						"".$this->options['structure']["right"]." = ".$counter.", " .
						"stack_top = -stack_top " .
					"WHERE stack_top = ".$currenttop
				)) { return false; }
				$counter++;
				$currenttop--;
			}
		}

		$temp_fields = $this->options['structure'];
		unset($temp_fields["parent_id"]);
		unset($temp_fields["position"]);
		unset($temp_fields["left"]);
		unset($temp_fields["right"]);
		unset($temp_fields["level"]);
		if(count($temp_fields) > 1) {
			if(!$this->db->query("" .
				"CREATE TEMPORARY TABLE temp_tree2 " .
					"SELECT ".implode(", ", $temp_fields)." FROM ".$this->options['structure_table']." "
			)) { return false; }
		}
		if(!$this->db->query("TRUNCATE TABLE ".$this->options['structure_table']."")) {
			return false;
		}
		if(!$this->db->query("" .
			"INSERT INTO ".$this->options['structure_table']." (" .
					"".$this->options['structure']["id"].", " .
					"".$this->options['structure']["parent_id"].", " .
					"".$this->options['structure']["position"].", " .
					"".$this->options['structure']["left"].", " .
					"".$this->options['structure']["right"].", " .
					"".$this->options['structure']["level"]." " .
				") " .
				"SELECT " .
					"".$this->options['structure']["id"].", " .
					"".$this->options['structure']["parent_id"].", " .
					"".$this->options['structure']["position"].", " .
					"".$this->options['structure']["left"].", " .
					"".$this->options['structure']["right"].", " .
					"".$this->options['structure']["level"]." " .
				"FROM temp_stack " .
				"ORDER BY ".$this->options['structure']["id"].""
		)) {
			return false;
		}
		if(count($temp_fields) > 1) {
			$sql = "" .
				"UPDATE ".$this->options['structure_table']." v, temp_tree2 SET v.".$this->options['structure']["id"]." = v.".$this->options['structure']["id"]." ";
			foreach($temp_fields as $k => $v) {
				if($k == "id") continue;
				$sql .= ", v.".$v." = temp_tree2.".$v." ";
			}
			$sql .= " WHERE v.".$this->options['structure']["id"]." = temp_tree2.".$this->options['structure']["id"]." ";
			if(!$this->db->query($sql)) {
				return false;
			}
		}
		// fix positions
		$nodes = $this->db->get("SELECT ".$this->options['structure']['id'].", ".$this->options['structure']['parent_id']." FROM ".$this->options['structure_table']." ORDER BY ".$this->options['structure']['parent_id'].", ".$this->options['structure']['position']);
		$last_parent = false;
		$last_position = false;
		foreach($nodes as $node) {
			if((int)$node[$this->options['structure']['parent_id']] !== $last_parent) {
				$last_position = 0;
				$last_parent = (int)$node[$this->options['structure']['parent_id']];
			}
			$this->db->query("UPDATE ".$this->options['structure_table']." SET ".$this->options['structure']['position']." = ".$last_position." WHERE ".$this->options['structure']['id']." = ".(int)$node[$this->options['structure']['id']]);
			$last_position++;
		}
		if($this->options['data_table'] != $this->options['structure_table']) {
			// fix missing data records
			$this->db->query("
				INSERT INTO
					".$this->options['data_table']." (".implode(',',$this->options['data']).")
				SELECT ".$this->options['structure']['id']." ".str_repeat(", ".$this->options['structure']['id'], count($this->options['data']) - 1)."
				FROM ".$this->options['structure_table']." s
				WHERE (SELECT COUNT(".$this->options['data2structure'].") FROM ".$this->options['data_table']." WHERE ".$this->options['data2structure']." = s.".$this->options['structure']['id'].") = 0 "
			);
			// remove dangling data records
			$this->db->query("
				DELETE FROM
					".$this->options['data_table']."
				WHERE
					(SELECT COUNT(".$this->options['structure']['id'].") FROM ".$this->options['structure_table']." WHERE ".$this->options['structure']['id']." = ".$this->options['data_table'].".".$this->options['data2structure'].") = 0
			");
		}
		return true;
	}

	public function res($data = array()) {
		if(!$this->db->query("TRUNCATE TABLE ".$this->options['structure_table'])) { return false; }
		if(!$this->db->query("TRUNCATE TABLE ".$this->options['data_table'])) { return false; }
		$sql = "INSERT INTO ".$this->options['structure_table']." (".implode(",", $this->options['structure']).") VALUES (?".str_repeat(',?', count($this->options['structure']) - 1).")";
		$par = array();
		foreach($this->options['structure'] as $k => $v) {
			switch($k) {
				case 'id':
					$par[] = null;
					break;
				case 'left':
					$par[] = 1;
					break;
				case 'right':
					$par[] = 2;
					break;
				case 'level':
					$par[] = 0;
					break;
				case 'parent_id':
					$par[] = 0;
					break;
				case 'position':
					$par[] = 0;
					break;
				default:
					$par[] = null;
			}
		}
		if(!$this->db->query($sql, $par)) { return false; }
		$id = $this->db->insert_id();
		foreach($this->options['structure'] as $k => $v) {
			if(!isset($data[$k])) { $data[$k] = null; }
		}
		return $this->rn($id, $data);
	}

	public function dump() {
		$nodes = $this->db->get("
			SELECT
				s.".implode(", s.", $this->options['structure']).",
				d.".implode(", d.", $this->options['data'])." ,
				d.".implode(", d.", $this->options['full'])."
			FROM
				".$this->options['structure_table']." s,
				".$this->options['data_table']." d
			WHERE
				s.".$this->options['structure']['id']." = d.".$this->options['data2structure']."
			ORDER BY ".$this->options['structure']["left"]
		);
		echo "\n\n";
		foreach($nodes as $node) {
			echo str_repeat(" ",(int)$node[$this->options['structure']["level"]] * 2);
			echo $node[$this->options['structure']["id"]]." ".$node["nm"]." (".$node[$this->options['structure']["left"]].",".$node[$this->options['structure']["right"]].",".$node[$this->options['structure']["level"]].",".$node[$this->options['structure']["parent_id"]].",".$node[$this->options['structure']["position"]].")" . "\n";
		}
		echo str_repeat("-",40);
		echo "\n\n";
	}
}