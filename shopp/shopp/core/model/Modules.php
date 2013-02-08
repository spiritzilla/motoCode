<?php
/**
 * Modules.php
 *
 * Controller and framework classes for Shopp modules
 *
 * @author Jonathan Davis
 * @version 1.0
 * @copyright Ingenesis Limited, January 15, 2010
 * @license GNU GPL version 3 (or later) {@see license.txt}
 * @package shopp
 * @subpackage modules
 **/

/**
 * ModuleLoader
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 * @subpackage modules
 **/
abstract class ModuleLoader {

	protected $loader = 'ModuleFile'; // Module File load manager

	var $legacy = array();		// Legacy module checksums
	var $modules = array();		// Installed available modules
	var $activated = array();	// List of selected modules to be activated
	var $active = array();		// Instantiated module objects
	var $path = false;			// Source path for target module files

	/**
	 * Indexes the install module files
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	function installed () {
		if (!is_dir($this->path)) return false;

		$path = $this->path;
		$files = array();
		find_files(".php",$path,$path,$files);
		if (empty($files)) return $files;

		foreach ($files as $file) {
			// Skip if the file can't be read or isn't a real file at all
			if (!is_readable($path.$file) && !is_dir($path.$file)) continue;
			// Add the module file to the registry
			$Loader = $this->loader;
			$module = new $Loader($path,$file);
			if ($module->addon) $this->modules[$module->subpackage] = $module;
			else $this->legacy[] = md5_file($path.$file);
		}

	}

	/**
	 * Loads the activated module files
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param boolean $all Loads all installed modules instead
	 * @return void
	 **/
	function load ($all=false) {
		if ($all) $activate = array_diff( array_keys($this->modules), $this->activated );
		else $activate = $this->activated;

		foreach ($activate as $module) {
			// Module isn't available, skip it
			if (!isset($this->modules[$module])) continue;
			// Load the file
			$this->active[$module] = $this->modules[$module]->load();
			if (function_exists('do_action_ref_array')) do_action_ref_array('shopp_module_loaded',array($module));
		}
		if (function_exists('do_action')) do_action('shopp_'.strtolower(get_class($this)).'_loaded');
	}

	/**
	 * Hashes module files
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return array List of checksums
	 **/
	function checksums () {
		$hashes = array();
		foreach ($this->modules as $module) $hashes[] = md5_file($module->file);
		if (!empty($this->legacy)) $hashes = array_merge($hashes,$this->legacy);
		return $hashes;
	}


} // END class ModuleLoader

/**
 * ModuleFile class
 *
 * Manages a module file
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 * @subpackage modules
 **/
class ModuleFile {

	var $file = false;			// The full path to the file
	var $filename = false;		// The name of the file
	var $name = false;			// The proper name of the module
	var $description = false;	// A description of the module
	var $subpackage = false;	// The class name of the module
	var $version = false;		// The version of the module
	var $since = false;			// The core version required
	var $addon = false;			// The valid addon flag

	/**
	 * Parses the module file meta data and validates it
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param string $path The directory the file lives in
	 * @param string $file The file name
	 * @return void
	 **/
	function __construct ($path,$file) {
		if (!is_readable($path.$file)) return;

		$this->filename = $file;
		$this->file = $path.$file;
		$meta = $this->readmeta($this->file);

		if ($meta) {
			$meta = preg_replace('/\r\n/',"\n",$meta); // Normalize line endings
			$lines = explode("\n",substr($meta,1));
			foreach($lines as $line) {
				preg_match("/^(?:[\s\*]*?\b([^@\*\/]*))/",$line,$match);
				if (!empty($match[1])) $data[] = $match[1];

				preg_match("/^(?:[\s\*]*?@([^\*\/]+?)\s(.+))/",$line,$match);
				if (!empty($match[1]) && !empty($match[2])) $tags[$match[1]] = $match[2];
			}

			$this->name = $data[0];
			$this->description = (!empty($data[1]))?$data[1]:"";

			foreach ($tags as $tag => $value)
				$this->{$tag} = trim($value);
		}
		if ($this->valid() !== true) return;
		$this->addon = true;

	}

