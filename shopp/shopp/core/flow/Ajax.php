<?php
/**
 * Ajax.php
 *
 * Handles AJAX calls from Shopp interfaces
 *
 * @author Jonathan Davis
 * @version 1.0
 * @copyright Ingenesis Limited, February  6, 2010
 * @license GNU GPL version 3 (or later) {@see license.txt}
 * @package shopp
 * @subpackage ajax
 **/

/**
 * AjaxFlow
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 **/
class AjaxFlow {

	/**
	 * Ajax constructor
	 *
	 * @author Jonathan Davis
	 *
	 * @return void
	 **/
	function __construct () {

		if (isset($_POST['action']) && 'add-menu-item' == $_POST['action']) {
			// Boot the Admin controller to handle AJAX added WP nav menu items
			if (!class_exists('AdminFlow')) require(SHOPP_FLOW_PATH."/Admin.php");
			new AdminFlow();
		}

		// Flash uploads require unprivileged access
		add_action('wp_ajax_nopriv_shopp_upload_image',array($this,'upload_image'));
		add_action('wp_ajax_nopriv_shopp_upload_file',array($this,'upload_file'));
		add_action('wp_ajax_shopp_upload_image',array($this,'upload_image'));
		add_action('wp_ajax_shopp_upload_file',array($this,'upload_file'));

		// Actions that can happen on front end whether or not logged in
		add_action('wp_ajax_nopriv_shopp_ship_costs',array($this,'shipping_costs'));
		add_action('wp_ajax_shopp_ship_costs',array($this,'shipping_costs'));

		// Below this line must have nonce protection (all admin ajax go below)
		if (!isset($_REQUEST['_wpnonce'])) return;

		add_action('wp_ajax_shopp_category_products',array($this,'category_products'));
		add_action('wp_ajax_shopp_order_receipt',array($this,'receipt'));
		add_action('wp_ajax_shopp_category_children',array($this,'category_children'));
		add_action('wp_ajax_shopp_category_order',array($this,'category_order'));
		add_action('wp_ajax_shopp_category_products_order',array($this,'products_order'));
		add_action('wp_ajax_shopp_country_zones',array($this,'country_zones'));
		add_action('wp_ajax_shopp_spec_template',array($this,'load_spec_template'));
		add_action('wp_ajax_shopp_options_template',array($this,'load_options_template'));
		add_action('wp_ajax_shopp_add_category',array($this,'add_category'));
		add_action('wp_ajax_shopp_edit_slug',array($this,'edit_slug'));
		add_action('wp_ajax_shopp_order_note_message',array($this,'order_note_message'));
		add_action('wp_ajax_shopp_activate_key',array($this,'activate_key'));
		add_action('wp_ajax_shopp_deactivate_key',array($this,'deactivate_key'));
		add_action('wp_ajax_shopp_rebuild_search_index',array($this,'rebuild_search_index'));
		add_action('wp_ajax_shopp_upload_local_taxes',array($this,'upload_local_taxes'));
		add_action('wp_ajax_shopp_feature_product',array($this,'feature_product'));
		add_action('wp_ajax_shopp_update_inventory',array($this,'update_inventory'));
		add_action('wp_ajax_shopp_import_file',array($this,'import_file'));
		add_action('wp_ajax_shopp_storage_suggestions',array($this,'storage_suggestions'),11);
		add_action('wp_ajax_shopp_suggestions',array($this,'suggestions'));
		add_action('wp_ajax_shopp_verify_file',array($this,'verify_file'));
		add_action('wp_ajax_shopp_gateway',array($this,'gateway_ajax'));
		add_action('wp_ajax_shopp_debuglog',array($this,'logviewer'));

	}

	function receipt () {
		check_admin_referer('wp_ajax_shopp_order_receipt');
		if (0 == intval($_GET['id'])) die('-1');

		ShoppPurchase( new Purchase((int)$_GET['id']));

		echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\"
			\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\">
		<html><head><title>".get_bloginfo('name').' &mdash; '.__('Order','Shopp').' #'.shopp('purchase','get-id')."</title>";
			echo '<style type="text/css">body { padding: 20px; font-family: Arial,Helvetica,sans-serif; }</style>';
			echo "<link rel='stylesheet' href='".shopp_template_url('shopp.css')."' type='text/css' />";
		echo "</head><body>";
		echo apply_filters('shopp_admin_order_receipt',shopp('purchase','get-receipt','template=receipt-admin.php'));
		if (isset($_GET['print']) && $_GET['print'] == 'auto')
			echo '<script type="text/javascript">window.onload = function () { window.print(); window.close(); }</script>';
		echo "</body></html>";
		exit();
	}

