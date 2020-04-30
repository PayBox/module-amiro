<?php

/**
 * Example pay driver.
 *
 * How to create your own pay driver:
 *
 * <ol>
 * <li>Copy the directory "_local/eshop/pay_drivers/example" to another, i.e. "_local/eshop/pay_drivers/my_driver"</li>
 * <li>Open driver.php in your path and:
 * <ul>
 * <li>Rename driver class name to your name (MyDriver_PaymentSystemDriver). Naming rule: all words delimeted by _ should be started from uppercase letter. Not _ allowed in driver name.</li>
 * <li>In some cases you should to check or add some fields when printing payment button on checkout page. You can manipulate with $aData array in getPayButton method for it. The same for pay system button with autoredirect but the method is getPayButtonParams</li>
 * <li>User will come back to the site from payment system by special URL and system already has the checks for setting correct order status. If you want to make your manual checking do it in payProcess method.</li>
 * <li>For payment system background requests for order approving there is payCallback method. You need to override this method with you own check of payment data.</li>
 * <li>If get or post field are differ from order_id, id or item_number you need to override getProcessOrder method that will return valid order id from POST and GET request.
 Also, you have to implement the getOrderIdVarName() method, that will return real field name:
 <pre>
    public static function getOrderIdVarName(){
        return 'ID_ORDER_FIELD_NAME';
    }
 </pre>
 </li>
 * </ul>
 * </li>
 * <li>Open driver.tpl and modify sets:
 * <ul>
 * <li>settings_form - part of form that will be insertted to driver form when you open your driver for editing.</li>
 * <li>checkout_form - button that will be shown on checkout page after the list of items. ##hiddens## field is required.</li>
 * <li>pay_form - form that will be submitted to payment system. In most cases this form is made with autoredirect.</li>
 * <li>Also modify path to driver.lng.</li>
 * </ul>
 * </li>
 * <li>Captions for drivers you can set in driver.lng.</li>
 * <li>After all these steps install your driver in Settings/Pay drivers page of admin panel and edit parameters. Then include your diver for the payment in option "Allowed payment drivers" of Catalog : Orders setting.</li>
 * </ol>
 *
 * @package Driver_PaymentSystem
 */
class Paybox_PaymentSystemDriver extends AMI_PaymentSystemDriver{

    /**
     * Get checkout button HTML form
     *
     * @param array $aRes Will contain "error" (error description, 'Success by default') and "errno" (error code, 0 by default). "forms" will contain a created form
     * @param array $aData The data list for button generation
     * @param bool $bAutoRedirect If form autosubmit required (directly from checkout page)
     * @return bool true if form is generated, false otherwise
     */
    public function getPayButton(&$aRes, $aData, $bAutoRedirect = false){
        foreach(Array("return", "description") as $fldName){
            $aData[$fldName] = htmlspecialchars($aData[$fldName]);
        }

        $hiddens = '';
        foreach ($aData as $key => $value) {
            $hiddens .= '<input type="hidden" name="' . $key . '" value="' . (is_null($value) ? $aData[$key] : $value) .'" />' . "\n";
        }
        $aData['hiddens'] = $hiddens;

        // Disable to process order using example button
        $aData["disabled"] = "disabled";

        return parent::getPayButton($aRes, $aData, $bAutoRedirect);
    }