	/**
	 * Loads the module file and instantiates the module
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	function load () {
		if (!$this->addon) return;
		if (!class_exists($this->subpackage)) include($this->file);
		return new $this->subpackage();
	}

	/**
	 * Determines if the module is a valid and compatible Shopp module
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void
	 **/
	function valid () {
		if (empty($this->version) || empty($this->since) || empty($this->subpackage))
			return new ShoppError(sprintf(
				__('%s could not be loaded because the file descriptors are incomplete.','Shopp'),
				$this->name),
				'addon_missing_meta',SHOPP_ADDON_ERR);

		if (!defined('SHOPP_VERSION')) return true;
		$coreversion = '/^([\d\.])\b.*?$/';
		$shopp = preg_replace($coreversion,"$1",SHOPP_VERSION);
		$since = preg_replace($coreversion,"$1",$this->since);
		if (version_compare($shopp,$since) == -1)
			return new ShoppError(sprintf(
				__('%s could not be loaded because it requires version %s (or higher) of Shopp.','Shopp'),
				$this->name, $this->since),
				'addon_core_version',SHOPP_ADDON_ERR);
		return true;
	}

	/**
	 * Read the file docblock for Shopp addons
	 *
	 * @author Jonathan Davis
	 * @since 1.0
	 *
	 * @param string $file The target file
	 * @return string The meta block from the file
	 **/
	function readmeta ($file) {
		if (!file_exists($file)) return false;
		if (!is_readable($file)) return false;

		$meta = false;
		$string = "";

		$f = @fopen($file, "r");
		if (!$f) return false;
		while (!feof($f)) {
			$buffer = fgets($f,80);
			if (preg_match("/\/\*/",$buffer)) $meta = true;
			if ($meta) $string .= $buffer;
			if (preg_match("/\*\//",$buffer)) break;
		}
		fclose($f);

		return $string;
	}


} // END class ModuleFile

/**
 * ModuleSettingsUI class
 *
 * Provides a PHP interface for building JavaScript based module setting
 * widgets using the ModuleSetting Javascript class.
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 **/
class ModuleSettingsUI {

	var $module;
	var $name;
	var $label;
	var $markup = array(
		array(),array(),array()
	);
	var $script = '';

	/**
	 * Registers a new module setting interface
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @return void Description...
	 **/
	function __construct ($Module,$name) {
		$this->name = $name;
		$this->module = $Module->module;
		$this->id = sanitize_title_with_dashes($this->module);
		$this->label = isset($Module->settings['label'])?$Module->settings['label']:$name;
	}

	function generate () {

		$_ = array();
		$_[] = '<tr><td colspan="5">';
		$_[] = '<table class="form-table shopp-settings"><tr>';

		foreach ($this->markup as $markup) {
			$_[] = '<td>';
			if (empty($markup)) $_[] = '&nbsp;';
			else $_[] = join('',$markup);
			$_[] = '</td>';
		}

		$_[] = '</tr></table>';
		$_[] = '</td></tr>';

		return join('',$_);

	}

	function template () {
		$_ = array('<script id="'.$this->id.'-editor" type="text/x-jquery-tmpl">');
		$_[] = $this->generate();
		$_[] = '</script>';

		echo join('',$_)."\n\n";
	}

	function ui ($markup,$column=0) {
		if (!isset($this->markup[$column])) $this->markup[$column] = array();
		$this->markup[$column][] = $markup;
	}

	/**
	 * Renders a checkbox input
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param int $column The table column to add the element to
	 * @param array $attributes Element attributes; use 'checked' to set whether the element is toggled on or not
	 *
	 * @return void
	 **/
	function checkbox ($column=0,$attributes=array()) {
		$defaults = array(
			'label' => '',
			'type' => 'checkbox',
			'normal' => 'off',
			'value' => 'on',
			'checked' => false,
			'class' => '',
		);
		$attributes = array_merge($defaults,$attributes);
		$attributes['checked'] = (value_is_true($attributes['checked'])?true:false);
		extract($attributes);
		$id = "{$this->id}-".sanitize_title_with_dashes($name);
		if (!empty($class)) $class = ' class="'.esc_attr($class).'"';

		$this->ui('<div><label for="'.$id.'">',$column);
		$this->ui('<input type="hidden" name="settings['.$this->module.']['.$name.']" value="'.$normal.'" id="'.$id.'-default" />',$column);
		$this->ui('<input type="'.$type.'" name="settings['.$this->module.']['.$name.']" value="'.$value.'"'.$class.' id="'.$id.'"'.($checked?' checked="checked"':'').' />',$column);
		if (!empty($label)) $this->ui('&nbsp;'.$label,$column);
		$this->ui('</label></div>',$column);

	}