	function category_menu () {
		check_admin_referer('wp_ajax_shopp_category_menu');

		require(SHOPP_MODEL_PATH."/Collection.php");
		require(SHOPP_FLOW_PATH."/Categorize.php");
		$Categorize = new Categorize();
		echo '<option value="">Select a category&hellip;</option>';
		echo '<option value="catalog-products">All Products</option>';
		echo $Categorize->menu();
		exit();
	}

	function category_products () {
		check_admin_referer('wp_ajax_shopp_category_products');
		if (!isset($_GET['category'])) return;
		$category = $_GET['category'];
		require(SHOPP_FLOW_PATH."/Warehouse.php");
		$Warehouse = new Warehouse();
		echo $Warehouse->category($category);
		exit();
	}

	function country_zones () {
		check_admin_referer('wp_ajax_shopp_country_zones');
		$zones = Lookup::country_zones();
		if (isset($_GET['country']) && isset($zones[$_GET['country']]))
			echo json_encode($zones[$_GET['country']]);
		else echo json_encode(false);
		exit();
	}

	function load_spec_template () {
		check_admin_referer('wp_ajax_shopp_spec_template');

		$Category = new ProductCategory((int)$_GET['category']);
		$Category->load_meta();

		echo json_encode($Category->specs);
		exit();
	}

	function load_options_template() {
		check_admin_referer('wp_ajax_shopp_options_template');

		$Category = new ProductCategory((int)$_GET['category']);
		$Category->load_meta();

		$result = new stdClass();
		$result->options = $Category->options;
		$result->prices = $Category->prices;

		echo json_encode($result);
		exit();
	}

	function upload_image () {
		require(SHOPP_FLOW_PATH."/Warehouse.php");
		$Warehouse = new Warehouse();
		echo $Warehouse->images();
		exit();
	}

	function upload_file () {
		require(SHOPP_FLOW_PATH."/Warehouse.php");
		$Warehouse = new Warehouse();
		echo $Warehouse->downloads();
		exit();
	}

	function add_category () {
		// Add a category in the product editor
		check_admin_referer('wp_ajax_shopp_add_category');
		if (empty($_GET['name'])) die(0);

		$Catalog = new Catalog();
		$Catalog->load_categories();

		$Category = new ProductCategory();
		$Category->name = $_GET['name'];
		$Category->slug = sanitize_title_with_dashes($Category->name);
		$Category->parent = $_GET['parent'];

		// Work out pathing
		$paths = array();
		if (!empty($Category->slug)) $paths = array($Category->slug);  // Include self

		$parentkey = -1;
		// If we're saving a new category, lookup the parent
		if ($Category->parent > 0) {
			array_unshift($paths,$Catalog->categories[$Category->parent]->slug);
			$parentkey = $Catalog->categories[$Category->parent]->parent;
		}

		while ($category_tree = $Catalog->categories[$parentkey]) {
			array_unshift($paths,$category_tree->slug);
			$parentkey = $category_tree->parent;
		}

		if (count($paths) > 1) $Category->uri = join("/",$paths);
		else $Category->uri = $paths[0];

		$Category->save();
		echo json_encode($Category);
		exit();

	}

	function edit_slug () {
		check_admin_referer('wp_ajax_shopp_edit_slug');

		$defaults = array(
			'slug' => false,
			'type' => false,
			'id' => false
		);
		$p = array_merge($defaults,$_POST);
		extract($p);

		if (!$slug) die('-1');

		switch ($type) {
			case "category":
				$Category = new ProductCategory($_POST['id']);
				if ($slug == $Category->slug) die('-1');
				$term = get_term($Category->id,$Category->taxonomy);
				$slug = wp_unique_term_slug(sanitize_title_with_dashes($slug),$term);
				if ($slug == $Category->slug) die('-1');
				$Category->slug = $slug;
				$Category->save();
				echo apply_filters('editable_slug',$Category->slug);
				break;
			case "product":
				$Product = new Product($_POST['id']);
				if ($slug == $Product->slug) die('-1'); // Same as before? Nothing to do so bail

				$Product->slug = wp_unique_post_slug(sanitize_title_with_dashes($slug), $Product->id, $Product->status, Product::posttype(), 0);
				$Product->save();
				echo apply_filters('editable_slug',$Product->slug);
				break;
		}
		exit();
	}