    /**
     * Get the form that will be autosubmitted to payment system. This step is required for some shooping cart actions.
     *
     * @param array $aData The data list for button generation
     * @param array $aRes Will contain "error" (error description, 'Success by default') and "errno" (error code, 0 by default). "forms" will contain a created form
     * @return bool true if form is generated, false otherwise
     */
    public function getPayButtonParams($aData, &$aRes){
		$oEshop = AMI::getSingleton('eshop');
		$strCurrency = $oEshop->getBaseCurrency();
		if ($strCurrency == 'RUR')
			$strCurrency = 'RUB';

		$oEshopCart = AMI::getResource('eshop/cart');
		$arrItems = $oEshopCart->getItems();
		$strDescription = '';
		foreach($arrItems as $objItem){
			$strDescription .= $objItem->getItem()->header;
			if($objItem->getQty() > 1)
				$strDescription .= '*'.$objItem->getQty()."; ";
			else
				$strDescription .= "; ";
		}

		$arrFields = array(
			'pg_merchant_id'		=> $aData['merchant_id'],
			'pg_order_id'			=> $aData['order_id'],
			'pg_currency'			=> $strCurrency,
			'pg_amount'				=> $aData['amount'],
			'pg_lifetime'			=> !empty($aData['lifetime'])?$aData['lifetime']:0,
			'pg_testing_mode'		=> !empty($aData['testing_mode'])?1:0,
			'pg_description'		=> $strDescription,
			'pg_user_ip'			=> $_SERVER['REMOTE_ADDR'],
			'pg_language'			=> strtoupper($aData['language']),
			'pg_check_url'			=> $aData['callback'],
			'pg_result_url'			=> $aData['callback'],
			'pg_request_method'		=> 'GET',
			'pg_success_url'		=> $aData['return'],
			'pg_failure_url'		=> $aData['cancel'],
			'pg_salt'				=> rand(21,43433), // Параметры безопасности сообщения. Необходима генерация pg_salt и подписи сообщения.
		);

		preg_match_all("/\d/", $aData['contact'], $array);
		if(!empty($array[0])){
			$strPhone = implode('',$array[0]);
			$arrFields['pg_user_phone'] = $strPhone;
		}

		if(preg_match('/^.+@.+\..+$/', $aData['email'])){
			$arrFields['pg_user_email'] = $aData['email'];
			$arrFields['pg_user_contact_email'] = $aData['email'];
		}


		if(!empty($aData['payment_system_name']) && !$arrFields['pg_testing_mode'])
			$arrFields['pg_payment_system'] = $aData['payment_system_name'];

		ksort($arrFields);
		array_unshift($arrFields, 'payment.php');
		array_push($arrFields, $aData['secret_key']);
		$arrFields['pg_sig'] = md5(implode(';', $arrFields));
		unset($arrFields[0], $arrFields[1]);

		// Check parameters and set your fields here
        return parent::getPayButtonParams($arrFields + $aData, $aRes);
    }

    /**
     * Verify the order from user back link. In success case 'accepted' status will be setup for order.
     *
     * @param array $aGet $_GET data
     * @param array $aPost $_POST data
     * @param array $aRes reserved array reference
     * @param array $aCheckData Data that provided in driver configuration
     * @param array $aOrderData order data that contains such fields as id, total, order_date, status
     * @return bool true if order is correct and false otherwise
     * @see AMI_PaymentSystemDriver::payProcess(...)
     */
    public function payProcess($aGet, $aPost, &$aRes, $aCheckData, $aOrderData){
		if(!empty($aPost))
			$arrRequest = $aPost;
		else
			$arrRequest = $aGet;

		$thisScriptName = PG_Signature::getOurScriptName();
		if (empty($arrRequest['pg_sig']) || !PG_Signature::check($arrRequest['pg_sig'], $thisScriptName, $arrRequest, $aCheckData['secret_key']))
			die("Wrong signature");

		if($arrRequest['status'] == 'ok')
			return 1;
		else
			return 0;
    }

