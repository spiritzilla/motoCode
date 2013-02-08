<?php
/**
 * Sage Pay Form
 * @class SagePayForm
 *
 * @author Clifton H. Griffin II
 * @version 1.0
 * @copyright 2012 (c) Clifton Griffin Web Development
 * @license GNU GPL version 3 (or later) {@see shopp/license.txt}
 * @package Shopp
 * @since 1.2
 * @subpackage SagePayForm
 *
 *  Portions created by Clifton Griffin are Copyright © 2012 by Clifton H. Griffin II
 *
 *  SagePayForm is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  SagePayForm is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with Shopp.  If not, see <http://www.gnu.org/licenses/>.
 *
**/

class SagePayForm extends GatewayFramework implements GatewayModule {

	// Settings
	var $secure = false; // do not require SSL or session encryption
	var $saleonly = true; // force sale event on processing (no auth)


	var $live_url = "https://live.sagepay.com/gateway/service/vspform-register.vsp";
	var $test_url = "https://test.sagepay.com/gateway/service/vspform-register.vsp";
	var $sim_url = "https://test.sagepay.com/simulator/vspformgateway.asp";

	var $protocol = "2.23";
	var $encryption = "AES";
	var $transaction_type = "PAYMENT";
	var $vendor_email = "";

	// Configurable
	var $vendor = "";
	var $password = "";
	var $currency = ""; //GBP
	var $send_email = ""; // 0 neither, 1 both, 2 vendor only


	function __construct () {
		parent::__construct();

		$this->vendor_email = shopp_setting('merchant_email');
		$this->vendor = $this->settings['vendor'];
		$this->password = $this->settings['password'];
		$this->send_email = $this->settings['receipt_preference'];

		if ($this->baseop['currency'] && !empty($this->baseop['currency']['code']))
			$this->currency = $this->baseop['currency']['code'];

		// order event handlers
		add_action('shopp_sagepayform_sale', array($this,'sale'));
	}

	function actions () {
		add_action('shopp_order_confirm_needed', array($this,'force_confirm'),9); // intercept checkout request, force confirm
		add_action('shopp_init_confirmation',array($this,'confirmation')); // replace confirm order page with paypal form
		add_action('shopp_remote_payment', array($this, 'returned'));
	}


	// ORDER EVENT HANDLER

	function sale ($Event) {
		$crypt = $this->decodeAndDecrypt($_REQUEST['crypt']);
		$crypt = $this->getToken($crypt);

		$Paymethod = $this->Order->paymethod();
		$Billing = $this->Order->Billing;

		shopp_add_order_event($Event->order, 'authed', array(
			'txnid' => $crypt['TxAuthNo'],						// Transaction ID
			'amount' => $crypt['Amount'],							// Gross amount authorized
			'gateway' => $this->module,								// Gateway handler name (module name from @subpackage)
			'paymethod' => $Paymethod->label,						// Payment method (payment method label from payment settings)
			'paytype' => $crypt['CardType'],						// Type of payment (check, MasterCard, etc)
			'payid' => $crypt['Last4Digits'],						// Payment ID (last 4 of card or check number)
			'capture' => true										// Capture flag
		));

	}

	/**
	 * confirmation
	 *
	 * replaces the confirm order form to submit cart to sagepayform
	 *
	 **/
	function confirmation () {
		add_filter('shopp_confirm_url',array($this,'url'));
		add_filter('shopp_confirm_form',array($this,'form'));
	}

	/**
	 * force_confirm
	 *
	 **/
	function force_confirm ( $confirm ) {
		$this->Order->Billing->cardtype = "SagePayForm";
		$this->Order->confirm = true;
		return true;
	}

	/**
	 * url
	 *
	 * url returns the live, test, or sim url, depending on testmode setting
	 *
	 **/
	function url ($url=false) {
		if ($this->settings['testmode'] == "test") {
			return $this->test_url;
		} else if($this->settings['testmode'] == "sim") {
			return $this->sim_url;
		}
		else return $this->live_url;
	}

