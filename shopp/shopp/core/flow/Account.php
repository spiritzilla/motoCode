<?php
/**
 * Account
 *
 * Flow controller for the customer management interfaces
 *
 * @author Jonathan Davis
 * @version 1.0
 * @copyright Ingenesis Limited, January  6, 2010
 * @package shopp
 * @subpackage shopp
 **/

class Account extends AdminController {

	var $screen = 'shopp_page_shopp-customers';

	/**
	 * Account constructor
	 *
	 * @return void
	 * @author Jonathan Davis
	 **/
	function __construct () {
		parent::__construct();
		if (!empty($_GET['id'])) {
			wp_enqueue_script('postbox');
			wp_enqueue_script('password-strength-meter');
			shopp_enqueue_script('suggest');
			shopp_enqueue_script('colorbox');
			do_action('shopp_customer_editor_scripts');
			add_action('admin_head',array(&$this,'layout'));
		} else add_action('admin_print_scripts',array(&$this,'columns'));
		do_action('shopp_customer_admin_scripts');
	}

	/**
	 * Parses admin requests to determine the interface to render
	 *
	 * @author Jonathan Davis
	 * @return void
	 **/
	function admin () {
		if (!empty($_GET['id'])) $this->editor();
		else $this->customers();
	}

	/**
	 * Interface processor for the customer list screen
	 *
	 * Handles processing customer list actions and displaying the
	 * customer list screen
	 *
	 * @author Jonathan Davis
	 * @return void
	 **/
	function customers () {
		global $wpdb;

		$defaults = array(
			'page' => false,
			'deleting' => false,
			'selected' => false,
			'update' => false,
			'newstatus' => false,
			'pagenum' => 1,
			'paged' => false,
			'per_page' => 20,
			'start' => '',
			'end' => '',
			'status' => false,
			's' => '',
			'range' => '',
			'startdate' => '',
			'enddate' => '',
		);

		$args = array_merge($defaults,$_GET);
		extract($args, EXTR_SKIP);

		if ($page == $this->Admin->pagename('customers')
				&& !empty($deleting)
				&& !empty($selected)
				&& is_array($selected)
				&& current_user_can('shopp_delete_customers')) {
			foreach($selected as $deletion) {
				$Customer = new Customer($deletion);
				$Billing = new BillingAddress($Customer->id);
				$Billing->delete();
				$Shipping = new ShippingAddress($Customer->id);
				$Shipping->delete();
				$Customer->delete();
			}
		}

		$updated = false;
		if (!empty($_POST['save'])) {
			check_admin_referer('shopp-save-customer');

			if ($_POST['id'] != "new") {
				$Customer = new Customer($_POST['id']);
				$Billing = new BillingAddress($Customer->id);
				$Shipping = new ShippingAddress($Customer->id);
			} else $Customer = new Customer();

			if (!empty($Customer->wpuser)) $user = get_user_by('id',$Customer->wpuser);

			$Customer->updates($_POST);

			// Reassign WordPress login
			if ('wordpress' == shopp_setting('account_system') && !empty($_POST['userlogin']) && $_POST['userlogin'] !=  $user->user_login) {
				$newlogin = get_user_by('login',$_POST['userlogin']);
				if (!empty($newlogin->ID)) {
					if (DB::query("SELECT count(*) AS used FROM $Customer->_table WHERE wpuser=$newlogin->ID",'auto','col','used') == 0) {
						$Customer->wpuser = $newlogin->ID;
						$updated = sprintf(__('Updated customer login to %s.','Shopp'),"<strong>$newlogin->user_login</strong>");
					} else $updated = sprintf(__('Could not update customer login to "%s" because that user is already assigned to another customer.','Shopp'),'<strong>'.sanitize_user($_POST['userlogin']).'</strong>');

				} else $updated = sprintf(__('Could not update customer login to "%s" because the user does not exist in WordPress.','Shopp'),'<strong>'.sanitize_user($_POST['userlogin']).'</strong>');
			}

			if (!empty($_POST['new-password']) && !empty($_POST['confirm-password'])
				&& $_POST['new-password'] == $_POST['confirm-password']) {
					$Customer->password = wp_hash_password($_POST['new-password']);
					if (!empty($Customer->wpuser)) wp_set_password($_POST['new-password'], $Customer->wpuser);
				}

			$Customer->info = false; // No longer used from DB
			$Customer->save();

			if (isset($_POST['info']) && !empty($_POST['info'])) {
				foreach ((array)$_POST['info'] as $id => $info) {
					$Meta = new MetaObject($id);
					$Meta->value = $info;
					$Meta->save();
				}
			}

			if (isset($Customer->id)) $Billing->customer = $Customer->id;
			$Billing->updates($_POST['billing']);
			$Billing->save();

			if (isset($Customer->id)) $Shipping->customer = $Customer->id;
			$Shipping->updates($_POST['shipping']);
			$Shipping->save();
			if (!$updated) __('Customer updated.','Shopp');
			$Customer = false;

		}

		$pagenum = absint( $paged );
		if ( empty($pagenum) )
			$pagenum = 1;
		if( !$per_page || $per_page < 0 )
			$per_page = 20;
		$index = ($per_page * ($pagenum-1));

		if (!empty($start)) {
			$startdate = $start;
			list($month,$day,$year) = explode("/",$startdate);
			$starts = mktime(0,0,0,$month,$day,$year);
		}
		if (!empty($end)) {
			$enddate = $end;
			list($month,$day,$year) = explode("/",$enddate);
			$ends = mktime(23,59,59,$month,$day,$year);
		}

		$customer_table = DatabaseObject::tablename(Customer::$table);
		$billing_table = DatabaseObject::tablename(BillingAddress::$table);
		$purchase_table = DatabaseObject::tablename(Purchase::$table);
		$users_table = $wpdb->users;

		$where = array();
		if (!empty($s)) {
			$s = stripslashes($s);
			if (preg_match_all('/(\w+?)\:(?="(.+?)"|(.+?)\b)/',$s,$props,PREG_SET_ORDER)) {
				foreach ($props as $search) {
					$keyword = !empty($search[2])?$search[2]:$search[3];
					switch(strtolower($search[1])) {
						case "company": $where[] = "c.company LIKE '%$keyword%'"; break;
						case "login": $where[] = "u.user_login LIKE '%$keyword%'"; break;
						case "address": $where[] = "(b.address LIKE '%$keyword%' OR b.xaddress='%$keyword%')"; break;
						case "city": $where[] = "b.city LIKE '%$keyword%'"; break;
						case "province":
						case "state": $where[] = "b.state='$keyword'"; break;
						case "zip":
						case "zipcode":
						case "postcode": $where[] = "b.postcode='$keyword'"; break;
						case "country": $where[] = "b.country='$keyword'"; break;
					}
				}
			} elseif (strpos($s,'@') !== false) {
				 $where[] = "c.email='$s'";
			} elseif (is_numeric($s)) {
				$where[] = "c.id='$s'";
			} else $where[] = "(CONCAT(c.firstname,' ',c.lastname) LIKE '%$s%' OR c.company LIKE '%$s%')";

		}
		if (!empty($starts) && !empty($ends)) $where[] = ' (UNIX_TIMESTAMP(c.created) >= '.$starts.' AND UNIX_TIMESTAMP(c.created) <= '.$ends.')';

		$select = array(
			'columns' => 'SQL_CALC_FOUND_ROWS c.*,city,state,country,user_login',
			'table' => "$customer_table as c",
			'joins' => array(
					$billing_table => "LEFT JOIN $billing_table AS b ON b.customer=c.id AND b.type='billing'",
					$users_table => "LEFT JOIN $users_table AS u ON u.ID=c.wpuser AND (c.wpuser IS NULL OR c.wpuser != 0)"
				),
			'where' => $where,
			'groupby' => "c.id",
			'orderby' => "c.created DESC",
			'limit' => "$index,$per_page"
		);
		$query = DB::select($select);
		$Customers = DB::query($query,'array','index','id');

		$total = DB::found();

		// Add order data to customer records in this view
		$orders = DB::query("SELECT customer,SUM(total) AS total,count(id) AS orders FROM $purchase_table WHERE customer IN (".join(',',array_keys($Customers)).") GROUP BY customer",'array','index','customer');
		foreach ($Customers as &$record) {
			$record->total = 0; $record->orders = 0;
			if ( ! isset($orders[$record->id]) ) continue;
			$record->total = $orders[$record->id]->total;
			$record->orders = $orders[$record->id]->orders;
		}

		$num_pages = ceil($total / $per_page);
		$ListTable = ShoppUI::table_set_pagination ($this->screen, $total, $num_pages, $per_page );

		$ranges = array(
			'all' => __('Show New Customers','Shopp'),
			'today' => __('Today','Shopp'),
			'week' => __('This Week','Shopp'),
			'month' => __('This Month','Shopp'),
			'quarter' => __('This Quarter','Shopp'),
			'year' => __('This Year','Shopp'),
			'yesterday' => __('Yesterday','Shopp'),
			'lastweek' => __('Last Week','Shopp'),
			'last30' => __('Last 30 Days','Shopp'),
			'last90' => __('Last 3 Months','Shopp'),
			'lastmonth' => __('Last Month','Shopp'),
			'lastquarter' => __('Last Quarter','Shopp'),
			'lastyear' => __('Last Year','Shopp'),
			'lastexport' => __('Last Export','Shopp'),
			'custom' => __('Custom Dates','Shopp')
			);

		$exports = array(
			'tab' => __('Tab-separated.txt','Shopp'),
			'csv' => __('Comma-separated.csv','Shopp'),
			'xls' => __('Microsoft&reg; Excel.xls','Shopp')
			);


		$formatPref = shopp_setting('customerexport_format');
		if (!$formatPref) $formatPref = 'tab';

		$columns = array_merge(Customer::exportcolumns(),BillingAddress::exportcolumns(),ShippingAddress::exportcolumns());
		$selected = shopp_setting('customerexport_columns');
		if (empty($selected)) $selected = array_keys($columns);

		$authentication = shopp_setting('account_system');

		$action = add_query_arg( array('page'=>$this->Admin->pagename('customers') ),admin_url('admin.php'));

		include(SHOPP_ADMIN_PATH."/customers/customers.php");

	}