	function shipping_costs () {
		if (!isset($_GET['method'])) return;
		$Order =& ShoppOrder();

		if ( $_GET['method'] == $Order->Shipping->method || ! isset($Order->Cart->shipping[$_GET['method']]) ) return;

		$Order->Shipping->method = $_GET['method'];
		$Order->Shipping->option = $Order->Cart->shipping[$_GET['method']]->name;
		$Order->Cart->retotal = true;
		$Order->Cart->totals();
		echo json_encode($Order->Cart->Totals);
		exit();
	}

	function order_note_message () {
		check_admin_referer('wp_ajax_shopp_order_note_message');
		if (!isset($_GET['id'])) die('1');

		$Note = new MetaObject(array('id' => intval($_GET['id']),'type'=>'order_note'));
		die($Note->value->message);
	}

	function activate_key () {
		check_admin_referer('wp_ajax_shopp_activate_key');
		echo Shopp::key('activate',$_GET['key']);
		exit();
	}

	function deactivate_key () {
		check_admin_referer('wp_ajax_shopp_deactivate_key');
		$sitekey = Shopp::keysetting();
		$key = $sitekey['k'];
		echo Shopp::key('deactivate',$key);
		exit();
	}

	function rebuild_search_index () {
		check_admin_referer('wp_ajax_shopp_rebuild_search_index');
		global $wpdb;
		if (!class_exists('ContentParser'))
			require(SHOPP_MODEL_PATH.'/Search.php');
		new ContentParser();

		$set = 10;
		$index_table = DatabaseObject::tablename(ContentIndex::$table);

		$total = DB::query("SELECT count(*) AS products,now() as start FROM $wpdb->posts WHERE post_type='".Product::$posttype."'");
		if (empty($total->products)) die('-1');

		echo str_pad('<html><body><script type="text/javascript">var indexProgress = 0;</script>'."\n",2048,' ');
		@ob_flush();
		@flush();
		set_time_limit(0); // Prevent timeouts
		$indexed = 0;
		for ($i = 0; $i*$set < $total->products; $i++) {
			$products = DB::query("SELECT ID FROM $wpdb->posts WHERE post_type='".Product::$posttype."' LIMIT ".($i*$set).",$set",'array','col','ID');
			foreach ($products as $id) {
				$Indexer = new IndexProduct($id);
				$Indexer->index();
				$indexed++;
				echo str_pad('<script type="text/javascript">indexProgress = '.$indexed/(int)$total->products.';</script>'."\n",2048,' ');
				if ( ob_get_length() ) {
					@ob_flush();
					@flush();
				}
			}
			echo str_pad('</body><html>'."\n",2048,' ');
			if ( ob_get_length() ) @ob_end_flush();
		}
		exit();
	}