	/**
	 * form
	 *
	 * Builds a hidden form to submit to SagePay when confirming the order for processing
	 *
	 **/
	function form ($form,$options=array()) {
		$Shopping = ShoppShopping();
		$Order = ShoppOrder();
		$Customer = $Order->Customer;
		$BillingAddress = $Order->Billing;
		$ShippingAddress = $Order->Shipping;

		// Sage
		// Generate crypt field
		$time_stamp = date("ymdHis", time());
		$rand = rand(0,32000)*rand(0,32000);
		$vendor_txcode = $vendor_name . "-" . $time_stamp . "-" . $rand;

		if($this->amount('shipping') > 0) {
			$basket = (count($Order->Cart->contents) + 1).":";
		} else {
			$basket = count($Order->Cart->contents).":";
		}

		$first = true;
		// Line Items
		foreach($Order->Cart->contents as $i => $Item) {
			$with_tax = $this->amount($Item->tax + $Item->unitprice);
			if(!$first) $basket .= ":";
			$basket .= $Item->name.":".
					   $Item->quantity.":".
					   $this->amount($Item->unitprice).":".
					   $this->amount($Item->tax).":".
					   $with_tax.":".
					   $with_tax;

			$first = false;
		}

		if($this->amount('shipping') > 0) {
			$basket .= ":"."Delivery:---:---:---:---:".$this->amount('shipping');
		}

		// Now to build the Form crypt field.
		$post = "VendorTxCode=" . $vendor_txcode;


		$post .= "&Amount=" . $this->amount('total'); // Formatted to 2 decimal places with leading digit TODO: check leading digit
		$post .=  "&Currency=" . $this->currency;

		$post .= "&Description= ".

		//TODO figure out
		$post .=  "&SuccessURL=" . shoppurl(array('rmtpay'=>'sageform_process'),'confirm');
		$post .=  "&FailureURL=" . shoppurl(array('rmtpay'=>'sageform_process'),'confirm');

		$post .=  "&CustomerName=" . $Customer->firstname . " " . $Customer->lastname;

		/* Email settings:
		** Flag 'SendEMail' is an Optional setting.
		** 0 = Do not send either customer or vendor e-mails,
		** 1 = Send customer and vendor e-mails if address(es) are provided(DEFAULT).
		** 2 = Send Vendor Email but not Customer Email. If you do not supply this field, 1 is assumed and e-mails are sent if addresses are provided. **/
		$post .=  "&SendEMail=" . $this->send_email;
		$post .=  "&CustomerEMail=" . $Customer->email;
		$post .=  "&VendorEMail=" . $this->vendor_email;
		$post .=  "&eMailMessage=Thank you very much for your order.";


		// Billing Details:
		$post .=  "&BillingFirstnames=" . $Customer->firstname;
		$post .=  "&BillingSurname=" . $Customer->lastname;
		$post .=  "&BillingAddress1=" . $BillingAddress->address;
		if (strlen($BillingAddress->xaddress) > 0) $post .=  "&BillingAddress2=" . $BillingAddress->xaddress;
		$post .=  "&BillingCity=" . $BillingAddress->city;
		$post .=  "&BillingPostCode=" . $BillingAddress->postcode;
		$post .=  "&BillingCountry=" . $BillingAddress->country;
		if (strlen($BillingAddress->state && $BillingAddress->country == "US") > 0) $post .=  "&BillingState=" . substr($BillingAddress->state, 0, 2);
		if (strlen($Customer->phone) > 0) $post .=  "&BillingPhone=" . parse_phone($Customer->phone);

		// Delivery Details:
		if(strlen($ShippingAddress->name) > 0) {
			$shipname = explode(' ',$ShippingAddress->name);
			$post .=  "&DeliveryFirstnames=" . array_shift($shipname);
			$post .=  "&DeliverySurname=" . join(' ',$shipname);
		} else {
			$post .=  "&DeliveryFirstnames=" . $Customer->firstname;
			$post .=  "&DeliverySurname=" . $Customer->lastname;
		}
		$post .=  "&DeliveryAddress1=" . $ShippingAddress->address;
		if (strlen($ShippingAddress-xaddress) > 0) $post .=  "&DeliveryAddress2=" . $ShippingAddress->xaddress;
		$post .=  "&DeliveryCity=" . $ShippingAddress->city;
		$post .=  "&DeliveryPostCode=" . $ShippingAddress->postcode;
		$post .=  "&DeliveryCountry=" . $ShippingAddress->country;
		if (strlen($ShippingAddress->state) > 0 && $ShippingAddress->country == "US") $post .=  "&DeliveryState=" . substr($ShippingAddress->state, 0, 2);


		$post .=  "&Basket=" . $basket; // As created above


		/* Allow fine control over 3D-Secure checks and rules by changing this value. 0 is Default
		** It can be changed dynamically, per transaction, if you wish.  See the Form Protocol document */

		$tri_secure = "0";
		if(str_true($this->settings['3dsecure'])) $tri_secure = "1";
		$post .=  "&Apply3DSecure=". $tri_secure;

		// Encrypt the plaintext string for inclusion in the hidden field
		$crypt = $this->encryptAndEncode($post, $this->encryption, $this->password);

		// End Sage

		$_['navigate'] = "";
		$_['VPSProtocol'] = $this->protocol;
		$_['TxType'] = $this->transaction_type;
		$_['Vendor'] = $this->vendor;
		$_['Crypt'] = $crypt;

		$_ = array_merge($_,$options);

		return $form.$this->format($_);
	}


