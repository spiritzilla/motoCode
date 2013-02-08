<?php
/**
 * Order Rates
 *
 * Provides flat rates on entire order
 *
 * @author Jonathan Davis
 * @version 1.2
 * @copyright Ingenesis Limited, June 14, 2011
 * @package shopp
 * @since 1.2
 * @subpackage OrderRates
 *
 **/

class OrderRates extends ShippingFramework implements ShippingModule {

	function methods () {
		return __('Flat Order Rates','Shopp');
	}

	function init () { /* Not implemented */ }

	function calcitem ($id,$Item) { /* Not implemented */  }

	function calculate (&$options,$Order) {

		foreach ($this->methods as $slug => $method) {

			$amount = $this->tablerate($method['table']);
			if ($amount === false) continue; // Skip methods that don't match at all

			$rate = array(
				'slug' => $slug,
				'name' => $method['label'],
				'amount' => $amount,
				'delivery' => $this->delivery($method),
				'items' => false
			);

			$options[$slug] = new ShippingOption($rate);

		}

		return $options;
	}

	function settings () {

		$this->ui->flatrates(0,array(
			'table' => $this->settings['table']
		));

	}

} // END class OrderRates
?>