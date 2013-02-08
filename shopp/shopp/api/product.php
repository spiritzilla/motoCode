<?php
/**
* Product API
*
* Plugin api for manipulating products in the catalog.
*
* @author John Dillick
* @version 1.0
* @copyright Ingenesis Limited, June 30, 2011
* @license GNU GPL version 3 (or later) {@see license.txt}
* @package shopp
* @since 1.2
* @subpackage shopp
**/

/**
 * shopp_add_product - comprehensive product creation through product api.  This function will do everything needed for creating a product
 * except attach product images and products.  That is done in the asset api. :)  You should be able to build an importer from another system using this function.
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param array $data (required) associative array structure containing a single product definition, see _validate_product_data for how this array is structured/validated.
 * @return Product the created product object, or boolean false on a failure.
 **/
function shopp_add_product ( $data = array() ) {
	if ( empty($data) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Empty data parameter.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$problems = _validate_product_data ( $data );

	if ( ! empty($problems) ) {
		if(SHOPP_DEBUG) new ShoppError("Problems detected: "._object_r($problems),__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	$Product = new Product();

	// Set Product publish status
	if ( isset($data['publish']) && isset($data['publish']['flag']) && $data['publish']['flag'] ) {
		if ( isset($data['publish']['publishtime']['month'])
			&& isset($data['publish']['publishtime']['day'])
			&& isset($data['publish']['publishtime']['year'])
			&& isset($data['publish']['publishtime']['hour'])
			&& isset($data['publish']['publishtime']['minute'])
			&& isset($data['publish']['publishtime']['meridian']) ) {

			if ($data['publish']['publishtime']['meridian'] == "PM" && $data['publish']['publishtime']['hour'] < 12)
				$data['publish']['publishtime']['hour'] += 12;

			$Product->publish = mktime( $data['publish']['publishtime']['hour'],
								$data['publish']['publishtime']['minute'],
								0,
								$data['publish']['publishtime']['month'],
								$data['publish']['publishtime']['day'],
								$data['publish']['publishtime']['year'] );
			$Product->status = 'future';
		} else {
			// Auto set the publish date if not set (or more accurately, if set to an irrelevant timestamp)
			if ($Product->publish <= 86400) $Product->publish = null;
		}
	} else {
		$Product->publish = 0;
	}

	// Set Product name
	if ( empty($data['name']) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Missing product name.",__FUNCTION__,SHOPP_DEBUG_ERR);
	}
	$Product->name = $data['name'];

	// Set Product slug
	if ( ! empty($data['slug'])) $Product->slug = $data['slug'];
	if (empty($Product->slug)) $Product->slug = sanitize_title_with_dashes($Product->name);
	$Product->slug = wp_unique_post_slug($Product->slug, $Product->id, $Product->status, Product::posttype(), 0);

	$Product->updates($data, array('meta','categories','prices','tags', 'publish'));
	$Product->save();

	Product::publishset(array($Product->id), $data['publish']['flag'] ? 'publish' : 'draft');

	if ( empty($Product->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Failure to create new Product object.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	// Product-wide settings
	$Product->featured = ( isset($data['featured']) && true === $data['featured'] ? "on" : "off" );
	$Product->variants = ( isset($data['variants']) ? "on" : "off" );
	$Product->addons = ( isset($data['addons']) ? "on" : "off" );

	if ( isset($data['packaging']) ) {
		$packaging_set = shopp_product_set_packaging($Product->id, $data['packaging']);
		if ( ! $packaging_set ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Failure to set packaging setting.",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}
	}

	// Save Taxonomies
	// Categories
	if ( isset($data['categories']) && isset($data['categories']['terms']) ) {
		$cats_set = shopp_product_add_categories ( $Product->id, $data['categories']['terms'] );
		if ( ! $cats_set ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Failure to add product categories to product.",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}
	}


	// Tags
	if ( isset($data['tags']) && isset($data['tags']['terms']) ) {
		$tags_set = shopp_product_add_tags ( $Product->id, $data['tags']['terms'] );
		if ( ! $tags_set ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Failure to add product tags to product.",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}
	}


	// Terms
	if ( isset($data['terms']) && isset($data['terms']['terms']) && isset($data['terms']['taxonomy']) ) {
		$terms_set = shopp_product_add_terms ( $Product->id, $data['terms']['terms'], $data['terms']['taxonomy'] );
		if ( ! $terms_set ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Failure to add product taxonomy terms to product.",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}
	}


	// Create Specs
	if ( isset($data['specs']) ) {
		$specs_set = shopp_product_set_specs ( $Product->id, $data['specs'] );
		if ( ! $specs_set ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Failure to add product specs to product.",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}
	}

	$subjects = array();
	$prices = array();

	// Create Prices
	if ( isset($data['single']) ) {
		if ( ! empty($data['single']) ) $subjects['product'] = array($data['single']);
	} else if ( isset($data['variants']) ) {  // Construct and Populate variants
		if ( ! isset($data['variants']['menu']) || empty($data['variants']['menu']) ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: variants menu is empty.",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}
		$new_variants = shopp_product_set_variant_options ( $Product->id, $data['variants']['menu'], false );

		$pricekeys = $prices = array();
		foreach ( $new_variants as $Price ) $prices[$Price->id] = $pricekeys[$Price->optionkey] = $Price;

		if ( ! $prices ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Unable to set variant options.",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}

		$subjects['variants'] = $data['variants'];
	}

	// Create the "product" Price
	$Price = new Price();
	$Price->label = __('Price & Delivery', 'Shopp');
	$Price->context = 'product';
	$Price->product = $Product->id;
	if ( isset($subjects['variants']) ) $Price->type = 'N/A'; // disabled
	$Price->save();
	$prices[$Price->id] = $productprice = $Price;

	// Create Addons
	if ( isset($data['addons']) ) {
		if ( ! isset($data['addons']['menu']) || empty($data['addons']['menu']) ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: addons menu is empty",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}

		$new_addons = shopp_product_set_addon_options ( $Product->id, $data['addons']['menu'], false );
		$addon_prices = array();
		foreach ( $new_addons as $Addon ) $addon_prices[$Addon->id] = $Addon;

		if ( ! $addon_prices ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Unable to set addon options.",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}

		$prices = $prices + $addon_prices;
		$subjects['addons'] = $data['addons'];
	}

	$contexts = array( 'addons' => 'addon', 'product' => 'product', 'variants' => 'variant' );
	foreach ( $subjects as $pricetype => $variants ) {

		// apply settings for each priceline
		foreach ( $variants as $key => $variant ) {
			if ( ! is_numeric($key) ) continue;

			$price = null;
			if ( 'product' == $pricetype ) {
				$price = $productprice->id;
			} else {
				// 'option' => 'array',	// array option example: Color=>Blue, Size=>Small
				if ( ! isset($variant['option']) || empty($variant['option']) ) {
					if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: variant $key missing variant options.",__FUNCTION__,SHOPP_DEBUG_ERR);
					return false;
				}

				list( $optionkey, $options, $label, $mapping ) = $Product->optionmap( $variant['option'], $variants['menu'], ('variants' == $pricetype ? 'variant' : 'addon') );
				if ( 'variants' == $pricetype && isset($pricekeys[$optionkey]) ) $price = $pricekeys[$optionkey]->id;
				else {
					// Find the correct Price
					foreach ( $addon_prices as $index => $Price ) {
						if ( $Price->options == $options && $Price->label == $label ) {
							$price = $index;
							break;
						}
					}
				}
			}

			if ( null === $price || ! isset($prices[$price]) ) {
				if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Variant $key not valid for this option set.",__FUNCTION__,SHOPP_DEBUG_ERR);
				return false;
			}

			// modify each priceline
			$prices[$price] = shopp_product_set_variant ( $prices[$price], $variant, $contexts[$pricetype] );
			if ( ! $prices[$price] ) {
				if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product variant setup failed.",__FUNCTION__,SHOPP_DEBUG_ERR);
				return false;
			}

			// save priceline settings
			if ( isset($prices[$price]->settings) )
				shopp_set_meta ( $prices[$price]->id, 'price', 'settings', $prices[$price]->settings );

			// We have everything we need to complete this price line
			$prices[$price]->save();

		} //end variants foreach
	} // end subjects foreach

	// Reset rollup figures for prices.
	$Product->resum();

	// Calculates aggregate product stats
	foreach ( $prices as $Price ) {
		$Product->sumprice($Price);
	}

	// Skeleton summary
	$Summary = new ProductSummary();
	$sum_props = array_keys($Summary->_datatypes);
	// init default summary items
	foreach ( $sum_props as $prop ) {
		if ( ! isset($Product->$prop) ) $Product->$prop = NULL;
	}

	// Process pricing stats
	$records = null;
	foreach ( $prices as $Price ) {
		$Product->pricing($records,$Price);
	}

	// Saves generated stats to the product summary
	$Product->sumup();

	return shopp_product($Product->id);
} // end function shopp_add_product

/**
 * shopp_rmv_product
 *
 * remove a product
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product the product id
 * @return bool true on success, false on failure
 **/
function shopp_rmv_product ( $product = false ) {
	if ( ! $product || ! ( $Product = shopp_product($product) ) ) return false;
	$Product->delete();
	return true;
}


/**
 * _validate_product_data - helper function for shopp_add_product that can be called recursively to validate the associative data array needed to build a product object.
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param array $data the associative array being used to build the product object
 * @param string $types (optional default:data) Sets the _type that will be evaluated for proper types.  $_data is the top level, and each non-built in type is described
 * in subsequent _type arrays
 * @param array $problems array of problems that have been found in the data through recursive calls
 * @return array list of problems with the data preventing proper product object construction
 **/
function _validate_product_data ( $data, $types = 'data', $problems = array() ) {
	$t = '_'.$types;

	if ( ! is_array($data) ) {
		$problems["$types must be an array."] = true;
		return $problems;
	}

	// data properties needed to populate a product
	$_data = array(
		'name' => 'string', 		// string - the product name
		'slug' => 'string', 		// string - the product slug (optional)
		'publish' => 'publish',		// array - flag => bool, publishtime => array(month => int, day => int, year => int, hour => int, minute => int, meridian => AM/PM)
		'categories' => 'terms',	// array of shopp category terms
		'tags' => 'terms', 			// array of shopp tag terms
		'terms' => 'terms', 		// array of taxonomy_type => type, terms => array of terms
		'description' => 'string', 	// string - the product description text
		'summary' => 'string', 		// string - the product summary text
		'specs' => 'array', 		// array - spec name => spec value pairs
		'single' => 'variant',		// array - single variant
		'variants' => 'variants', 	// array - menu => options, count => # of variants, 0-# => variant
		'addons' => 'variants', 	// array of addon arrays
		'featured' => 'bool', 		// bool - product flag
		'packaging' => 'bool', 		// bool - packaging flag
		'processing' => 'processing'// array - flag => bool, min => days, max => days)
	);

	$_publish = array(
		'flag' => 'bool',			// bool - publish or not
		'publishtime' => 'timestamp'// array - array(month => int, day => int, year => int, hour => int, minute => int, meridian => AM/PM)
	);

	$_timestamp = array(
		'month' => 'int',			// int - month
		'day' => 'int',				// int - day
		'year' => 'int',			// int - year
		'hour' => 'int',			// int - hour
		'minute' => 'int',			// int - minute
		'meridian' => 'enum'		// array (AM, PM)
	);

	$_meridian = array('AM', 'PM');

	$_terms = array(
		'terms' => 'array',			// array of terms
		'taxonomy' => 'string'		// string - name of taxonomy (not needed for categories and tags)
	);

	// variants structure
	$_variants = array(
		'menu' => 'array',		// two dimensional array creates option permutations
								// examples:
								// $option['Color']['Blue']
								// $option['Color']['Red]
								// $option['Size']['Large']
								// $option['Size']['Small']

		'count' => 'int',		// Number of variants
		'#'	=> 'variant'		// number indexed elements are each a variant
	);

	// single/variant/addon structure
	$_variant = array(
		'option' => 'array',	// array option example: Color=>Blue, Size=>Small
		'type' => 'enum',		// string - Shipped, Virtual, Download, Donation, Subscription, Disabled ( Price::types() )
		'taxed' => 'bool',		// bool - flag variant as taxable
		'price' => 'float',		// float - Price of variant
		'sale' => 'sale',		// array - flag => bool, price => Sale price of variant
		'shipping' => 'shipping', 	// array - flag => bool, fee, weight, height, width, length
		'inventory'=> 'inventory',	// array - flag => bool, stock, sku
		'donation'=> 'donation',	// (optional - needed only for Donation type) array of settings (variable, minumum)
		'subscription'=>'subscription'	// (optional - needed only for Subscription type) array of subscription settings
	);

	// order processing estimate
	$_processing = array(
		'flag'=>'bool',			// bool - processing time setting on/off
		'min'=>'cycle',			// array('interval'=># of time units, 'period'=> one of d, w, m, y)
		'max'=>'cycle'			// array('interval'=># of time units, 'period'=> one of d, w, m, y)
	);

	// variant types
	$_types = Price::types();
	$_type = array();
	foreach ( $_types as $type ) {
		$_type[] = $type['value'];
	}

	// sale price
	$_sale = array(
		'flag' => 'bool', 	// sale price on/off
		'price' => 'float' // sale price
	);

	// variant shipping settings
	$_shipping = array(
		'flag'=>'bool',				// bool - charge shipping on or off
		'fee'=>'float',				// float - shipping fee for variant
		'weight'=>'float',			// float - weight of variant
		'height'=>'float',			// float - height of variant
		'width'=>'float',			// float - width of variatn
		'length'=>'float'			// float - length of variant
	);

	// variant inventory settings
	$_inventory = array(
		'flag' => 'bool',	// bool - inventory settings on/off
		'stock' => 'int',	// int - stock level
		'sku'	=> 'string' // sku - stock keeping unit label
	);

	// variant donation settings
	$_donation = array(
		'variable' => 'bool',	// bool - variable prices allowed
		'minimum' => 'bool'		// bool - price is the minimum allowed
	);

	// variant subscription settings
	$_subscription = array(
		'trial' => 'trial',
		'billcycle' => 'billcycle'
	);

	// subscriptions billing cycle
	$_billcycle = array(
		'cycle' => 'cycle', // billing cycle
		'cycles' => 'int'	// number of cycles
	);

	// subscription trial settings
	$_trial = array(
		'cycle' => 'cycle',	// trial cycle
		'price' => 'float'	// price during trial
	);

	// time cycles
	$_cycle = array(
		'interval' => 'int',	// int number of units
		'period' => 'enum'		// string d for day, w for week, m for month, y for year
	);

	$_periods = Price::periods();
	$_period = array();
	foreach ( $_periods[0] as $p ) $_period[] = $p['value'];

	$known_types = array( 'int' => 'is_numeric', 'float' => 'is_numeric', 'bool' => 'is_bool', 'string' => 'is_string', 'array' => 'is_array' );

	foreach ( $data as $key => $value ) {
		if ( is_numeric($key) && 'variants' == $types ) {
			$key = '#';
		}
		$k = '_'.$key;
		$recurse = ${$t}[$key];
		$r = '_'.$recurse;

		if ( in_array(${$t}[$key], array_keys($known_types) )  ) { // check known types first
			if ( ! $known_types[${$t}[$key]]( $value ) ) {
				if ( ! isset($problems['type mismatch']) ) $problems['type mismatch'] = array();
				if ( ! isset($problems['type mismatch'][$types]) ) $problems['type mismatch'][$types] = array();
				$problems['type mismatch'][$types][$key] = ${$t}[$key];
			}
		} else if ( 'enum' == ${$t}[$key] && ! in_array( $value, $$k) ) {  // check enumerated types
			if ( ! isset($problems['out of range']) ) $problems['out of range'] = array();
			if ( ! isset($problems['out of range'][$types]) ) $problems['out of range'][$types] = array();
			$problems['out of range'][$types][$key] = implode(', ', $$k);
		} else if ( isset($$r) ) { // recurse into provided data structure, and validate
			$problems = _validate_product_data($value, $recurse, $problems);
		} else if ( ! in_array($key, array_keys($$t) ) ) { // unknown data type
			if ( ! isset($problems['unknown data type']) ) $problems['unknown data type'] = array();
			if ( ! isset($problems['unknown data type'][$types]) ) $problems['unknown data type'][$types] = array();
			$problems['unknown data type'][$types][] = $key;
		}

		if ( 'single' == $key && isset($data['variants']) || 'variants' == $key && isset($data['single']) ) {
			$problems['both single and variant price definitions detected'] = true;
		}
	}

	return $problems;
}

// Product-wide getters

/**
 * shopp_product - retrieve a Shopp product by id
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param mixed $product (required) the product id to load.  Also possible to specify the name or slug.  See the $load_by parameter.
 * @param string $load_by (optional default=id) id for loading the product by id, name for loading by name, and slug for loading by slug
 * @return Product a product object, false on failure
 **/
function shopp_product ( $product = false, $load_by = 'id' ) {
	if ( false === $product ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	$Product = new Product($product, $load_by);
	if ( empty($Product->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Unable to load product $product.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Product->load_data();
	return $Product;
}

/**
 * shopp_product_publish - set a product to published state, now or in the future, or unpublish a product
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int (required) $product the product id to publish/unpublish
 * @param bool (optional default: false) $flag true for publish, false for unpublish
 * @param int (optional) $datetime a unix datetime, use php mktime() to create this
 * @return bool true on success, false on failure
 **/
function shopp_product_publish ( $product = false, $flag = false, $datetime = false ) {
	if ( false === $product ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	$Product = new Product($product);
	if ( empty($Product->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Unable to load product $product.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	$Product->status = 'draft';
	$Product->publish = 0;

	if ( $flag ) {
		$Product->status = 'publish';
		$Product->publish = null;

		if ( $datetime && $datetime > $Product->publish ) {
			$Product->publish = $datetime;
			$Product->status = 'future';
		}
	}
	$Product->save();

	return true;

}

/**
 * shopp_product_specs - get a list of the product specs for a given product
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product product id to load
 * @return array array of product specs, bool false on failure
 **/
function shopp_product_specs ( $product = false ) {
	if ( false === $product ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	$Specs = shopp_product_meta ( $product, false, 'spec' );
	foreach ( $Specs as $id => $spec) {
		$Specs[$spec->name] = $spec;
		unset($Specs[$id]);
	}

	return ! empty($Specs) ? $Specs : array();
}

/**
 * shopp_product_variants - get a list of variants for the product
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product the product id to get the variants for
 * @return array of variant Price objects, empty array if no variants, false on error
 **/
function shopp_product_variants ( $product = false, $load_by = 'id' ) {
	if ( false === $product ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	$Product = new Product($product,$load_by);
	if ( empty($Product->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Unable to load product $product.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Product->load_data(array('prices'));
	$prices = array();
	foreach( $Product->prices as $Price ) {
		if ( 'variation' != $Price->context ) continue;
		$prices[] = $Price;
	}
	return $prices;
}

/**
 * shopp_product_addons - get a list of addons for the product
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product the product id to get the addons for
 * @return array of addon Price objects, empty array if no addons, false on error
 **/
function shopp_product_addons ( $product = false, $load_by = 'id' ) {
	if ( false === $product ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	$Product = new Product($product,$load_by);
	if ( empty($Product->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Unable to load product $product.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Product->load_data(array('prices','summary'));
	$prices = array();
	foreach( $Product->prices as $Price ) {
		if ( 'addon' != $Price->context ) continue;
		$prices[] = $Price;
	}
	return $prices;
}

/**
 * shopp_product_variant - get a specific Price object
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param mixed $variant the id of the variant, or array('product'=>int, 'option' => array('menu1name'=>'option', 'menu2name'=>'option') ) to specify variant by product id and option
 * @param string $pricetype (optional default:variant) product, variant, or addon
 * @return Price Price object or false on error
 **/
function shopp_product_variant ( $variant = false, $pricetype = 'variant' ) {
	if ( false === $variant ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Variant id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	if ( is_numeric($variant) ) {
		$Price = new Price($variant);
		if ( empty($Price->id) ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Unable to load variant $variant.",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}
	} else if ( is_array($variant) ) {  // specifying variant by product id and option
		$Product = new stdClass;
		if ( isset($variant['product']) && is_numeric($variant['product']) ) {
			$Product = new Product($variant['product']);
			$Product->load_data(array('prices','meta','summary'));
		}

		if ( empty($Product->id) || empty($Product->prices) ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Unable to load variant.  Invalid Product.",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}

		$pricetype = ($pricetype == 'variant' ? 'variation' : $pricetype);
		$pricetypes = array('product', 'variation', 'addon');
		if ( ! in_array($pricetype, $pricetypes) ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Invalid pricetype.  Can be product, variant, or addon.",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}

		if ( 'product' == $pricetype ) {
			// No product context for product with variants
			if ( 'on' == $Product->variants ) {
				if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Invalid pricetype for this product.",__FUNCTION__,SHOPP_DEBUG_ERR);
				return false;
			}

			foreach ( $Product->prices as $price ) {
				if ( 'product' == $price->context ) {
					$Price = new Price();
					$Price->populate($price);
					$Price->load_settings();
					$Price->load_download();
					break;
				}
			}
		} else { // addon or variant
			if ( ! isset($variant['option']) || ! is_array($variant['option']) || empty($variant['option']) ) {
				if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Missing option array.",__FUNCTION__,SHOPP_DEBUG_ERR);
				return false;
			}

			$menukey = substr($pricetype, 0, 1);
			$flag = ($pricetype == 'variation' ? 'variants' : 'addons');

			if ( ! isset($Product->options[$menukey]) || $Product->$flag == 'off' ) {
				if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: No product variant options of type $pricetype for product {$Product->id}",__FUNCTION__,SHOPP_DEBUG_ERR);
				return false;
			}

			// build simple option menu array
			$menu = array();
			foreach ( $Product->options[$menukey] as $optionmenu ) {
				$key = $optionmenu['name'];
				$menu[$key] = array();
				foreach ( $optionmenu['options'] as $option ) {
					$menu[$key][] = $option['name'];
				}
			}

			list( $optionkey, $options, $label, $mapping ) = $Product->optionmap( $variant['option'], $menu , $pricetype );
			if ( 'variation' == $pricetype && ! isset($Product->pricekey[$optionkey]) || ! $options ) {
				if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Invalid option.",__FUNCTION__,SHOPP_DEBUG_ERR);
				return false;
			}

			if ( 'variation' == $pricetype ) $price = $Product->pricekey[$optionkey];
			else {
				// Find the option
				foreach ( $Product->prices as $price ) {
					if ( $price->context == $pricetype && $price->options == $options ) {
						break;
					}
				}
			}
			$Price = new Price;
			$Price->populate($price);
			$Price->load_settings();
			$Price->load_download();

		} // end if product type / addon/variants type
	}
	if ( ! isset($Price) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product, Variant, or Addon Price object could not be found.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	return $Price;
} // end shopp_product_variant

/**
 * shopp_product_variant_to_item
 *
 * Convert a variant Price object to an Item object
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param Price $Variant a product or variant Price object to create the item from.
 * @param int $quantity (optional default:1) quantity of the variant the Item object will represent
 * @return Item|bool Item object on success, false on failure
 **/
function shopp_product_variant_to_item ( $Variant, $quantity = 1 ) {
	$quantity = (int) $quantity;
	if ( ! $quantity ) $quantity = 1;

	if ( is_object($Variant) && is_a($Variant, 'Price') && $Variant->product && $Variant->id && in_array($Variant->context, array('product', 'variation')) ) {
		$Product = shopp_product( $Variant->product );
		$Item = new Item( $Product, $Variant->id );
		$Item->quantity($quantity);
		return $Item;
	}

	if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Variant object missing or invalid.",__FUNCTION__,SHOPP_DEBUG_ERR);
	return false;
}


/**
 * shopp_product_addon - get a specific addon Price object.
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param mixed $variant the id of the variant, or array('product'=>int, 'option' => array('addonmenu'=>'optionname') ) to specify addon by product id and option
 * @return Price Price object of the addon or false on error
 **/
function shopp_product_addon ( $addon = false ) {
	return shopp_product_variant( $addon, 'addon' );
}

/**
 * shopp_product_variant_options - get an associative array of the option types keys and array of options associated with a product
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) product id of the product to retrieve the options for
 * @return array of options, false on error or non-variant product
 **/
function shopp_product_variant_options ( $product = false ) {
	if ( false === $product ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	$Product = new Product($product);
	if ( empty($Product->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Unable to load product $product.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Product->load_data(array('summary'));

	if ( "off" == $Product->variants ) return false;

	$meta = shopp_product_meta($product, 'options');
	$v = $meta['v'];

	$options = array();
	foreach ( $v as $menus ) {
		$options[$menus['name']] = array();
		foreach ( $menus['options'] as $option ) {
			$options[$menus['name']][] = $option['name'];
		}
	}
	return $options;
}

/**
 * shopp_product_addon_options - get an associative array of the addon option groups and array of associated addon options for a product
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) product id of the product to retrieve the addon options for
 * @return array of options, false on error or product without addon options
 **/
function shopp_product_addon_options ( $product = false ) {
	if ( false === $product ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	$Product = new Product($product);
	if ( empty($Product->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Unable to load product $product.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Product->load_data(array('summary'));

	if ( "off" == $Product->addons ) return false;

	$meta = shopp_product_meta($product, 'options');
	$a = $meta['a'];

	$options = array();
	foreach ( $a as $menus ) {
		$options[$menus['name']] = array();
		foreach ( $menus['options'] as $option ) {
			$options[$menus['name']][] = $option['name'];
		}
	}
	return $options;
}


// Product-wide setters/mutators

/**
 * shopp_product_add_categories - add shopp product categories to a product
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) Product id to add the product categories to.
 * @param array $categories array of integer category term ids to add the product to.  Names are not unique.
 * @return bool true for success, false otherwise
 **/
function shopp_product_add_categories ( $product = false, $categories = array() ) {
	return shopp_product_add_terms( $product, $categories, ProductCategory::$taxon );
}

/**
 * shopp_product_add_tags - add shopp product tags to a product
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) Product id to add the product tags to.
 * @param array $tags array of tags/(tag ids) to add to the product
 * @return bool true for success, false otherwise
 **/
function shopp_product_add_tags ( $product = false, $tags = array() ) {
	return shopp_product_add_terms( $product, $tags, ProductTag::$taxon );
}

/**
 * shopp_product_add_terms - add/set taxonomical terms to a product
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) the product id to add/set the terms to
 * @param array $terms (optional default:empty) list of terms to add/set
 * @param string $taxonomy (optional default:shopp_category) name of the taxonomy to use
 * @param string $behavior (optional default:append) append to add the terms, else the terms will override what is currently set for the taxonomy
 * @return bool true on success, false on failure
 **/
function shopp_product_add_terms ( $product = false, $terms = array(), $taxonomy = 'shopp_category', $behavior = 'append' ) {
	if ( false === $product ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Product = new Product($product);
	if ( empty($Product->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id $product not found.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	if ( ! taxonomy_exists($taxonomy) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: No such taxonomy, $taxonomy.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	$taxonomy_obj = get_taxonomy($taxonomy);

	if ( is_array($terms) ) $terms = array_filter($terms);

	$behavior = ( 'append' == $behavior ? true : false ); // append or override
	$result = wp_set_post_terms( $Product->id, $terms, $taxonomy, $behavior );

	// false and WP_Error object indicates failure
	return ( false !== $result && ! ( is_object($result) && is_a($result, 'WP_Error') ) );
}

/**
 * shopp_product_set_specs - set the details/specs on a product
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) the product id to add the specs to.
 * @param array $specs (required) array of name/value pairs to add to the product
 * @return bool true on success, false on failure
 **/
function shopp_product_set_specs ( $product = false, $specs = array() ) {
	if ( empty($specs) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: No specs set.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	$success = true;
	foreach ( $specs as $name => $value ) {
		$success = $success && shopp_product_set_spec( $product, $name, $value );
	}
	return $success;
}

/**
 * shopp_product_set_spec - set a detail/spec on a product
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) the product id to set the spec on.
 * @param string $name (required) the name of the spec
 * @param string $value the value of the spec
 * @return bool true on success, false on failure
 **/
function shopp_product_set_spec ( $product = false, $name = '', $value = '' ) {
	if ( false === $product) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",shopp_product_set_spec,SHOPP_DEBUG_ERR);
		return false;
	}
	if ( empty($name) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Spec name required.",shopp_product_set_spec,SHOPP_DEBUG_ERR);
		return false;
	}
	$Product = new Product($product);
	if ( empty($Product->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id $product not found.",shopp_product_set_spec,SHOPP_DEBUG_ERR);
		return false;
	}

	return shopp_set_product_meta ( $product, $name, $value, 'spec' );
}

/**
 * shopp_product_rmv_spec - remove a spec/detail from a product
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) the product id.
 * @param string $name (required) the name of the spec to remove.
 * @return bool true on success, false on failure
 **/
function shopp_product_rmv_spec ( $product = false, $name = '' ) {
	if ( false === $product) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",shopp_product_set_spec,SHOPP_DEBUG_ERR);
		return false;
	}
	if ( empty($name) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Spec name required.",shopp_product_set_spec,SHOPP_DEBUG_ERR);
		return false;
	}
	$Product = new Product($product);
	if ( empty($Product->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id $product not found.",shopp_product_set_spec,SHOPP_DEBUG_ERR);
		return false;
	}

	return shopp_rmv_product_meta ( $product, $name, 'spec' );
}

/**
 * shopp_product_set_variant - used to configure a variant
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int|Price $variant (required) Either the id of the variant/addon/product price line, or the Price object. If passed a Price object, the modified object is returned, but not saved.
 * @param array $data (required) the data array used to configure the variant. See example below.
 * @param string $context (optional default:variant) set product, addon, or variant context
 * @return bool|Price false on failure, resulting Price object on success.
 **/
function shopp_product_set_variant ( $variant = false, $data = array(), $context = 'variant' ) {
	$context = ( 'variant' == $context ? 'variation' : $context );
	$save = true;
	if ( is_object($variant) && is_a($variant, 'Price') ) {
		$Price = $variant;
		$save = false;
	} else {
		if ( false == $variant ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Variant id required.", __FUNCTION__, SHOPP_DEBUG_ERR);
			return false;
		}
		$Price = new Price($variant);
		if ( empty($Price->id) || $Price->context != $context ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: No such $context with id $variant.", __FUNCTION__, SHOPP_DEBUG_ERR);
		}
	}

	// 'type' => 'enum',		// string - Shipped, Virtual, Download, Donation, Subscription, Disabled ( Price::types() )
	if ( ! isset($data['type']) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Required variant type missing.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	$Price->type = $data['type'];

	// 'taxed' => 'bool',		// bool - flag variant as taxable
	if ( ! isset($data['taxed']) ) $Price->tax == "on"; // default to on
	else $Price->tax = ( true == $data['taxed'] ? "on" : "off" );

	// 'price' => 'float',		// float - Price of variant
	if ( isset($data['price']) ) {
		$Price = shopp_product_variant_set_price ($Price, $data['price'], $context);
		if ( ! $Price ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Failure setting variant price.",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}
	}

	// 'sale' => 'sale',		// array - flag => bool, price => Sale price of variant
	if ( isset($data['sale']) && isset($data['sale']['flag']) ) {
		$Price = shopp_product_variant_set_saleprice ($Price, $data['sale'], isset($data['sale']['price']) ? $data['sale']['price'] : 0.0, $context );
		if ( ! $Price ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Failure setting variant sale price.",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}
	}

	// 'shipping' => 'shipping', 	// array - flag => bool, fee, weight, height, width, length
	if ( isset($data['shipping']) && isset($data['shipping']['flag']) ) {
		$Price = shopp_product_variant_set_shipping ( $Price, $data['shipping']['flag'], $data['shipping'], $context );
		if ( ! $Price ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Failure setting variant shipping settings.",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}
	}

	// 'inventory'=> 'inventory',	// array - flag => bool, stock, sku
	if ( isset($data['inventory']) && isset($data['inventory']['flag']) ) {
		$Price = shopp_product_variant_set_inventory ( $Price, $data['inventory']['flag'], $data['inventory'], $context );
		if ( ! $Price ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Failure setting variant price settings.",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}
	}

	// 'donation'=> 'donation',	// (optional - needed only for Donation type) array of settings (variable, minumum)
	if ( 'Donation' == $data['type'] ) {
		if ( ! isset($data['donation']) ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Variant $key is donation type but no donation settings exist in the data.",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}
		$Price = shopp_product_variant_set_donation ( $Price, $data['donation'], $context );
		if ( ! $Price ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Failure setting variant donation settings.",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}
	}

	// 'subscription'=>'subscription'	// (optional - needed only for Subscription type) array of subscription settings
	if ( 'Subscription' == $data['type'] ) {
		if ( ! isset($data['subscription']) ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Variant $key is subscription type, but no subscription settings exist in data.",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}
		$Price = shopp_product_variant_set_subscription ( $Price, $data['subscription'], $context );
		if ( ! $Price ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Failure setting variant subscription settings.",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}
	}

	if ( $save ) {
		return $Price->save() && shopp_set_meta ( $Price->id, 'price', 'settings', $Price->settings );
	}
	return $Price;
}

/**
 * shopp_product_set_addon - configure a addon priceline.
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int|Price $addon (required) addon id or Price object of addon
 * @param array $data (required) configuration data array for addon priceline
 * @return bool|Price false on failure, resulting Price object on success.
 **/
function shopp_product_set_addon ( $addon = false, $data = array() ) {
	return shopp_product_set_variant ( $addon, $data, 'addon' );
}

// Product-wide flags
/**
 * shopp_product_set_featured - set or unset the product as featured.
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) the product id to set
 * @param bool $flag (optional default:false) true to set as featured, false to unset featured
 * @return bool true on success, false on failure
 **/
function shopp_product_set_featured ( $product = false, $flag = false ) {
	if ( false === $product ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Product = new Product($product);
	if ( empty($Product->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id $product not found.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	return Product::featureset ( array($product), $flag ? "on" : "off");
}

/**
 * shopp_product_set_packaging - set or unset packaging override product setting. When enabled, packaging for the product is handled separately.
 * In other words, the product will always ship in a package by itself when enabled.  This setting only matters for on-line shipping add-on modules,
 * and only if they use the packaging module.
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) the product id
 * @param bool $flag (optional default:false) true to set separate packaging on the product, false to let default packaging take place
 * @return bool true on success, false on failure
 **/
function shopp_product_set_packaging ( $product = false, $flag = false ) {
	if ( false === $product ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Product = new Product($product);
	if ( empty($Product->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id $product not found.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	return shopp_set_product_meta ( $product, 'packaging', $flag ? 'on' : 'off' );

}

/**
 * shopp_product_set_processing - @todo complete after processing implementation
 *
 * @author John Dillick
 * @since
 *
 * @return void this function does nothing currently... just a stub
 **/
function shopp_product_set_processing ( $product, $flag, $settings ) {
	if ( false === $product ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Product = new Product($product);
	if ( empty($Product->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id $product not found.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	/*
		TODO implement
	*/
}

// Non-variant setters

/**
 * shopp_product_variant_set_type - set the type of a product.  Use shopp_product_variant_set_type() instead if the product has variants.
 *
 * @uses shopp_product_variant_set_type()
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) The Product id to set the type on.
 * @param string $type (optional default:N/A) The product price type, ex Shipped, Download, Virtual, Subscription.  N/A is a disabled priceline.
 * @return bool true on success, false on failure
 **/
function shopp_product_set_type ( $product = false, $type = 'N/A' ) {
	if ( false == $product ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	if ( is_array(shopp_product_variant_options($product)) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product $product has variants. Set the type using shopp_product_variant_set_type instead.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Price = new Price(array('product' => $product, 'context' => 'product'));
	if ( empty($Price->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Unable to load.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	return shopp_product_variant_set_type (  $Price->id, $type, 'product' );
}

/**
 * shopp_product_set_taxed - set whether or not a price is taxed.  Use shopp_product_variant_set_taxed() instead for products with variants.
 *
 * @uses shopp_product_variant_set_taxed()
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) The Product id to set the tax setting on.
 * @param bool $taxed true to tax variant, false to not tax
 * @return bool true on success, false on failure
 **/
function shopp_product_set_taxed ( $product = false, $taxed = true ) {
	if ( false == $product ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	if ( is_array(shopp_product_variant_options($product)) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product $product has variants. Set using shopp_product_variant_set_taxed instead.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Price = new Price(array('product' => $product, 'context' => 'product'));
	if ( empty($Price->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Unable to load.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	return shopp_product_variant_set_taxed ( $Price->id, $taxed, $context = 'product' );
}

/**
 * shopp_product_set_price - set the price of a product.  Use shopp_product_variant_set_price() instead for products with variants.
 *
 * @uses shopp_product_variant_set_price()
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) The Product id to set the price on.
 * @param float $price the price to be set
 * @return bool true on success, false on failure
 **/
function shopp_product_set_price ( $product = false, $price = 0.0 ) {
	if ( false == $product ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	if ( is_array(shopp_product_variant_options($product)) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product $product has variants. Set using shopp_product_variant_set_price instead.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Price = new Price(array('product' => $product, 'context' => 'product'));
	if ( empty($Price->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Unable to load.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	return shopp_product_variant_set_price ( $Price->id, $price, 'product' );
}

/**
 * shopp_product_set_saleprice - set the sale price of a product. Use shopp_product_variant_set_saleprice() for products with variants.
 *
 * @uses shopp_product_variant_set_saleprice()
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) The Product id to set the sale price on.
 * @param bool $flag (optional default:false) true for on, false for off. Turns on or off the sale flag on the product.  If false, price is ignored.
 * @param float $price the price to be set
 * @return bool true on success, false on failure
 **/
function shopp_product_set_saleprice ( $product = false, $flag = false, $price = 0.0 ) {
	if ( false == $product ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	if ( is_array(shopp_product_variant_options($product)) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product $product has variants. Set using shopp_product_variant_set_saleprice instead.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Price = new Price(array('product' => $product, 'context' => 'product'));
	if ( empty($Price->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Unable to load.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	return shopp_product_variant_set_saleprice ( $Price->id, $flag, $price, 'product' );
}

/**
 * shopp_product_set_shipping - turn on/off shipping charges on product with no variations
 * Use shopp_product_variant_set_shipping() for products with variants.
 *
 * @uses shopp_product_variant_set_shipping()
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) The Product id to turn setup the shipping settings on.
 * @param bool $flag (optional default:false) true for on, false for off. Turns on or off the shipping charges on the product.  If false, settings are ignored.
 * @param array $settings array of shipping dimensions (weight => float, height => float, length => float, width => float)
 * @return bool true on success, false on failure
 **/
function shopp_product_set_shipping ( $product = false, $flag = false, $settings = array() ) {
	if ( false == $product ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	if ( is_array(shopp_product_variant_options($product)) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product $product has variants. Set using shopp_product_variant_set_shipping instead.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Price = new Price(array('product' => $product, 'context' => 'product'));
	if ( empty($Price->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Unable to load.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	return shopp_product_variant_set_shipping ( $Price->id, $flag, $settings, 'product' );
}

/**
 * shopp_product_addon_set_inventory - turn on/off inventory tracking on a product and set stock and sku
 * Use shopp_product_variant_set_inventory() for products with variants.
 *
 * @uses shopp_product_variant_set_inventory()
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) The Product id to setup the inventory tracking on.
 * @param bool $flag (optional default:false) true for on, false for off. Turns on or off the inventory tracking on the product.  If false, settings are ignored.
 * @param array $settings array of inventory settings (stock => int, sku => sting)
 * @return bool true on success, false on failure
 **/
function shopp_product_set_inventory ( $product = false, $flag = false, $settings = array() ) {
	if ( false == $product ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	if ( is_array(shopp_product_variant_options($product)) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product $product has variants. Set using shopp_product_variant_set_inventory instead.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Price = new Price(array('product' => $product, 'context' => 'product'));
	if ( empty($Price->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Unable to load.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	return shopp_product_variant_set_inventory ( $Price->id, $flag, $settings, 'product' );
}

/**
 * shopp_product_set_stock - adjust stock or set stock level on a product. The stock level effects low stock warning thresholds.
 * Use shopp_product_variant_set_stock() for products with variants.
 *
 * @uses shopp_product_variant_set_stock()
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) The Product id to set stock/stock level on, or the Price object to change.  If Price object is specified, the object will be returned, but not saved to the database.
 * @param int $stock (optional default=0) The stock number to adjust/set the level to.
 * @param string $action (optional default=adjust) 'adjust' to set the product stock without setting the stock level, 'restock' to set both the product stock and stock level
 * @return bool true on success, false on failure
 **/
function shopp_product_set_stock ( $product = false, $stock = 0, $action = 'adjust' ) {
	if ( false == $product ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	if ( is_array(shopp_product_variant_options($product)) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product $product has variants. Set using shopp_product_variant_set_stock instead.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Price = new Price(array('product' => $product, 'context' => 'product'));
	if ( empty($Price->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Unable to load.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	return shopp_product_variant_set_stock ( $Price->id, $stock, $action, 'product' );
}

/**
 * shopp_product_set_donation - for donation type addons, set minimum and variable donation settings
 * Use shopp_product_variant_set_donation() for products with variants.
 *
 * @uses shopp_product_variant_set_donation()
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) The Product id to set donation settings on.
 * @param array $settings (required) The array of settings (minimum => bool, variable => bool), to set price as minimum donation flag and variable donation amounts flag.
 * @return bool true on success, false on failure
 **/
function shopp_product_set_donation ( $product = false, $settings = array() ) {
	if ( false == $product ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	if ( is_array(shopp_product_variant_options($product)) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product $product has variants. Set using shopp_product_variant_set_donation instead.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Price = new Price(array('product' => $product, 'context' => 'product'));
	if ( empty($Price->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Unable to load.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	return shopp_product_variant_set_donation ( $Price->id, $settings, 'product' );
}

/**
 * shopp_product_set_subscription - for subscription type addons, set subscription parameters.
 * Use shopp_product_variant_set_subscription() for products with variants.
 *
 * @uses shopp_product_variant_set_subscription()
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) The Product id to set subscription settings on.
 * @param array $settings (required) The array of settings. Specify any trial period pricing, and the define the billing cycle.
 * Example array( 	'trial' => array(	'price' => 0.00,	// the trial price
 * 										'cycle' => array (	'interval' => 30, // how many units of the period the trial lasts (day,week,month,year)
 * 															'period' => 'd'  // d for days, w for weeks, m for months, y for years
 * 														 )
 * 									),
 * 					'billcycle' => array(	'cycles' => 12,		// 0 for forever, int number of cycles to repeat the billing
 * 											'cycle' => array (	'interval' => 30, // how many units of the period before the next billing cycle (day,week,month,year)
 * 																'period' => 'd'  // d for days, w for weeks, m for months, y for years
 * 															 )
 * 										)
 * 				)
 * @return bool true on success, false on failure
 **/
function shopp_product_set_subscription ( $product = false, $settings = array() ) {
	if ( false == $product ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product id required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	if ( is_array(shopp_product_variant_options($product)) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product $product has variants. Set using shopp_product_variant_set_subscription instead.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Price = new Price(array('product' => $product, 'context' => 'product'));
	if ( empty($Price->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Unable to load.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	return shopp_product_variant_set_subscription ( $Price->id, $settings, 'product' );
}

/**
 * shopp_product_set_variant_options - Creates a complete set of variant product options on a specified product, by letting you
 * specify the set of options types, and corresponding options.  This function will create new variant options in the database and
 * will attach them to the specified product.
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) The product id of the product that you wish to add the variant options to.
 * @param array $options (Description...) A two dimensional array describing the options.
 * The outer array is keyed on the name of the option type (Color, Size, Gender, etc.)
 * The inner contains the corresponding option values.
 * Ex. $options = array( 'Color' => array('Red','Blue'), 'Gender' => array('Male', 'Female') );
 * @return array variant Price objects that have been created on the product.
 *
 **/
function shopp_product_set_variant_options ( $product = false, $options = array(), $summary = 'save' ) {
	if ( ! $product || empty($options) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Missing required parameters.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Product = new Product($product);
	if ( empty($Product->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product not found for product id $product.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Product->load_data( array( 'summary' ) );


	// clean up old variations
	$table = DatabaseObject::tablename(Price::$table);
	db::query("DELETE FROM $table WHERE product=$product AND context='variation'");

	$prices = array();
	$combos = _optioncombinations( array(), $options);
	$mapping = array();
	foreach ( $combos as $combo ) {
		$Price = new Price();
		$Price->type = 'Shipped';
		$Price->product = $product;
		$Price->context = 'variation';
		list( $Price->optionkey, $Price->options, $Price->label, $mapping ) = $Product->optionmap($combo, $options);
		$Price->save();
		shopp_set_meta ( $Price->id, 'price', 'options', $Price->options );
		$prices[] = $Price;
	}

	$metaopts = shopp_product_meta($product, 'options');
	$metaopts['v'] = array();

	$i = 1;
	foreach ($options as $optname => $option) {
		if ( ! isset($metaopts['v'][$i]) )
			$metaopts['v'][$i] = array('id' => $i, 'name' => $optname, 'options' => array() );

		foreach ($option as $value) {
			$metaopts['v'][$i]['options'][$mapping[$optname][$value]]
				= array('id' => $mapping[$optname][$value], 'name' => $value, 'linked' => "off");
		}

		$i++;
	}

	shopp_set_product_meta ( $product, 'options', $metaopts);

	$Product->variants = "on";
	if ( 'save' == $summary ) $Product->sumup();

	return $prices;
}

/**
 * _optioncombinations - recursive helper function to build combinations of options from a list of option type => option arrays.
 *
 * @author John Dillick
 * @since 1.2
 *
 * @return array list of all combinations for a particular set of options
 **/
function _optioncombinations ($combos=array(), $options, $menu = false, &$results = array() ) {
	$menus = array_keys($options);

	if ( $menu >= count($menus) ) {
		$results[] = $combos;
		return $results;
	} else {
		foreach ( $options[$menus[$menu]] as $option ) {
			_optioncombinations( $combos + array( $menus[$menu] => $option ) , $options, $menu + 1, $results);
		}
		return $results;
	}
}

/**
 * shopp_product_variant_set_type - set the type of a product/variant/addon
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int/Price $variant (required) The priceline id to set the type on, or the Price object to change.  If Price object is specified, the object will be returned, but not saved to the database.
 * @param string $type (optional default:N/A) The product price type, ex Shipped, Download, Virtual, Subscription.  N/A is a disabled priceline.
 * @param string $context (optional default:variant) enforces the priceline is a 'product','variant', or 'addon'
 * @return bool/Price false on failure, true if Price saved, else the modified Price object.
 **/
function shopp_product_variant_set_type ( $variant = false, $type = 'N/A', $context = 'variant' ) {
	$context = ( 'variant' == $context ? 'variation' : $context );
	$save = true;
	if ( is_object($variant) && is_a($variant, 'Price') ) {
		$Price = $variant;
		$save = false;
	} else {
		if ( false == $variant ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Variant id required.", __FUNCTION__, SHOPP_DEBUG_ERR);
			return false;
		}
		$Price = new Price($variant);
		if ( empty($Price->id) || $Price->context != $context ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: No such $context with id $variant.", __FUNCTION__, SHOPP_DEBUG_ERR);
		}
	}

	$types = array();
	foreach ( Price::types() as $t ) {
		$types[] = $t['value'];
	}

	if ( ! in_array($type, $types) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Invalid type $type.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	$Price->type = $type;
	if ( $save ) return $Price->save();
	return $Price;
}

/**
 * shopp_product_variant_set_taxed - set whether or not a price is taxed
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int/Price $variant (required) The priceline id to set the tax setting on, or the Price object to change.  If Price object is specified, the object will be returned, but not saved to the database.
 * @param bool $taxed true to tax variant, false to not tax
 * @param string $context (optional default:variant) enforces the priceline is a 'product','variant', or 'addon'
 * @return bool/Price false on failure, true if Price saved, else the modified Price object.
 **/
function shopp_product_variant_set_taxed ( $variant = false, $taxed = true, $context = 'variant' ) {
	$context = ( 'variant' == $context ? 'variation' : $context );
	$save = true;
	if ( is_object($variant) && is_a($variant, 'Price') ) {
		$Price = $variant;
		$save = false;
	} else {
		if ( false == $variant ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Variant id required.", __FUNCTION__, SHOPP_DEBUG_ERR);
			return false;
		}
		$Price = new Price($variant);
		if ( empty($Price->id) || $Price->context != $context ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: No such $context with id $variant.", __FUNCTION__, SHOPP_DEBUG_ERR);
		}
	}

	$Price->tax = ( $taxed ? "on" : "off" );

	if ( $save ) return $Price->save();
	return $Price;
}

/**
 * shopp_product_variant_set_price - set the price of a variant
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int/Price $variant (required) The priceline id to set the price on, or the Price object to change.  If Price object is specified, the object will be returned, but not saved to the database.
 * @param float $price the price to be set
 * @param string $context (optional default:variant) enforces the priceline is a 'product','variant', or 'addon'
 * @return bool/Price false on failure, true if Price saved, else the modified Price object.
 **/
function shopp_product_variant_set_price ( $variant = false, $price = 0.0, $context = 'variant' ) {
	$context = ( 'variant' == $context ? 'variation' : $context );
	$save = true;
	if ( is_object($variant) && is_a($variant, 'Price') ) {
		$Price = $variant;
		$save = false;
	} else {
		if ( false == $variant ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Variant id required.", __FUNCTION__, SHOPP_DEBUG_ERR);
			return false;
		}
		$Price = new Price($variant);
		if ( empty($Price->id) || $Price->context != $context ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: No such $context with id $variant.", __FUNCTION__, SHOPP_DEBUG_ERR);
		}
	}

	if ( shopp_setting_enabled('tax_inclusive') && isset($Price->tax) && str_true($Price->tax) ) {
		$Product = new Product($Price->product);
		$taxrate = shopp_taxrate(null,true,$Product);
		$price = ( floatvalue( $price / ( 1 + $taxrate ) ) );
	}

	$Price->price = $price;

	if ( $save ) return $Price->save();
	return $Price;
}

/**
 * shopp_product_variant_set_saleprice - set the sale price of a variant
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int/Price $variant (required) The priceline id to set the sale price on, or the Price object to change.  If Price object is specified, the object will be returned, but not saved to the database.
 * @param bool $flag (optional default:false) true for on, false for off. Turns on or off the sale flag on the variant.  If false, price is ignored.
 * @param float $price the price to be set
 * @param string $context (optional default:variant) enforces the priceline is a 'product','variant', or 'addon'
 * @return bool/Price false on failure, true if Price saved, else the modified Price object.
 **/
function shopp_product_variant_set_saleprice ( $variant = false, $flag = false, $price = 0.0, $context = 'variant' ) {
	$context = ( 'variant' == $context ? 'variation' : $context );
	$save = true;
	if ( is_object($variant) && is_a($variant, 'Price') ) {
		$Price = $variant;
		$save = false;
	} else {
		if ( false == $variant ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Variant id required.", __FUNCTION__, SHOPP_DEBUG_ERR);
			return false;
		}
		$Price = new Price($variant);
		if ( empty($Price->id) || $Price->context != $context ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: No such $context with id $variant.", __FUNCTION__, SHOPP_DEBUG_ERR);
		}
	}

	$Price->sale = "off";
	if ( $flag ) {
		$Price->sale = "on";

		if ( shopp_setting_enabled('tax_inclusive') && isset($Price->tax) && str_true($Price->tax) ) {
			$Product = new Product($Price->product);
			$taxrate = shopp_taxrate(null,true,$Product);
			$price = ( floatvalue( $price / ( 1 + $taxrate ) ) );
		}

		$Price->saleprice = $price;
	}

	if ( $save ) return $Price->save();
	return $Price;
}

/**
 * shopp_product_variant_set_shipping - turn on/off shipping charges on a variant and set shipping settings (weight and dimensions)
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int/Price $variant (required) The priceline id to turn setup the shipping settings on, or the Price object to change.  If Price object is specified, the object will be returned, but not saved to the database.
 * @param bool $flag (optional default:false) true for on, false for off. Turns on or off the shipping charges on the variant.  If false, settings are ignored.
 * @param array $settings array of shipping dimensions (weight => float, height => float, length => float, width => float)
 * @param string $context (optional default:variant) enforces the priceline is a 'product','variant', or 'addon'
 * @return bool/Price false on failure, true if Price saved, else the modified Price object.
 **/
function shopp_product_variant_set_shipping ( $variant = false, $flag = false, $settings = array(), $context = 'variant' ) {
	global $Shopp;
	$context = ( 'variant' == $context ? 'variation' : $context );
	$save = true;
	if ( is_object($variant) && is_a($variant, 'Price') ) {
		$Price = $variant;
		$save = false;
	} else {
		if ( false == $variant ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Variant id required.", __FUNCTION__, SHOPP_DEBUG_ERR);
			return false;
		}
		$Price = new Price($variant);
		if ( empty($Price->id) || $Price->context != $context ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: No such $context with id $variant.", __FUNCTION__, SHOPP_DEBUG_ERR);
			return false;
		}
	}

	$Price->shipping = "off";
	if ( $flag && ! empty($settings) ) {
		$Price->shipping = "on";
		if ( isset($settings['weight']) ) {
			if ( 0.0 >= $settings['weight'] ) {
				if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Weight must be greater than 0.",__FUNCTION__,SHOPP_DEBUG_ERR);
				return false;
			}

			$Price->weight = $settings['weight'];
			$Price->dimensions = array('weight' => $settings['weight'], 'height' => 0, 'width' => 0, 'length' => 0);

			if ( ! isset($Price->settings) ) $Price->settings = array();
			$Price->settings['dimensions'] = $Price->dimensions;
		}

		if ( isset($settings['height']) && isset($settings['width']) && isset($settings['length']) ) {
			if ( 0.0 >= $settings['height'] || 0.0 >= $settings['width'] || 0.0 >= $settings['length'] ) {
				if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Height, width, and length must be greater than 0.",__FUNCTION__,SHOPP_DEBUG_ERR);
				return false;
			}

			$Price->dimensions = array('weight' => $settings['weight'], 'height' => $settings['height'], 'width' => $settings['width'], 'length' => $settings['length']);

			if ( ! isset($Price->settings) ) $Price->settings = array();
			$Price->settings['dimensions'] = $Price->dimensions;
		} else if ( $Shopp->Shipping->dimensions ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Height, width, and length are required for one or more installed shipping module.",__FUNCTION__,SHOPP_DEBUG_ERR);
			return false;
		}
		if ( isset($settings['fee']) ) $Price->shipfee = $settings['fee'];
	}

	if ( $save ) {
		return $Price->save() && shopp_set_meta ( $Price->id, 'price', 'settings', $Price->settings );
	}

	return $Price;
}

/**
 * shopp_product_variant_set_inventory - turn on/off inventory tracking on a variant and set stock and sku
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int/Price $variant (required) The priceline id to setup the inventory tracking on, or the Price object to change.  If Price object is specified, the object will be returned, but not saved to the database.
 * @param bool $flag (optional default:false) true for on, false for off. Turns on or off the inventory tracking on the variant.  If false, settings are ignored.
 * @param array $settings array of inventory settings (stock => int, sku => sting)
 * @param string $context (optional default:variant) enforces the priceline is a 'product','variant', or 'addon'
 * @return bool/Price false on failure, true if Price saved, else the modified Price object.
 **/
function shopp_product_variant_set_inventory ( $variant = false, $flag = false, $settings = array(), $context = 'variant' ) {
	$context = ( 'variant' == $context ? 'variation' : $context );
	$save = true;
	if ( is_object($variant) && is_a($variant, 'Price') ) {
		$Price = $variant;
		$save = false;
	} else {
		if ( false == $variant ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Variant id required.", __FUNCTION__, SHOPP_DEBUG_ERR);
			return false;
		}
		$Price = new Price($variant);
		if ( empty($Price->id) || $Price->context != $context ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: No such $context with id $variant.", __FUNCTION__, SHOPP_DEBUG_ERR);
		}
	}

	$Price->inventory = "off";
	if ( $flag ) {
		$Price->inventory = "on";
		if ( isset($settings['stock']) ) {
			$Price = shopp_product_variant_set_stock( $Price, $settings['stock'], 'restock', $context );
		}
		if ( isset($settings['sku']) ) {
			$Price->sku = $settings['sku'];
		}
	}

	if ( $save ) return $Price->save();
	return $Price;
}

/**
 * shopp_product_variant_set_stock - adjust stock or set stock level on a variant. The stock level effects low stock warning thresholds.
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int/Price $variant (required) The priceline id to set stock/stock level on, or the Price object to change.  If Price object is specified, the object will be returned, but not saved to the database.
 * @param int $stock (optional default=0) The stock number to adjust/set the level to.
 * @param string $action (optional default=adjust) 'adjust' to set the variant stock without setting the stock level, 'restock' to set both the variant stock and stock level
 * @param string $context (optional default:variant) enforces the priceline is a 'product','variant', or 'addon'
 * @return bool/Price false on failure, true if Price saved, else the modified Price object.
 **/
function shopp_product_variant_set_stock ( $variant = false, $stock = 0, $action = 'adjust', $context = 'variant' ) {
	$context = ( 'variant' == $context ? 'variation' : $context );
	$save = true;
	if ( is_object($variant) && is_a($variant, 'Price') ) {
		$Price = $variant;
		$save = false;
	} else {
		if ( false == $variant ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Variant id required.", __FUNCTION__, SHOPP_DEBUG_ERR);
			return false;
		}
		$Price = new Price($variant);
		if ( empty($Price->id) || $Price->context != $context ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: No such $context with id $variant.", __FUNCTION__, SHOPP_DEBUG_ERR);
		}
	}

	$Price->stock = $stock;
	if ( 'restock' == $action ) {
		$Price->modified = 0;
		$Price->stocked = $stock;
	}

	if ( $save ) return $Price->save();
	return $Price;
}

/**
 * shopp_product_variant_set_donation - for donation type variants, set minimum and variable donation settings
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int/Price $variant (required) The priceline id to set donation settings on, or the Price object to change.  If Price object is specified, the object will be returned, but not saved to the database.
 * @param array $settings (required) The array of settings (minimum => bool, variable => bool), to set price as minimum donation flag and variable donation amounts flag.
 * @param string $context (optional default:variant) enforces the priceline is a 'product','variant', or 'addon'
 * @return bool/Price false on failure, true if Price saved, else the modified Price object.
 **/
function shopp_product_variant_set_donation ( $variant = false, $settings = array(), $context = 'variant' ) {
	$context = ( 'variant' == $context ? 'variation' : $context );
	$save = true;
	if ( is_object($variant) && is_a($variant, 'Price') ) {
		$Price = $variant;
		$save = false;
	} else {
		if ( false == $variant ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Variant id required.", __FUNCTION__, SHOPP_DEBUG_ERR);
			return false;
		}
		$Price = new Price($variant);
		if ( empty($Price->id) || $Price->context != $context ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: No such $context with id $variant.", __FUNCTION__, SHOPP_DEBUG_ERR);
		}
	}
	if ( 'Donation' != $Price->type ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Variant $variant is not Donation type.  Use shopp_product_variant_set_type to set. ",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	$variant_settings = shopp_meta( $Price->id, 'price', 'settings' );
	if ( ! is_array($variant_settings) ) {
		$variant_settings = array();
	}

	if ( ! isset($variant_settings['donation']) ) {
		$variant_settings['donation'] = array();
	}

	$variant_settings['donation']['var'] = ( isset($settings['variable']) && $settings['variable'] ? "on" : "off" );
	$variant_settings['donation']['min'] = ( isset($settings['minimum']) && $settings['minimum'] ? "on" : "off" );

	$Price->donation = $variant_settings['donation'];
	$Price->settings = $variant_settings;

	if ( $save ) {
		return shopp_set_meta ( $Price->id, 'price', 'settings', $variant_settings );
	}
	return $Price;
}

/**
 * shopp_product_variant_set_subscription - for subscription type variants, set subscription parameters.
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int/Price $variant (required) The priceline id to set donation settings on, or the Price object to change.  If Price object is specified, the object will be returned, but not saved to the database.
 * @param array $settings (required) The array of settings. Specify any trial period pricing, and the define the billing cycle.
 * Example array( 	'trial' => array(	'price' => 0.00,	// the trial price
 * 										'cycle' => array (	'interval' => 30, // how many units of the period the trial lasts (day,week,month,year)
 * 															'period' => 'd'  // d for days, w for weeks, m for months, y for years
 * 														 )
 * 									),
 * 					'billcycle' => array(	'cycles' => 12,		// 0 for forever, int number of cycles to repeat the billing
 * 											'cycle' => array (	'interval' => 30, // how many units of the period before the next billing cycle (day,week,month,year)
 * 																'period' => 'd'  // d for days, w for weeks, m for months, y for years
 * 															 )
 * 										)
 * 				)
 * @param string $context (optional default:variant) enforces the priceline is a 'product','variant', or 'addon'
 * @return bool/Price false on failure, true if Price saved, else the modified Price object.
 **/
function shopp_product_variant_set_subscription ( $variant = false, $settings = array(), $context = 'variant' ) {
	$context = ( 'variant' == $context ? 'variation' : $context );
	$save = true;
	if ( is_object($variant) && is_a($variant, 'Price') ) {
		$Price = $variant;
		$save = false;
	} else {
		if ( false == $variant ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Variant id required.", __FUNCTION__, SHOPP_DEBUG_ERR);
			return false;
		}
		$Price = new Price($variant);
		if ( empty($Price->id) || $Price->context != $context ) {
			if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: No such $context with id $variant.", __FUNCTION__, SHOPP_DEBUG_ERR);
		}
	}
	if ( 'Subscription' != $Price->type ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Variant $variant is not Subscription type.  Use shopp_product_variant_set_type to set. ",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	$Price->trial = "off";

	if ( ! empty($Price->id) ) $variant_settings = shopp_meta( $Price->id, 'price', 'settings' );
	if ( ! is_array($variant_settings) ) {
		$variant_settings = array();
	}

	if ( isset($settings['trial']) && is_array($settings['trial']) && ! empty($settings['trial']) ) {
		$Price->trial = "on";
		$variant_settings['recurring']['trial'] = "on";
		foreach ( $settings['trial'] as $name => $setting ) {
			if ( is_array($setting) && empty($setting) ) continue;

			switch ( $name ) {
				case "price":
					$variant_settings['recurring']['trialprice'] = $setting;
					break;
				case "cycle":
					$variant_settings['recurring']['trialint'] = $setting['interval'];
					$variant_settings['recurring']['trialperiod'] = $setting['period'];
					break;
			}
		}
	}

	if ( ! isset($settings['billcycle']) || empty($settings['billcycle']) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Billing cycle required.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	foreach ( $settings['billcycle'] as $name => $setting ) {
		if ( is_array($setting) && empty($setting) ) continue;

		switch ( $name ) {
			case "cycle":
				$variant_settings['recurring']['interval'] = $setting['interval'];
				$variant_settings['recurring']['period'] = $setting['period'];
				break;
			case "cycles":
				$variant_settings['recurring']['cycles'] = $setting;
				break;
		}
	}

	foreach ( $variant_settings['recurring'] as $property => $setting ) {
		$Price->{$property} = $setting;
	}
	$Price->settings = $variant_settings;

	if ( $save ) {
		return shopp_set_meta ( $Price->id, 'price', 'settings', $Price->settings );
	}
	return $Price;
}

// Addon setters

/**
 * shopp_product_set_addon_options - Creates a complete set of addon product options on a specified product, by letting you
 * specify the set of options types, and corresponding options.  This function will create new addon options in the database and
 * will attach them to the specified product.
 *
 * @author John Dillick
 * @since 1.2
 *
 * @param int $product (required) The product id of the product that you wish to add the addon options to.
 * @param array $options (Description...) A two dimensional array describing the addon options.
 * The outer array is keyed on the name of the option type (Framing, Matting, Glass, etc.)
 * The inner contains the corresponding option values.
 * Ex. $options = array( 'Framing' => array('Wood', 'Gold'), 'Glass' => array('Anti-glare', 'UV Protectant') );
 * @return array addon Price objects that have been created on the product.
 *
 **/
function shopp_product_set_addon_options ( $product = false, $options = array(), $summary = 'save' ) {
	if ( ! $product || empty($options) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Missing required parameters.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}

	$Product = new Product($product);
	if ( empty($Product->id) ) {
		if(SHOPP_DEBUG) new ShoppError(__FUNCTION__." failed: Product not found for product id $product.",__FUNCTION__,SHOPP_DEBUG_ERR);
		return false;
	}
	$Product->load_data( array( 'summary' ) );


	// clean up old variations
	$table = DatabaseObject::tablename(Price::$table);
	db::query("DELETE FROM $table WHERE product=$product AND context='addon'");

	$prices = array();
	$mapping = array();

	foreach ( $options as $type => $opts ) {
		foreach ( $opts as $option ) {
			$addon = array($type => $option );

			$Price = new Price();
			$Price->type = 'Shipped';
			$Price->product = $product;
			$Price->context = 'addon';
			list( $Price->optionkey, $Price->options, $Price->label, $mapping ) = $Product->optionmap($addon, $options, 'addon');
			$Price->save();
			shopp_set_meta ( $Price->id, 'price', 'options', $Price->options );
			$prices[] = $Price;
		}
	}

	$metaopts = shopp_product_meta($product, 'options');
	$metaopts['a'] = array();

	$i = 1;
	foreach ($options as $optname => $option) {
		if ( ! isset($metaopts['a'][$i]) )
			$metaopts['a'][$i] = array('id' => $i, 'name' => $optname, 'options' => array() );

		foreach ($option as $value) {
			$metaopts['a'][$i]['options'][$mapping[$optname][$value]]
				= array('id' => $mapping[$optname][$value], 'name' => $value, 'linked' => "off");
		}

		$i++;
	}

	shopp_set_product_meta ( $product, 'options', $metaopts);

	$Product->addons = "on";
	if ( 'save' == $summary ) $Product->sumup();

	return $prices;
}

/**
 * shopp_product_variant_set_type - set the type of a addon
 *
 * @uses shopp_product_variant_set_type()
 * @author John Dillick
 * @since 1.2
 *
 * @param int/Price $addon (required) The priceline id to set the type on, or the Price object to change.  If Price object is specified, the object will be returned, but not saved to the database.
 * @param string $type (optional default:N/A) The product price type, ex Shipped, Download, Virtual, Subscription.  N/A is a disabled priceline.
 * @param string $context (optional default:addon) enforces the priceline is a 'product','variant', or 'addon'
 **/
function shopp_product_addon_set_type ( $addon = false, $type = 'N/A' ) {
	return shopp_product_variant_set_type (  $addon, $type, 'addon' );
}

/**
 * shopp_product_addon_set_taxed - set whether or not a price is taxed
 *
 * @uses shopp_product_variant_set_taxed()
 * @author John Dillick
 * @since 1.2
 *
 * @param int/Price $addon (required) The priceline id to set the tax setting on, or the Price object to change.  If Price object is specified, the object will be returned, but not saved to the database.
 * @param bool $taxed true to tax addon, false to not tax
 * @return bool/Price false on failure, true if Price saved, else the modified Price object.
 **/
function shopp_product_addon_set_taxed ( $addon = false, $taxed = true ) {
	return shopp_product_variant_set_taxed ( $addon, $taxed, 'addon' );
}

/**
 * shopp_product_addon_set_price - set the price of a addon
 *
 * @uses shopp_product_variant_set_price()
 * @author John Dillick
 * @since 1.2
 *
 * @param int/Price $addon (required) The priceline id to set the price on, or the Price object to change.  If Price object is specified, the object will be returned, but not saved to the database.
 * @param float $price the price to be set
 * @return bool/Price false on failure, true if Price saved, else the modified Price object.
 **/
function shopp_product_addon_set_price ( $addon = false, $price = 0.0 ) {
	return shopp_product_variant_set_price ( $addon, $price, 'addon' );
}

/**
 * shopp_product_addon_set_saleprice - set the sale price of a addon
 *
 * @uses shopp_product_variant_set_saleprice()
 * @author John Dillick
 * @since 1.2
 *
 * @param int/Price $addon (required) The priceline id to set the sale price on, or the Price object to change.  If Price object is specified, the object will be returned, but not saved to the database.
 * @param bool $flag (optional default:false) true for on, false for off. Turns on or off the sale flag on the addon.  If false, price is ignored.
 * @param float $price the price to be set
 * @return bool/Price false on failure, true if Price saved, else the modified Price object.
 **/
function shopp_product_addon_set_saleprice ( $addon = false, $flag = false, $price = 0.0 ) {
	return shopp_product_variant_set_saleprice ( $addon, $flag, $price, 'addon' );
}

/**
 * shopp_product_addon_set_shipping - turn on/off shipping charges on a addon and set shipping settings (weight and dimensions)
 *
 * @uses shopp_product_variant_set_shipping()
 * @author John Dillick
 * @since 1.2
 *
 * @param int/Price $addon (required) The priceline id to turn setup the shipping settings on, or the Price object to change.  If Price object is specified, the object will be returned, but not saved to the database.
 * @param bool $flag (optional default:false) true for on, false for off. Turns on or off the shipping charges on the addon.  If false, settings are ignored.
 * @param array $settings array of shipping dimensions (weight => float, height => float, length => float, width => float)
 * @return bool/Price false on failure, true if Price saved, else the modified Price object.
 **/
function shopp_product_addon_set_shipping ( $addon = false, $flag = false, $settings = array() ) {
	return shopp_product_variant_set_shipping ( $addon, $flag, $settings, 'addon' );
}

/**
 * shopp_product_addon_set_stock - adjust stock or set stock level on a addon. The stock level effects low stock warning thresholds.
 *
 * @uses shopp_product_variant_set_stock()
 * @author John Dillick
 * @since 1.2
 *
 * @param int/Price $addon (required) The priceline id to set stock/stock level on, or the Price object to change.  If Price object is specified, the object will be returned, but not saved to the database.
 * @param int $stock (optional default=0) The stock number to adjust/set the level to.
 * @param string $action (optional default=adjust) 'adjust' to set the addon stock without setting the stock level, 'restock' to set both the addon stock and stock level
 * @return bool/Price false on failure, true if Price saved, else the modified Price object.
 **/
function shopp_product_addon_set_stock ( $addon = false, $stock = 0, $action = 'adjust' ) {
	return shopp_product_variant_set_stock ( $addon, $stock, $action, 'addon' );
}

/**
 * shopp_product_addon_set_inventory - turn on/off inventory tracking on a addon and set stock and sku
 *
 * @uses shopp_product_variant_set_inventory()
 * @author John Dillick
 * @since 1.2
 *
 * @param int/Price $addon (required) The priceline id to setup the inventory tracking on, or the Price object to change.  If Price object is specified, the object will be returned, but not saved to the database.
 * @param bool $flag (optional default:false) true for on, false for off. Turns on or off the inventory tracking on the addon.  If false, settings are ignored.
 * @param array $settings array of inventory settings (stock => int, sku => sting)
 * @return bool/Price false on failure, true if Price saved, else the modified Price object.
 **/
function shopp_product_addon_set_inventory ( $addon = false, $flag = false, $settings = array() ) {
	return shopp_product_variant_set_inventory ( $addon, $flag, $settings, 'addon' );
}

/**
 * shopp_product_addon_set_donation - for donation type addons, set minimum and variable donation settings
 *
 * @uses shopp_product_variant_set_donation()
 * @author John Dillick
 * @since 1.2
 *
 * @param int/Price $addon (required) The priceline id to set donation settings on, or the Price object to change.  If Price object is specified, the object will be returned, but not saved to the database.
 * @param array $settings (required) The array of settings (minimum => bool, variable => bool), to set price as minimum donation flag and variable donation amounts flag.
 * @return bool/Price false on failure, true if Price saved, else the modified Price object.
 **/
function shopp_product_addon_set_donation ( $addon = false, $settings = array() ) {
	return shopp_product_variant_set_donation ( $addon, $settings, 'addon' );
}

/**
 * shopp_product_addon_set_subscription - for subscription type addons, set subscription parameters.
 *
 * @uses shopp_product_variant_set_subscription()
 * @author John Dillick
 * @since 1.2
 *
 * @param int/Price $addon (required) The priceline id to set donation settings on, or the Price object to change.  If Price object is specified, the object will be returned, but not saved to the database.
 * @param array $settings (required) The array of settings. Specify any trial period pricing, and the define the billing cycle.
 * Example array( 	'trial' => array(	'price' => 0.00,	// the trial price
 * 										'cycle' => array (	'interval' => 30, // how many units of the period the trial lasts (day,week,month,year)
 * 															'period' => 'd'  // d for days, w for weeks, m for months, y for years
 * 														 )
 * 									),
 * 					'billcycle' => array(	'cycles' => 12,		// 0 for forever, int number of cycles to repeat the billing
 * 											'cycle' => array (	'interval' => 30, // how many units of the period before the next billing cycle (day,week,month,year)
 * 																'period' => 'd'  // d for days, w for weeks, m for months, y for years
 * 															 )
 * 										)
 * 				)
 * @return bool/Price false on failure, true if Price saved, else the modified Price object.
 **/
function shopp_product_addon_set_subscription ( $addon = false, $settings = array() ) {
	return shopp_product_variant_set_subscription ( $addon, $settings, 'addon' );
}

?>