	/**
	 * returned
	 *
	 * Processes return data from SagePay
	 *
	 **/
	function returned () {
		if(!isset($_REQUEST['rmtpay'])) return;

		$rmtpay = $_REQUEST['rmtpay'];

		if($rmtpay == "sageform_process") {
			$crypt = $this->decodeAndDecrypt($_REQUEST['crypt']);
			$crypt = $this->getToken($crypt);

			$status = $crypt['Status'];
			if($status == "OK") {
				// Create the order and begin processing it
				shopp_add_order_event(false, 'purchase', array(
					'gateway' => $this->module,
					'txnid' => $crypt['TxAuthNo']
				));

				ShoppOrder()->purchase = ShoppPurchase()->id;
				shopp_redirect(shoppurl(false,'thanks',false));
			} else  {
				switch($status) {
					case "NOTAUTHED":
						return new ShoppError("SagePay Form: The bank has declined the transaction three times. Code: $status",'sagepay_form_error',SHOPP_TRXN_ERR);
					break;

					case "MALFORMED":
						return new ShoppError("SagePay Form: The information sent to SagePay was malformed. This is a gateway error. Code: $status",'sagepay_form_error',SHOPP_TRXN_ERR);
					break;

					case "INVALID":
						return new ShoppError("SagePay Form: The information sent to SagePay contained illegal data. This is a gateway error. Code: $status",'sagepay_form_error',SHOPP_TRXN_ERR);
					break;

					case "ABORT";
						return new ShoppError("SagePay Form: The transaction has timed out or the user has cancelled. Code: $status",'sagepay_form_error',SHOPP_TRXN_ERR);
					break;

					case "REJECTED":
						return new ShoppError("SagePay Form: The payment could not be accepted. The merchant requirements could not be met. This may be an AVS, CV2, or 3D Secure error. Code: $status",'sagepay_form_error',SHOPP_TRXN_ERR);
					break;

					case "ERROR":
						return new ShoppError("SagePay Form: The server errored. This may be due to scheduled maintanence.  Please try again. Code: $status",'sagepay_form_error',SHOPP_TRXN_ERR);
					break;
				}
			}
		}
	}

	/**
	 * Defines the settings interface
	 *
	 **/
	function settings () {
		$receipt_options = array(
			"0" => __("Don't send any receipt",'Shopp'),
			"1" => __("Send to both merchant and customer",'Shopp'),
			"2" => __("Send to customer only",'Shopp')
		);
		$mode_options = array(
			"test" => __('Test','Shopp'),
			"sim" => __('Simulator','Shopp'),
			"production" => __('Production','Shopp')
		);

		$this->ui->text(0,array(
			'name' => 'vendor',
			'value' => $this->settings['vendor'],
			'size' => 30,
			'label' => __('Enter SagePay Vendor Name.','Shopp')
		));

		$this->ui->text(0,array(
			'name' => 'password',
			'value' => $this->settings['password'],
			'size' => 30,
			'label' => __('Enter SagePay Password.','Shopp')
		));

		$this->ui->menu(1,array(
			'name' => 'receipt_preference',
			'keyed'=> true,
			'selected' => $this->settings['receipt_preference'],
			'label' => __('Order receipts','Shopp')
		),$receipt_options);

		$this->ui->menu(1,array(
			'name' => 'testmode',
			'keyed'=> true,
			'selected' => $this->settings['testmode'],
			'label' => __('Processing mode','Shopp')
		),$mode_options);

		$this->ui->checkbox(0,array(
			'name' => '3dsecure',
			'checked' => $this->settings['3dsecure'],
			'label' => __('Enable 3D Secure','Shopp')
		));
	}