	function suggestions () {
		check_admin_referer('wp_ajax_shopp_suggestions');

		if (isset($_GET['t'])) {
			switch($_GET['t']) {
				case "product-name": $_GET['s'] = 'shopp_products'; break;
				case "product-tags": $_GET['s'] = 'shopp_tags'; break;
				case "product-category": $_GET['s'] = 'shopp_categories'; break;
				case "customer-type": $_GET['s'] = 'shopp_customer_types'; break;
			}
		}

		if (isset($_GET['s'])) {
			global $wpdb;

			$source = strtolower($_GET['s']);
			$q = $_GET['q'];

			do_action('shopp_suggestions_from_'.$source);

			$joins = $where = array();
			switch ($source) {
				case 'wp_posts':
					$id = 'ID';
					$name = 'post_title';
					$table = $wpdb->posts;
					$where[] = "post_type='post'";
					break;
				case 'wp_pages':
					$id = 'ID';
					$name = 'post_title';
					$table = $wpdb->posts;
					$where[] = "post_type='page'";
					break;
				case 'wp_categories':
					$id = 't.term_id';
					$name = 'name';
					$table = "$wpdb->terms AS t";
					$joins[] = "INNER JOIN  $wpdb->term_taxonomy AS tt ON tt.term_id = t.term_id";
					$where[] = "tt.taxonomy = 'category'";
					break;
				case 'wp_tags':
					$id = 't.term_id';
					$name = 'name';
					$table = "$wpdb->terms AS t";
					$joins[] = "INNER JOIN  $wpdb->term_taxonomy AS tt ON tt.term_id = t.term_id";
					$where[] = "tt.taxonomy = 'post_tag'";
					break;
				case 'wp_media':
					$id = 'ID';
					$name = 'post_title';
					$table = $wpdb->posts;
					$where[] = "post_type='attachment'";
					break;
				case 'wp_users':
					$id = 'ID';
					$name = 'user_login';
					$table = $wpdb->users;
					break;
				case 'shopp_memberships':
					$id = 'id';
					$name = 'name';
					$table = DatabaseObject::tablename('meta');
					$where[] = "context='membership'";
					$where[] = "type='membership'";
					break;
				case 'shopp_products':
					$id = 'ID';
					$name = 'post_title';
					$table = $wpdb->posts;
					$where[] = "post_type='".Product::$posttype."'";
					break;
				case 'shopp_categories':
					$id = 't.term_id';
					$name = 'name';
					$table = "$wpdb->terms AS t";
					$joins[] = "INNER JOIN  $wpdb->term_taxonomy AS tt ON tt.term_id = t.term_id";
					$where[] = "tt.taxonomy = '".ProductCategory::$taxon."'";
					break;
				case 'shopp_tags':
					$id = 't.term_id';
					$name = 'name';
					$table = "$wpdb->terms AS t";
					$joins[] = "INNER JOIN  $wpdb->term_taxonomy AS tt ON tt.term_id = t.term_id";
					$where[] = "tt.taxonomy = 'shopp_tag'";
					if ('shopp_popular_tags' == strtolower($q)) {
						$q = ''; $orderlimit = "ORDER BY tt.count DESC LIMIT 15";
					}
					break;
				case 'shopp_promotions':
					$id = 'id';
					$name = 'name';
					$table = DatabaseObject::tablename(Promotion::$table);
					break;
				case 'shopp_downloads':
					$id = 'id';
					$name = 'name';
					$table = DatabaseObject::tablename('meta');
					$where[] = "context='price'";
					$where[] = "type='download'";
					break;
				case 'shopp_target_markets':
					$markets = shopp_setting('target_markets');
					$results = array();
					foreach ($markets as $id => $market) {
						if (strpos(strtolower($market),strtolower($_GET['q'])) !== false) {
							$_ = new StdClass();
							$_->id = $id;
							$_->name = stripslashes($market);
							$results[] = $_;
						}
					}
					echo json_encode($results);
					exit();
					break;
				case 'shopp_customer_types':
					$types = Lookup::customer_types();
					$results = array();
					foreach ($types as $id => $type) {
						if (strpos(strtolower($type),strtolower($_GET['q'])) !== false) {
							$_ = new StdClass();
							$_->id = $id;
							$_->name = $type;
							$results[] = $_;
						}
					}
					echo json_encode($results);
					exit();
					break;

			}
			if (!empty($q))
				$where[] = "$name LIKE '%".DB::escape($q)."%'";
			$where = join(' AND ',$where);
			$joins = join(' ',$joins);

			$query = "SELECT $id AS id, $name AS name FROM $table $joins WHERE $where $orderlimit";
			$items = DB::query($query,'array');
			echo json_encode($items);
			exit();
		}

	}

	function upload_local_taxes () {
		check_admin_referer('shopp-settings-taxrates');
		if (!class_exists('Setup')) require(SHOPP_FLOW_PATH.'/Setup.php');
		$rates = Setup::taxrate_upload();
		echo json_encode($rates);
		exit();
	}

	function feature_product () {
		check_admin_referer('wp_ajax_shopp_feature_product');

		if (empty($_GET['feature'])) die('0');
		$Product = new ProductSummary((int)$_GET['feature']);
		if (empty($Product->product)) die('0');
		$Product->featured = ('on' == $Product->featured)?'off':'on';
		$Product->save();
		echo $Product->featured;
		exit();
	}

