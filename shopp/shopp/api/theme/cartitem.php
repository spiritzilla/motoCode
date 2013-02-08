<?php
/**
* ShoppCartItemThemeAPI - Provided theme api tags.
*
* @version 1.0
* @since 1.2
* @package shopp
* @subpackage ShoppCartItemThemeAPI
*
**/

/**
 * Provides support for the shopp('cartitem') tags
 *
 * @author Jonathan Davis, John Dillick
 * @since 1.2
 *
 **/
class ShoppCartItemThemeAPI {
	static $register = array(
		'_cartitem',
		'id' => 'id',
		'product' => 'product',
		'name' => 'name',
		'type' => 'type',
		'link' => 'url',
		'url' => 'url',
		'sku' => 'sku',
		'description' => 'description',
		'discount' => 'discount',
		'unitprice' => 'unitprice',
		'unittax' => 'unittax',
		'discounts' => 'discounts',
		'tax' => 'tax',
		'total' => 'total',
		'taxrate' => 'taxrate',
		'quantity' => 'quantity',
		'remove' => 'remove',
		'optionlabel' => 'option_label',
		'options' => 'options',
		'hasaddons' => 'has_addons',
		'addons' => 'addons',
		'addon' => 'addon',
		'addonslist' => 'addons_list',
		'hasinputs' => 'has_inputs',
		'incategory' => 'in_category',
		'inputs' => 'inputs',
		'input' => 'input',
		'inputslist' => 'inputs_list',
		'coverimage' => 'coverimage',
		'thumbnail' => 'coverimage'
	);

	static function _apicontext () { return 'cartitem'; }

	/**
	 * _setobject - returns the global context object used in the shopp('cartitem) call
	 *
	 * @author John Dillick
	 * @since 1.2
	 *
	 **/
	static function _setobject ( $Object, $object ) {
		if ( is_object($Object) && is_a($Object, 'Item') ) return $Object;

		if (strtolower($object) != 'cartitem') return $Object; // not mine, do nothing
		else {
			$Order =& ShoppOrder();
			$Cart =& $Order->Cart;
			$Item = false;
			if (isset($Cart->_item_loop)) { $Item = current($Cart->contents); $Item->_id = key($Cart->contents); return $Item; }
			elseif (isset($Cart->_shipped_loop)) { $Item = current($Cart->shipped); $Item->_id = key($Cart->shipped); return $Item; }
			elseif (isset($Cart->_downloads_loop)) { $Item = current($Cart->downloads); $Item->_id = key($Cart->downloads); return $Item; }
			return false;
		}
	}

	static function _cartitem ($result, $options, $property, $O) {
		if (is_float($result)) {
			if (isset($options['currency']) && !value_is_true($options['currency'])) return $result;
			else return money($result);
		}
		return $result;
	}

	static function id ($result, $options, $O) { return $O->_id; }

	static function product ($result, $options, $O) { return $O->product; }

	static function name ($result, $options, $O) { return $O->name; }

	static function type ($result, $options, $O) { return $O->type; }

	static function url ($result, $options, $O) { return shoppurl( '' == get_option('permalink_structure')?array(Product::$posttype=>$O->slug):$O->slug, false ); }

	static function sku ($result, $options, $O) { return $O->sku; }

	static function description ($result, $options, $O) { return $O->description; }

	static function discount ($result, $options, $O) { return (float) $O->discount; }

	static function unitprice ($result, $options, $O) {
		$taxes = isset( $options['taxes'] ) ? str_true( $options['taxes'] ) : self::_include_tax($O);
		return (float) $O->unitprice + ( $taxes ? $O->unittax : 0 );
	}

	static function unittax ($result, $options, $O) { return (float) $O->unittax; }

	static function discounts ($result, $options, $O) { return (float) $O->discounts; }

	static function tax ($result, $options, $O) { return (float) $O->tax; }

	static function total ($result, $options, $O) {
		$taxes = isset( $options['taxes'] ) ? str_true( $options['taxes'] ) : self::_include_tax($O);
		return (float) $O->total + ( $taxes ? ( $O->unittax * $O->quantity ) : 0 );
	}

	static function taxrate ($result, $options, $O) { return percentage( $O->taxrate * 100, array( 'precision' => 1 ) ); }