	/**
	 * Encrypt form for transmission to SagePay
	 *
	 **/
	function encryptAndEncode ($strIn, $strEncryptionType, $strEncryptionPassword) {

		if ($strEncryptionType=="XOR") {
			//** XOR encryption with Base64 encoding **
			return base64Encode(simpleXor($strIn,$strEncryptionPassword));
		} else {
			//** AES encryption, CBC blocking with PKCS5 padding then HEX encoding - DEFAULT **

			//** use initialization vector (IV) set from $strEncryptionPassword
	    	$strIV = $strEncryptionPassword;

	    	//** add PKCS5 padding to the text to be encypted
	    	$strIn = $this->addPKCS5Padding($strIn);

	    	//** perform encryption with PHP's MCRYPT module
			$strCrypt = mcrypt_encrypt(MCRYPT_RIJNDAEL_128, $strEncryptionPassword, $strIn, MCRYPT_MODE_CBC, $strIV);

			//** perform hex encoding and return
			return "@" . bin2hex($strCrypt);
		}
	}

	/**
	 * Used by encryptAndEncode()
	 *
	 **/
	function addPKCS5Padding ($input) {
	   $blocksize = 16;
	   $padding = "";

	   // Pad input to an even block size boundary
	   $padlength = $blocksize - (strlen($input) % $blocksize);
	   for($i = 1; $i <= $padlength; $i++) {
	      $padding .= chr($padlength);
	   }

	   return $input . $padding;
	}

	/**
	 * Decodes response from SagePay
	 *
	 **/
	function decodeAndDecrypt ($strIn) {

		$strEncryptionPassword = $this->settings['password'];

		if (substr($strIn,0,1)=="@") {
			//** HEX decoding then AES decryption, CBC blocking with PKCS5 padding - DEFAULT **

			//** use initialization vector (IV) set from $strEncryptionPassword
	    	$strIV = $strEncryptionPassword;

	    	//** remove the first char which is @ to flag this is AES encrypted
	    	$strIn = substr($strIn,1);

	    	//** HEX decoding
	    	$strIn = pack('H*', $strIn);

	    	//** perform decryption with PHP's MCRYPT module
			return $this->removePKCS5Padding(
				mcrypt_decrypt(MCRYPT_RIJNDAEL_128, $strEncryptionPassword, $strIn, MCRYPT_MODE_CBC, $strIV));
		} else {
			//** Base 64 decoding plus XOR decryption **
			return simpleXor(base64Decode($strIn),$strEncryptionPassword);
		}
	}

	/**
	 * Used by decryptAndDecode()
	 *
	 **/
	function removePKCS5Padding($decrypted) {
		$padChar = ord($decrypted[strlen($decrypted) - 1]);
	    return substr($decrypted, 0, -$padChar);
	}

	/**
	 * Create array from decrypted SagePay resposne
	 *
	 **/
	function getToken ($thisString) {

		// List the possible tokens
		$Tokens = array(
		"Status",
		"StatusDetail",
		"VendorTxCode",
		"VPSTxId",
		"TxAuthNo",
		"Amount",
		"AVSCV2",
		"AddressResult",
		"PostCodeResult",
		"CV2Result",
		"GiftAid",
		"3DSecureStatus",
		"CAVV",
		"AddressStatus",
		"CardType",
		"Last4Digits",
		"PayerStatus");

		// Initialise arrays
		$output = array();
		$resultArray = array();

		// Get the next token in the sequence
		for ($i = count($Tokens)-1; $i >= 0 ; $i--) {
			// Find the position in the string
			$start = strpos($thisString, $Tokens[$i]);
			// If it's present
			if ($start !== false) {
			  // Record position and token name
			  $resultArray[$i]->start = $start;
			  $resultArray[$i]->token = $Tokens[$i];
			}
		}

		// Sort in order of position
		sort($resultArray);
		// Go through the result array, getting the token values
		for ($i = 0; $i<count($resultArray); $i++) {
			// Get the start point of the value
			$valueStart = $resultArray[$i]->start + strlen($resultArray[$i]->token) + 1;
			// Get the length of the value
			if ($i==(count($resultArray)-1)) {
			  $output[$resultArray[$i]->token] = substr($thisString, $valueStart);
			} else {
			  $valueLength = $resultArray[$i+1]->start - $resultArray[$i]->start - strlen($resultArray[$i]->token) - 2;
			  $output[$resultArray[$i]->token] = substr($thisString, $valueStart, $valueLength);
			}
		}

		// Return the ouput array
		return $output;
	}

} // END class SagePayForm