	function update_inventory () {
		check_admin_referer('wp_ajax_shopp_update_inventory');
		$Priceline = new Price($_GET['id']);
		if ( empty($Priceline->id) ) die('0');
		if ( ! str_true($Priceline->inventory) ) die('0');
		if ( (int)$_GET['stock'] < 0 ) die('0');
		$Priceline->stock = $Priceline->stocked = $_GET['stock'];
		$Priceline->save();
		$summary = DatabaseObject::tablename(ProductSummary::$table);
		DB::query("UPDATE $summary SET modified='0000-00-00 00:00:01' WHERE product=$Priceline->product LIMIT 1");
		echo "1";
		exit();
	}

	function import_file () {
		check_admin_referer('wp_ajax_shopp_import_file');
		global $Shopp;
		$Engine =& $Shopp->Storage->engines['download'];

		$error = create_function('$s', 'die(json_encode(array("error" => $s)));');
		if (empty($_REQUEST['url'])) $error(__('No file import URL was provided.','Shopp'));
		$url = $_REQUEST['url'];
		$request = parse_url($url);
		$headers = array();
		$filename = basename($request['path']);

		$_ = new StdClass();
		$_->name = $filename;
		$_->stored = false;

		$File = new ProductDownload();
		$stored = false;
		$File->_engine(); // Set engine from storage settings
		$File->uri = sanitize_path($url);
		$File->type = "download";
		$File->name = $filename;
		$File->filename = $filename;

		if ($File->found()) {
			// File in storage, look up meta from storage engine
			$File->readmeta();
			$_->stored = true;
			$_->path = $File->uri;
			$_->size = $File->size;
			$_->mime = $File->mime;
			if ($_->mime == "application/octet-stream" || $_->mime == "text/plain")
				$mime = file_mimetype($File->name);
			if ($mime == "application/octet-stream" || $mime == "text/plain")
				$_->mime = $mime;
		} else {
			if (!$importfile = @tempnam(sanitize_path(realpath(SHOPP_TEMP_PATH)), 'shp')) $error(sprintf(__('A temporary file could not be created for importing the file.','Shopp'),$importfile));
			if (!$incoming = @fopen($importfile,'w')) $error(sprintf(__('A temporary file at %s could not be opened for importing.','Shopp'),$importfile));

			if (!$file = @fopen(linkencode($url), 'rb')) $error(sprintf(__('The file at %s could not be opened for importing.','Shopp'),$url));
			$data = @stream_get_meta_data($file);

			if (isset($data['timed_out']) && $data['timed_out']) $error(__('The connection timed out while trying to get information about the target file.','Shopp'));

			if (isset($data['wrapper_data'])) {
				foreach ($data['wrapper_data'] as $d) {
					if (strpos($d,':') === false) continue;
					list($name,$value) = explode(': ',$d);
					if ($rel = strpos($value,';')) $headers[$name] = substr($value,0,$rel);
					else $headers[$name] = $value;
				}
			}

			$tmp = basename($importfile);
			// $Settings =& ShoppSettings();

			$_->path = $importfile;
			if (empty($headers)) {
				// Stat file data directly if no stream data available
				$_->size = filesize($url);
				$_->mime = file_mimetype($url);
			} else {
				// Use the stream data
				$_->size = $headers['Content-Length'];
				$_->mime = $headers['Content-Type'] == 'text/plain'?file_mimetype($_->name):$headers['Content-Type'];
			}
		}

		// Mimetype must be set or we'll have problems in the UI
		if (!$_->mime) $_->mime = "application/octet-stream";

		echo str_repeat(' ',1024); // Minimum browser data
		echo '<script type="text/javascript">var importFile = '.json_encode($_).';</script>'."\n";
		echo '<script type="text/javascript">var importProgress = 0;</script>'."\n";
		if ($_->stored) exit();
		@ob_flush();
		@flush();

		$progress = 0;
		$bytesread = 0;
		fseek($file, 0);
		$packet = 1024*1024;
		set_time_limit(0); // Prevent timeouts
		while(!feof($file)) {
			if (connection_status() !== 0) return false;
			$buffer = fread($file,$packet);
			if (!empty($buffer)) {
				fwrite($incoming, $buffer);
				$bytesread += strlen($buffer);
				echo '<script type="text/javascript">importProgress = '.$bytesread/(int)$_->size.';</script>'."\n";
				@ob_flush();
				@flush();
			}
		}
		@ob_end_flush();
		fclose($file);
		fclose($incoming);

		exit();
	}