	/**
	 * Registers the column headers for the customer list screen
	 *
	 * @author Jonathan Davis
	 * @return void
	 **/
	function columns () {
		shopp_enqueue_script('calendar');
		register_column_headers($this->screen, array(
			'cb'=>'<input type="checkbox" />',
			'customer-name'=>__('Name','Shopp'),
			'customer-login'=>__('Login','Shopp'),
			'email'=>__('Email','Shopp'),
			'customer-location'=>__('Location','Shopp'),
			'customer-orders'=>__('Orders','Shopp'),
			'customer-joined'=>__('Joined','Shopp'))
		);

	}

	/**
	 * Builds the interface layout for the customer editor
	 *
	 * @author Jonathan Davis
	 * @return void Description...
	 **/
	function layout () {
		global $Shopp;
		$Admin =& $Shopp->Flow->Admin;
		include(SHOPP_ADMIN_PATH."/customers/ui.php");
	}

	/**
	 * Interface processor for the customer editor
	 *
	 * Handles rendering the interface, processing updated customer details
	 * and handing saving them back to the database
	 *
	 * @author Jonathan Davis
	 * @return void
	 **/
	function editor () {

		if ( ! current_user_can('shopp_customers') )
			wp_die(__('You do not have sufficient permissions to access this page.'));


		if ($_GET['id'] != "new") {
			$Customer = new Customer($_GET['id']);
			$Customer->Billing = new BillingAddress($Customer->id);
			$Customer->Shipping = new ShippingAddress($Customer->id);
			if (empty($Customer->id))
				wp_die(__('The requested customer record does not exist.','Shopp'));
		} else $Customer = new Customer();

		if (empty($Customer->info->meta)) remove_meta_box('customer-info','shopp_page_shopp-customers','normal');

		if ($Customer->id > 0) {
			$purchase_table = DatabaseObject::tablename(Purchase::$table);
			$r = DB::query("SELECT count(id) AS purchases,SUM(total) AS total FROM $purchase_table WHERE customer='$Customer->id' LIMIT 1");

			$Customer->orders = $r->purchases;
			$Customer->total = $r->total;
		}


		$countries = array(''=>'&nbsp;');
		$countrydata = Lookup::countries();
		foreach ($countrydata as $iso => $c) {
			if (isset($_POST['settings']) && $_POST['settings']['base_operations']['country'] == $iso)
				$base_region = $c['region'];
			$countries[$iso] = $c['name'];
		}
		$Customer->countries = $countries;

		$regions = Lookup::country_zones();
		$Customer->billing_states = array_merge(array(''=>'&nbsp;'),(array)$regions[$Customer->Billing->country]);
		$Customer->shipping_states = array_merge(array(''=>'&nbsp;'),(array)$regions[$Customer->Shipping->country]);

		include(SHOPP_ADMIN_PATH."/customers/editor.php");
	}


} // END class Account

?>