    /**
     * Verify the order by payment system background responce. In success case 'confirmed' status will be setup for order.
     *
     * @param array $aGet $_GET data
     * @param array $aPost $_POST data
     * @param array $aRes reserved array reference
     * @param array $aCheckData Data that provided in driver configuration
     * @param array $aOrderData order data that contains such fields as id, total, order_date, status
     * @return int -1 - ignore post, 0 - reject(cancel) order, 1 - confirm order
     * @see AMI_PaymentSystemDriver::payCallback(...)
     */
    public function payCallback($aGet, $aPost, &$aRes, $aCheckData, $aOrderData){
		if(!empty($aPost))
			$arrRequest = $aPost;
		else
			$arrRequest = $aGet;

		$thisScriptName = PG_Signature::getOurScriptName();
		if (empty($arrRequest['pg_sig']) || !PG_Signature::check($arrRequest['pg_sig'], $thisScriptName, $arrRequest, $aCheckData['secret_key']))
			die("Wrong signature");

		$objOrder = AMI::getResourceModel('eshop_order/table')->find($arrRequest['pg_order_id']);
		$arrResponse = array();
		$aGoodCheckStatuses = array('checkout','pending','waiting');
		$aGoodResultStatuses = array('checkout','pending','waiting','confirmed','confirmed_done','accepted','confirmed');

		if(!isset($arrRequest['pg_result'])){
			$bCheckResult = 1;
			if(empty($objOrder) || !in_array($objOrder->status, $aGoodCheckStatuses)){
				$bCheckResult = 0;
				$error_desc = 'Order status '.$objOrder->status.' or deleted order';
			}
			if(intval($aOrderData['total']) != intval($arrRequest['pg_amount'])){
				$bCheckResult = 0;
				$error_desc = 'Wrong amount';
			}

			$arrResponse['pg_salt']              = $arrRequest['pg_salt'];
			$arrResponse['pg_status']            = $bCheckResult ? 'ok' : 'error';
			$arrResponse['pg_error_description'] = $bCheckResult ?  ""  : $error_desc;
			$arrResponse['pg_sig']				 = PG_Signature::make($thisScriptName, $arrResponse, $aCheckData['secret_key']);

			$objResponse = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
			$objResponse->addChild('pg_salt', $arrResponse['pg_salt']);
			$objResponse->addChild('pg_status', $arrResponse['pg_status']);
			$objResponse->addChild('pg_error_description', $arrResponse['pg_error_description']);
			$objResponse->addChild('pg_sig', $arrResponse['pg_sig']);
		}
		else{
			if(intval($aOrderData['total']) != intval($arrRequest['pg_amount'])){
				$strResponseDescription = 'Wrong amount';
				if($arrRequest['pg_can_reject'] == 1)
					$strResponseStatus = 'rejected';
				else
					$strResponseStatus = 'error';
			}
			elseif((empty($objOrder) || !in_array($objOrder->status, $aGoodResultStatuses)) &&
					!($arrRequest['pg_result'] == 0 && $objOrder->status == 'rejected')){
				$strResponseDescription = 'Order status '.$objOrder->status.' or deleted order';
				if($arrRequest['pg_can_reject'] == 1)
					$strResponseStatus = 'rejected';
				else
					$strResponseStatus = 'error';
			} else {
				$strResponseStatus = 'ok';
				$strResponseDescription = "Request cleared";
				if ($arrRequest['pg_result'] == 1)
					$bResult = 1;
				else
					$bResult = 0;
			}

			$objResponse = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
			$objResponse->addChild('pg_salt', $arrRequest['pg_salt']);
			$objResponse->addChild('pg_status', $strResponseStatus);
			$objResponse->addChild('pg_description', $strResponseDescription);
			$objResponse->addChild('pg_sig', PG_Signature::makeXML($thisScriptName, $objResponse, $aCheckData['secret_key']));
		}

		header("Content-type: text/xml");
		echo $objResponse->asXML();

		if(isset($bCheckResult))
			die();
		else{
			global $billServices, $oEshop, $oOrder, $frn;

			if($bResult){
				$oEshop->initByOwnerName("eshop");
				$oOrder->updateStatus($frn, $arrRequest['pg_order_id'], 'auto', 'confirmed');
				$billServices->onPaymentConfirmed($arrRequest['pg_order_id']);
			}
			else{
				$oEshop->initByOwnerName("eshop");
				$oOrder->updateStatus($frn, $arrRequest['pg_order_id'], 'auto', 'rejected');
			}
			die();
		}
    }

    /**
     * Return real system order id from data that provided by payment system.
     *
     * @param array $aGet $_GET data
     * @param array $aPost $_POST data
     * @param array $aRes reserved array reference
     * @param array $aAdditionalParams reserved array
     * @return int order Id
     * @see AMI_PaymentSystemDriver::getProcessOrder(...)
     */
    public function getProcessOrder($aGet, $aPost, &$aRes, $aAdditionalParams){
        $orderId = 0;
        if(!empty($aGet["pg_order_id"])){
            $orderId = $aGet["pg_order_id"];
        }
        if(!empty($aPost["pg_order_id"])){
            $orderId = $aPost["pg_order_id"];
        }

        return intval($orderId);
    }

}



class PG_Signature {

	/**
	 * Get script name from URL (for use as parameter in self::make, self::check, etc.)
	 *
	 * @param string $url
	 * @return string
	 */
	public static function getScriptNameFromUrl ( $url )
	{
		$path = parse_url($url, PHP_URL_PATH);
		$len  = strlen($path);
		if ( $len == 0  ||  '/' == $path{$len-1} ) {
			return "";
		}
		return basename($path);
	}

	/**
	 * Get name of currently executed script (need to check signature of incoming message using self::check)
	 *
	 * @return string
	 */
	public static function getOurScriptName ()
	{
		if(!empty($_SERVER['REDIRECT_URL']))
			return array_pop(explode('/',$_SERVER['REDIRECT_URL']));

		return self::getScriptNameFromUrl( $_SERVER['PHP_SELF'] );
	}