	/**
	 * Renders a drop-down menu element
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param int $column The table column to add the element to
	 * @param array $attributes Element attributes; use 'selected' to set the selected option
	 * @param array $options The available options in the menu
	 *
	 * @return void
	 **/
	function menu ($column=0,$attributes=array(),$options=array()) {
		$defaults = array(
			'label' => '',
			'selected' => '',
			'keyed' => false
		);
		$attributes = array_merge($defaults,$attributes);
		extract($attributes);
		$id = "{$this->id}-".sanitize_title_with_dashes($name);

		$this->ui('<div>',$column);
		$this->ui('<select name="settings['.$this->module.']['.$name.']" id="'.$id.'"'.inputattrs($attributes).'>',$column);

		if (is_array($options)) {
			foreach ($options as $val => $option) {
				$value = $keyed?' value="'.$val.'"':'';
				$select = ($selected == (string)$val || $selected == $option)?' selected="selected"':'';
				$this->ui('<option'.$value.$select.'>'.$option.'</option>',$column);
			}
		}
		$this->ui('</select>',$column);
		if (!empty($label)) $this->ui('<br /><label for="'.$id.'">'.$label.'</label>',$column);
		$this->ui('</div>',$column);

	}

	/**
	 * Renders a multiple-select widget
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param int $column The table column to add the element to
	 * @param array $attributes Element attributes; pass a 'selected' attribute as an array to set the selected options
	 * @param array $options The available options in the menu
	 *
	 * @return void
	 **/
	function multimenu ($column=0,$attributes=array(),$options=array()) {

		$defaults = array(
			'label' => '',
			'selected' => array(),
			'disabled' => array(),
			'readonly' => array(),
			'class' => ''
		);
		$attributes = array_merge($defaults,$attributes);
		$attributes['id'] = "{$this->id}-{$attributes['name']}";
		extract($attributes);


		$classes = empty($class)?'':' class="'.$class.'"';

		$this->ui('<div'.$classes.'><div class="multiple-select">',$column);
		$this->ui('<ul '.inputattrs($attributes).'>',$column);
		if (is_array($options)) {
			$checked = '';
			$alt = false;
			$this->ui('<li class="hide-if-no-js"><input type="checkbox" name="select-all" id="'.$id.'-select-all" class="selectall-toggle" /><label for="'.$id.'-select-all"><strong>'.__('Select All','Shopp').'</strong></label></li>',$column);
			foreach ($options as $key => $l) {
				$attrs = '';
				$boxid = $id.'-'.sanitize_title_with_dashes($key);

				if (in_array($key,(array)$selected)) $attrs .= ' checked="checked"';
				if (in_array($key,(array)$disabled)) $attrs .= ' disabled="disabled"';
				if (in_array($key,(array)$readonly)) $attrs .= ' readonly="readonly"';

				$this->ui('<li'.($alt = !$alt?' class="odd"':'').'><input type="checkbox" name="settings['.$this->module.']['.$name.'][]" value="'.$key.'" id="'.$boxid.'"'.$attrs.' /><label for="'.$boxid.'">'.$l.'</label></li>',$column);
			}
		}
		$this->ui('</ul></div>',$column);
		if (!empty($label)) $this->ui('<br /><label for="'.$id.'">'.$label.'</label>',$column);
		$this->ui('</div>',$column);

	}

	/**
	 * Renders a text input
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param int $column The table column to add the element to
	 * @param array $attributes Element attributes; requires a 'name' attribute
	 *
	 * @return void
	 **/
	function input ($column=0,$attributes=array()) {
		$defaults = array(
			'type' => 'hidden',
			'label' => '',
			'readonly' => false,
			'value' => '',
			'size' => 20,
			'class' => ''
		);
		$attributes = array_merge($defaults,array_filter($attributes));
		$attributes['id'] = "{$this->id}-".sanitize_title_with_dashes($attributes['name']);
		extract($attributes);

		$this->ui('<div>',$column);
		$this->ui('<input type="'.$type.'" name="settings['.$this->module.']['.$name.']" id="'.$id.'"'.inputattrs($attributes).' />',$column);
		if (!empty($label)) $this->ui('<br /><label for="'.$id.'">'.$label.'</label>',$column);
		$this->ui('</div>',$column);
	}

