<?php
/**
 * Membership
 *
 * Description…
 *
 * @author Jonathan Davis
 * @version 1.0
 * @copyright Ingenesis Limited, March 30, 2011
 * @license GNU GPL version 3 (or later) {@see license.txt}
 * @package shopp
 * @since 1.0
 * @subpackage membership
 **/

/**
 * Membership
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package membership
 **/
class Membership extends DatabaseObject  {
	static $table = 'meta';

	var $_table = false;	// Fully qualified table name
	var $_settings = array('role','continuity');

	var $stages = array();		// Loaded MemberStage(s) associated with this membership

	// Meta table properties
	var $id = false;
	var $parent = 0;			// Linking reference to the root record
	var $context= 'membership';	// The meta context
	var $type = 'memberplan';	// Type (class) of object
	var $name = '';


	/**
	 * MemberPlan constructor
	 *
	 * @author Jonathan Davis
	 *
	 * @return void
	 **/
	function __construct ($id=false,$key='id') {
		$this->init(self::$table);

		// Packed object settings
		$this->role = 'subscriber';
		$this->continuity = 'off';

		$this->load($id,$key);
	}

}


/**
 * MemberPlan
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package membership
 **/
class MemberPlan extends DatabaseObject  {
	static $table = 'meta';

	var $_table = false;	// Fully qualified table name
	var $_settings = array('role','continuity');
	var $stages = array();		// Loaded MemberStage(s) associated with this membership

	// Meta table properties
	var $id = false;
	var $parent = 0;			// Linking reference to the root record
	var $context= 'membership';	// The meta context
	var $type = 'memberplan';	// Type (class) of object
	var $name = '';


	/**
	 * MemberPlan constructor
	 *
	 * @author Jonathan Davis
	 *
	 * @return void
	 **/
	function __construct ($id=false,$key='id') {
		$this->init(self::$table);

		// Packed object settings
		$this->role = 'subscriber';
		$this->continuity = 'off';

		$this->load($id,$key);
	}

	function load_stages () {
		$db = DB::get();
		$StageLoader = new MemberStage();
		$query = "SELECT * FROM $StageLoader->_table
					WHERE parent='$this->id'
						AND context='$StageLoader->context'
						AND type='$StageLoader->type'
					ORDER BY sortorder";
		$this->stages = $db->query($query,'array', array($StageLoader,'loader'));
	}

	function load_access ($stage=false) {
		$db = DB::get();
		$AccessLoader = new MemberAccess();
		if (!$stage) {
			if (empty($this->stages)) $this->load_stages();
			$stageids = array_keys($this->stages);
			$parent = "0 < FIND_IN_SET(parent,'".join(',',$stageids)."')";
		} else $parent = "parent='$stage'";

		$query = "SELECT * FROM $AccessLoader->_table
					WHERE $parent
						AND context='$AccessLoader->context'
						AND type='$AccessLoader->type'";
		$this->access = $db->query($query,'array', array($this,'map_stageaccess'));
	}

	// function load_content ($access=false) {
	// 	$db = DB::get();
	// 	$ContentLoader = new MemberContent();
	// 	if (!$access) {
	// 		if (empty($this->access)) $this->load_access();
	// 		// $stageids = array_keys($this->stages);
	// 		// $parent = "0 < FIND_IN_SET(parent,'".join(',',$stageids)."')";
	// 	} else $taxonomy = "taxonomy='$access'";
	//
	// 	$query = "SELECT * FROM $ContentLoader->_table WHERE $taxonomy";
	// 	$this->content = $db->query($query,'array', array($this,'map_stageaccess'));
	// }

	/**
	 * Saves updates or creates records for the defined object properties
	 *
	 * @author Jonathan Davis
	 * @since 1.2
	 *
	 * @return void
	 **/
	function save () {

		$this->value = array();
		foreach ($this->_settings as $property) {
			if (!isset($this->$property)) continue;
			$this->value[$property] = $this->$property;
		}

		parent::save();
	}