	/**
	 * Creates a signature
	 *
	 * @param array $arrParams  associative array of parameters for the signature
	 * @param string $strSecretKey
	 * @return string
	 */
	public static function make ( $strScriptName, $arrParams, $strSecretKey )
	{
		$arrFlatParams = self::makeFlatParamsArray($arrParams);
		return md5( self::makeSigStr($strScriptName, $arrFlatParams, $strSecretKey) );
	}

	/**
	 * Verifies the signature
	 *
	 * @param string $signature
	 * @param array $arrParams  associative array of parameters for the signature
	 * @param string $strSecretKey
	 * @return bool
	 */
	public static function check ( $signature, $strScriptName, $arrParams, $strSecretKey )
	{
		return (string)$signature === self::make($strScriptName, $arrParams, $strSecretKey);
	}


	/**
	 * Returns a string, a hash of which coincide with the result of the make() method.
	 * WARNING: This method can be used only for debugging purposes!
	 *
	 * @param array $arrParams  associative array of parameters for the signature
	 * @param string $strSecretKey
	 * @return string
	 */
	static function debug_only_SigStr ( $strScriptName, $arrParams, $strSecretKey ) {
		return self::makeSigStr($strScriptName, $arrParams, $strSecretKey);
	}


	private static function makeSigStr ( $strScriptName, array $arrParams, $strSecretKey ) {
		unset($arrParams['pg_sig']);

		ksort($arrParams);

		array_unshift($arrParams, $strScriptName);
		array_push   ($arrParams, $strSecretKey);

		return join(';', $arrParams);
	}

	private static function makeFlatParamsArray ( $arrParams, $parent_name = '' )
	{
		$arrFlatParams = array();
		$i = 0;
		foreach ( $arrParams as $key => $val ) {

			$i++;
			if ( 'pg_sig' == $key )
				continue;

			$name = $parent_name . $key . sprintf('%03d', $i);

			if (is_array($val) ) {
				$arrFlatParams = array_merge($arrFlatParams, self::makeFlatParamsArray($val, $name));
				continue;
			}

			$arrFlatParams += array($name => (string)$val);
		}

		return $arrFlatParams;
	}

	/********************** singing XML ***********************/

	/**
	 * make the signature for XML
	 *
	 * @param string|SimpleXMLElement $xml
	 * @param string $strSecretKey
	 * @return string
	 */
	public static function makeXML ( $strScriptName, $xml, $strSecretKey )
	{
		$arrFlatParams = self::makeFlatParamsXML($xml);
		return self::make($strScriptName, $arrFlatParams, $strSecretKey);
	}

	/**
	 * Verifies the signature of XML
	 *
	 * @param string|SimpleXMLElement $xml
	 * @param string $strSecretKey
	 * @return bool
	 */
	public static function checkXML ( $strScriptName, $xml, $strSecretKey )
	{
		if ( ! $xml instanceof SimpleXMLElement ) {
			$xml = new SimpleXMLElement($xml);
		}
		$arrFlatParams = self::makeFlatParamsXML($xml);
		return self::check((string)$xml->pg_sig, $strScriptName, $arrFlatParams, $strSecretKey);
	}

	/**
	 * Returns a string, a hash of which coincide with the result of the makeXML() method.
	 * WARNING: This method can be used only for debugging purposes!
	 *
	 * @param string|SimpleXMLElement $xml
	 * @param string $strSecretKey
	 * @return string
	 */
	public static function debug_only_SigStrXML ( $strScriptName, $xml, $strSecretKey )
	{
		$arrFlatParams = self::makeFlatParamsXML($xml);
		return self::makeSigStr($strScriptName, $arrFlatParams, $strSecretKey);
	}

	/**
	 * Returns flat array of XML params
	 *
	 * @param (string|SimpleXMLElement) $xml
	 * @return array
	 */
	private static function makeFlatParamsXML ( $xml, $parent_name = '' )
	{
		if ( ! $xml instanceof SimpleXMLElement ) {
			$xml = new SimpleXMLElement($xml);
		}

		$arrParams = array();
		$i = 0;
		foreach ( $xml->children() as $tag ) {

			$i++;
			if ( 'pg_sig' == $tag->getName() )
				continue;

			$name = $parent_name . $tag->getName().sprintf('%03d', $i);

			if ( $tag->children()->count() > 0 ) {
				$arrParams = array_merge($arrParams, self::makeFlatParamsXML($tag, $name));
				continue;
			}

			$arrParams += array($name => (string)$tag);
		}

		return $arrParams;
	}
}