	static function quantity ($result, $options, $O) {
		$result = $O->quantity;
		if ($O->type == "Donation" && $O->donation['var'] == "on") return $result;
		if ($O->type == "Subscription" || $O->type == "Membership") return $result;
		if ('Download' == $O->type && !shopp_setting_enabled('download_quantity')) return $result;
		if (isset($options['input']) && $options['input'] == "menu") {
			if (!isset($options['value'])) $options['value'] = $O->quantity;
			if (!isset($options['options']))
				$values = "1-15,20,25,30,35,40,45,50,60,70,80,90,100";
			else $values = $options['options'];

			if (strpos($values,",") !== false) $values = explode(",",$values);
			else $values = array($values);
			$qtys = array();
			foreach ($values as $value) {
				if (strpos($value,"-") !== false) {
					$value = explode("-",$value);
					if ($value[0] >= $value[1]) $qtys[] = $value[0];
					else for ($i = $value[0]; $i < $value[1]+1; $i++) $qtys[] = $i;
				} else $qtys[] = $value;
			}
			$result = '<select name="items['.$O->_id.'][quantity]">';
			foreach ($qtys as $qty)
				$result .= '<option'.(($qty == $O->quantity)?' selected="selected"':'').' value="'.$qty.'">'.$qty.'</option>';
			$result .= '</select>';
		} elseif (isset($options['input']) && valid_input($options['input'])) {
			if (!isset($options['size'])) $options['size'] = 5;
			if (!isset($options['value'])) $options['value'] = $O->quantity;
			$result = '<input type="'.$options['input'].'" name="items['.$O->_id.'][quantity]" id="items-'.$O->_id.'-quantity" '.inputattrs($options).'/>';
		} else $result = $O->quantity;
		return $result;
	}

	static function remove ($result, $options, $O) {
		$label = __("Remove");
		if (isset($options['label'])) $label = $options['label'];
		if (isset($options['class'])) $class = ' class="'.$options['class'].'"';
		else $class = ' class="remove"';
		if (isset($options['input'])) {
			switch ($options['input']) {
				case "button":
					$result = '<button type="submit" name="remove['.$O->_id.']" value="'.$O->_id.'"'.$class.' tabindex="">'.$label.'</button>'; break;
				case "checkbox":
				    $result = '<input type="checkbox" name="remove['.$O->_id.']" value="'.$O->_id.'"'.$class.' tabindex="" title="'.$label.'"/>'; break;
			}
		} else {
			$result = '<a href="'.href_add_query_arg(array('cart'=>'update','item'=>$O->_id,'quantity'=>0),shoppurl(false,'cart')).'"'.$class.'>'.$label.'</a>';
		}
		return $result;
	}

	static function option_label ($result, $options, $O) { return $O->option->label; }

	static function options ($result, $options, $O) {
		$class = "";
		if (!isset($options['before'])) $options['before'] = '';
		if (!isset($options['after'])) $options['after'] = '';
		if (isset($options['show']) &&
			strtolower($options['show']) == "selected")
			return (!empty($O->option->label))?
				$options['before'].$O->option->label.$options['after']:'';

		if (isset($options['class'])) $class = ' class="'.$options['class'].'" ';
		if (count($O->variants) > 1) {
			$result .= $options['before'];
			$result .= '<input type="hidden" name="items['.$O->_id.'][product]" value="'.$O->product.'"/>';
			$result .= ' <select name="items['.$O->_id.'][price]" id="items-'.$O->_id.'-price"'.$class.'>';
			$result .= $O->options($O->priceline);
			$result .= '</select>';
			$result .= $options['after'];
		}
		return $result;
	}

	static function has_addons ($result, $options, $O) { return (count($O->addons) > 0); }

	static function addons ($result, $options, $O) {
		if (!isset($O->_addons_loop)) {
			reset($O->addons);
			$O->_addons_loop = true;
		} else next($O->addons);

		if (current($O->addons) !== false) return true;

		unset($O->_addons_loop);
		reset($O->addons);
		return false;
	}