	/**
	 * Renders a password input
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param int $column The table column to add the element to
	 * @param array $attributes Element attributes; requires a 'name' attribute
	 *
	 * @return void
	 **/
	function text ($column=0,$attributes=array()) {
		$attributes['type'] = 'text';
		$this->input($column,$attributes);
	}

	/**
	 * Renders a password input
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param int $column The table column to add the element to
	 * @param array $attributes Element attributes; requires a 'name' attribute
	 *
	 * @return void
	 **/
	function password ($column=0,$attributes=array()) {
		$attributes['type'] = 'password';
		$this->input($column,$attributes);
	}

	/**
	 * Renders a hidden input
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param int $column The table column to add the element to
	 * @param array $attributes Element attributes; requires a 'name' attribute
	 *
	 * @return void
	 **/
	function hidden ($column=0,$attributes=array()) {
		$attributes['type'] = 'hidden';
		$this->input($column,$attributes);
	}

	/**
	 * Renders a text input
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param int $column The table column to add the element to
	 * @param array $attributes Element attributes; requires a 'name' attribute
	 *
	 * @return void
	 **/
	function textarea ($column=0,$attributes=array()) {
		$defaults = array(
			'label' => '',
			'readonly' => false,
			'value' => '',
			'cols' => 30,
			'rows' => 3,
			'class' => '',
			'id' => ''
		);
		$attributes = array_merge($defaults,$attributes);
		if (!empty($attributes['id']))
			$attributes['id'] = "{$this->id}-".sanitize_title_with_dashes($attributes['id']);
		extract($attributes);

		$this->ui('<div><textarea name="settings['.$this->module.']['.$name.']" '.inputattrs($attributes).'>'.esc_html($value).'</textarea>',$column);
		if (!empty($label)) $this->ui('<br /><label for="'.$id.'">'.$label.'</label>',$column);
		$this->ui('</div>',$column);
	}


	/**
	 * Renders a styled button element
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param int $column The table column to add the element to
	 * @param array $attributes Element attributes; requires a 'name' attribute
	 *
	 * @return void
	 **/
	function button ($column=0,$attributes=array()) {
		$defaults = array(
			'type' => 'button',
			'label' => '',
			'disabled' => false,
			'content' =>__('Button','Shopp'),
			'value' => '',
			'class' => ''
		);
		$attributes = array_merge($defaults,$attributes);
		$attributes['id'] = "{$this->id}-".sanitize_title_with_dashes($attributes['name']);
		$attributes['class'] = 'button-secondary'.('' == $attributes['class']?'':' '.$attributes['class']);
		extract($attributes);

		$this->ui('<button type="'.$type.'" name="'.$name.'" id="'.$id.'"'.inputattrs($attributes).'>'.$content.'</button>',$column);
		if (!empty($label)) $this->ui('<br /><label for="'.$id.'">'.$label.'</label>',$column);

	}

	/**
	 * Renders a paragraph element
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 *
	 * @param int $column The table column to add the element to
	 * @param array $attributes Element attributes; requires a 'name' attribute
	 *
	 * @return void
	 **/
	function p ($column=0,$attributes=array()) {
		$defaults = array(
			'id' => '',
			'label' => '',
			'content' => '',
			'class' => ''
		);
		$attributes = array_merge($defaults,$attributes);
		if (!empty($attributes['id']))
			$attributes['id'] = " id=\"{$this->id}-".sanitize_title_with_dashes($attributes['id'])."\"";
		extract($attributes);

		if (!empty($class)) $class = ' class="'.$class.'"';

		if (!empty($label)) $label = '<p><label><strong>'.$label.'</strong></label></p>';
		$this->ui('<div'.$id.$class.'>'.$label.$content.'</div>',$column);
	}

	function behaviors ($script) {
		shopp_custom_script('shopp',$script);
	}

} // END class ModuleSettingsUI

?>