	function storage_suggestions () { exit(); }

	function verify_file () {
		check_admin_referer('wp_ajax_shopp_verify_file');
		$Settings = &ShoppSettings();
		chdir(WP_CONTENT_DIR); // relative file system path context for realpath
		$url = $_POST['url'];
		$request = parse_url($url);

		if ($request['scheme'] == "http") {
			$results = get_headers(linkencode($url));
			if (substr($url,-1) == "/") die("ISDIR");
			if (strpos($results[0],'200') === false) die("NULL");
		} else {
			$url = str_replace('file://','',$url);

			if ($url{0} != "/" || substr($url,0,2) == "./" || substr($url,0,3) == "../")
				$result = apply_filters('shopp_verify_stored_file',$url);

			$url = sanitize_path(realpath($url));
			if (!file_exists($url)) die('NULL');
			if (is_dir($url)) die('ISDIR');
			if (!is_readable($url)) die('READ');

		}

		die('OK');

	}

	function category_children () {
		check_admin_referer('wp_ajax_shopp_category_children');

		if (empty($_GET['parent'])) die('0');
		$parent = $_GET['parent'];

		$columns = array('id','parent','priority','name','uri','slug');

		$filters['columns'] = 'cat.'.join(',cat.',$columns);
		$filters['parent'] = $parent;

		$Catalog = new Catalog();
		$Catalog->outofstock = true;
		$Catalog->load_categories($filters);

		$columns[] = 'depth';
		foreach ($Catalog->categories as &$Category) {
			$properties = get_object_vars($Category);
			foreach ($properties as $property => $value)
				if (!in_array($property,$columns)) unset($Category->$property);
		}

		die(json_encode($Catalog->categories));
	}

	function category_order () {
		check_admin_referer('wp_ajax_shopp_category_order');
		if (empty($_POST['position']) || !is_array($_POST['position'])) die('0');

		$db =& DB::get();
		$table = DatabaseObject::tablename(ProductCategory::$table);
		$updates = $_POST['position'];
		foreach ($updates as $id => $position)
			$db->query("UPDATE $table SET priority='$position' WHERE id='$id'");
		die('1');
		exit();
	}

	function products_order () {
		check_admin_referer('wp_ajax_shopp_category_products_order');
		if (empty($_POST['category']) || empty($_POST['position']) || !is_array($_POST['position'])) die('0');

		global $wpdb;
		$updates = $_POST['position'];
		$category = (int)$_POST['category'];
		foreach ((array)$updates as $id => $position)
			DB::query("UPDATE $wpdb->term_relationships SET term_order='".((int)$position)."' WHERE object_id='".((int)$id)."' AND term_taxonomy_id='$category'");
		die('1');
		exit();
	}

	function gateway_ajax () {
		check_admin_referer('wp_ajax_shopp_gateway');
		if (isset($_POST['pid'])) {
			$Purchase = new Purchase($_POST['pid']);
			if ($Purchase->gateway) do_action('shopp_gateway_ajax_'.sanitize_title_with_dashes($Purchase->gateway), $Purchase);
		}
		exit();
	}

	// @todo Investigate if it is possible to inject a formatted error log message as an XSS vector
	function logviewer () {
		check_admin_referer('wp_ajax_shopp_debuglog'); ?>
		<html>
		<head>
		<style type="text/css">
		body { margin: 0; padding: 0; font-family:monospace;font-size:1em;line-height:1em;}
		ol { list-style:decimal;padding-left:5em;background:#ececec;margin-left:0; margin-bottom: 1px; }
		ol li { background:white;margin:0;padding:5px; }
		a { color: #606060; text-decoration: none; }
		</style>
		</head>
		<body>
		<ol><?php $log = ShoppErrorLogging()->tail(1000); $size = count($log);
				foreach ($log as $n => $line) {
					if (empty($line)) continue;
					echo '<li'.($n+1 == $size?' id="bottom"':'').'>'.$line.'</li>';
				}

		?></ol></body></html><?php exit();
	}

} // END class AjaxFlow

?>