	static function addon ($result, $options, $O) {
		if (empty($O->addons)) return false;
		$addon = current($O->addons);
		$defaults = array(
			'separator' => ' '
		);
		$options = array_merge($defaults,$options);

		$fields = array('id','type','label','sale','saleprice','price',
						'inventory','stock','sku','weight','shipfee','unitprice');

		$fieldset = array_intersect($fields,array_keys($options));
		if (empty($fieldset)) $fieldset = array('label');

		$_ = array();
		foreach ($fieldset as $field) {
			switch ($field) {
				case 'weight': $_[] = $addon->dimensions['weight'];
				case 'saleprice':
				case 'price':
				case 'shipfee':
				case 'unitprice':
					if ('saleprice' == $field) $field = 'promoprice';
					if (isset($addon->$field)) {
						$_[] = (isset($options['currency']) && str_true($options['currency'])) ?
							 money($addon->$field) : $addon->$field;
					}
					break;
				default:
					if (isset($addon->$field))
						$_[] = $addon->$field;
			}


		}
		return join($options['separator'],$_);
	}

	static function addons_list ($result, $options, $O) {
		if (empty($O->addons)) return false;
		$defaults = array(
			'before' => '',
			'after' => '',
			'class' => '',
			'exclude' => '',
			'prices' => true,
			'taxes' => shopp_setting('tax_inclusive')

		);
		$options = array_merge($defaults,$options);
		extract($options);

		$classes = !empty($class)?' class="'.esc_attr($class).'"':'';
		$excludes = explode(',',$exclude);
		$prices = str_true($prices);
		$taxes = str_true($taxes);

		$result .= $before.'<ul'.$classes.'>';
		foreach ($O->addons as $id => $addon) {
			if (in_array($addon->label,$excludes)) continue;
			$price = (str_true($addon->sale)?$addon->promoprice:$addon->price);
			if ($taxes && $O->taxrate > 0)
				$price = $price+($price*$O->taxrate);

			if ($prices) $pricing = " (".($addon->price < 0?'-':'+').money($price).")";
			$result .= '<li>'.$addon->label.$pricing.'</li>';
		}
		$result .= '</ul>'.$after;
		return $result;
	}

	static function has_inputs ($result, $options, $O) { return (count($O->data) > 0); }

	static function in_category ($result, $options, $O) {
		if (empty($O->categories)) return false;
		if (isset($options['id'])) $field = "id";
		if (isset($options['name'])) $field = "name";
		foreach ($O->categories as $id => $name) {
			switch (strtolower($field)) {
				case 'id': if ($options['id'] == $id) return true;
				case 'name': if ($options['name'] == $name) return true;
			}
		}
		return false;
	}

	static function inputs ($result, $options, $O) {
		if (!isset($O->_data_loop)) {
			reset($O->data);
			$O->_data_loop = true;
		} else next($O->data);

		if (current($O->data) !== false) return true;

		unset($O->_data_loop);
		reset($O->data);
		return false;
	}

	static function input ($result, $options, $O) {
		$data = current($O->data);
		$name = key($O->data);
		if (isset($options['name'])) return $name;
		return $data;
	}

	static function inputs_list ($result, $options, $O) {
		if (empty($O->data)) return false;
		$defaults = array(
			'class' => '',
			'exclude' => array(),
			'before' => '',
			'after' => '',
			'separator' => '<br />'
		);
		$options = array_merge($defaults,$options);
		extract($options);

		if (!empty($exclude)) $exclude = explode(',',$exclude);

		$classes = '';
		if (!empty($class)) $classes = ' class="'.$class.'"';

		$result .= $before.'<ul'.$classes.'>';
		foreach ($O->data as $name => $data) {
			if (in_array($name,$exclude)) continue;
			if (is_array($data)) $data = join($separator,$data);
			$result .= '<li><strong>'.$name.'</strong>: '.$data.'</li>';
		}
		$result .= '</ul>'.$after;
		return $result;
	}

	static function coverimage ($result, $options, $O) {
		if ( false === $O->image ) return false;
		$O->images = array($O->image);
		$options['index'] = 0;
		return ShoppCatalogThemeAPI::image($result, $options, $O);
	}

	static function _include_tax ($O) {
		return (
			shopp_setting_enabled('tax_inclusive') &&
			$O->istaxed &&
			$O->unittax > 0 &&
			!$O->excludetax
		);
	}

}

?>