	/**
	 * Deletes the entire set of meta entries for the combined record
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return boolean
	 **/
	function delete () {
		if (empty($this->id)) return;
		$db = DB::get();

		// @todo Delete all catalog entries related to this
		// $catalog = DatabaseObject::tablename(Catalog::$table);
		// $db->query("DELETE FROM $this->_table WHERE parent='$id' AND context='membership'");

		// Delete all meta data related to this entry
		$db->query("DELETE FROM $this->_table WHERE parent='$this->id' AND context='membership'");

		parent::delete();
	}

	function populate ($data) {
		// Populate normally
		parent::populate($data);

		// Remap values data to real properties
		$values = $this->value;
		foreach ($values as $property => $data)
			$this->$property = stripslashes_deep($data);
		unset($this->value);
	}

	function map_stageaccess (&$records,&$record) {
		if (!isset($this->stages[$record->parent])) return;
		$Access = new MemberAccess();
		$Access->populate($record);
		if (!isset($this->stages[$Access->parent]->rules))
			$this->stages[$Access->parent]->rules = array();
		if (!isset($this->stages[$Access->parent]->rules[$Access->name]))
		$this->stages[$Access->parent]->rules[$Access->name] = array();
		$this->stages[$Access->parent]->rules[$Access->name][] = $Access;
		$records[$record->id] = $Access;
	}

	function _ignore_ ($property) {
		return ($property[0] != "_");
	}

} // END class MemberPlan

/**
 * MemberStage
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package membership
 **/
class MemberStage extends MemberPlan  {
	static $table = 'meta';

	var $_table = false;	// Fully qualified table name
	var $_settings = array('advance','interval','period','content');

	// Meta table properties
	var $id = false;
	var $parent = false;		// id of parent membership record
	var $context= 'membership';	// The meta context
	var $type = 'stage';		// Type (class) of object
	var $name = '';				//
	var $value = '';			//
	var $sortorder = 0;			//

	// Packed object settings
	var $content = array();
	var $advance = 'off';
	var $interval = 1;
	var $period = 'd';

	function __construct ($membership=false,$stage=false) {
		$this->init(self::$table);
		unset($this->stages);
		if ($membership && $stage) {
			$this->load(array(
				'id' => $stage,
				'parent' => $membership,
				'context' => $this->context,
				'type' => $this->type
			));
			$this->parent = $membership;
		}
	}

}

/**
 * MemberAccess
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package membership
 **/
class MemberAccess extends DatabaseObject  {
	static $table = 'meta';

	var $_table = false;	// Fully qualified table name
	var $_rules = array('allow','deny','allow-all','deny-all');

	// Meta table properties
	var $id = false;
	var $parent = false;		// id of parent membership stage record
	var $context= 'membership';	// The meta context
	var $type = 'taxonomy';		// Type (class) of object
	var $name = '';				// Target content source name (wp_posts,shopp_products)
	var $value = '';			// Access setting (allow/deny)

	function __construct ($memberstage=false,$content=false,$rule=false) {
		$this->init(self::$table);

		if ($rule !== false && !in_array($rule,$this->_rules)) {
			if (class_exists('ShoppError'))
				return new ShoppError('Invalid membership access rule specified (must use one of "allow" or "deny").','membership_rule_warning',SHOPP_DEBUG_ERR);
		}

		$this->load(array(
			'parent' => $memberstage,
			'context' => $this->context,
			'type' => $this->type,
			'name' => $content,
			'value' => $rule
		));
		$this->parent = $memberstage;
		$this->name = $content;
		$this->value = $rule;
	}

}


/**
 * MemberContent
 *
 * @author Jonathan Davis
 * @since 1.2
 * @package membership
 **/
class MemberContent extends DatabaseObject  {
	static $table = 'catalog';

	function __construct ($content=false,$access=false,$stage=false) {

		$this->init(self::$table);
		$this->load(array(
			'product' => $content,
			'taxonomy' => $access,
			'parent' => $stage
		));
		$this->product = $content;
		$this->taxonomy = $access;
		$this->parent = $stage;
	}

}


?>