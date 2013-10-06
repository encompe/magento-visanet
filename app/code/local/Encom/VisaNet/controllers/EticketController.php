<?php
class Strobe_VisaNet_EticketController extends Mage_Core_Controller_Front_Action
{
    /**
     * Get singleton with payment model
     *
     * @return Strobe_VisaNet_Model
     */
    public function getPayment()
    {
        return Mage::getSingleton('visanet/eticket');
    }

    /**
     * Get singleton with model checkout session
     *
     * @return Mage_Checkout_Model_Session
     */
    public function _getCheckout()
    {
        return Mage::getSingleton('checkout/session');
    }
    public function checkEticket($order, $eticket, $shop_code, $debug){
      //Url for Eticket Generation
      if($debug){
        $eticket_url = "http://cal2testing.sytes.net/WSConsulta/WSConsultaEticket.asmx?WSDL";
      }else{
        $eticket_url = "http://www.multimerchantvisanet.com/WSConsulta/WSConsultaEticket.asmx";
      }
      //Generating the base document
      $dom = new DOMDocument('1.0', 'utf-8');
      //Parameters
      $codTienda = $dom->createElement('parametro', $shop_code);
      $codTiendaAttribute = $dom->createAttribute('id');
      $codTiendaAttribute->value = 'CODTIENDA';
      $codTienda->appendChild($codTiendaAttribute);

      $eticket = $dom->createElement('parametro',$eticket);
      $eticketAttribute = $dom->createAttribute('id');
      $eticketAttribute->value = 'ETICKET';
      $eticket->appendChild($eticketAttribute);

      //End Parameters
      $parametros = $dom->createElement('parametros');
      //Adding Parameters to the parameters tag
      $parametros->appendChild($codTienda);
      $parametros->appendChild($eticket);
      //End Adding
      $check_eticket = $dom->createElement('consulta_eticket');
      $check_eticket->appendChild($parametros);
      $dom->appendChild($check_eticket);
      $xml = $dom->saveXML();
      //Soap Client
      $client = new SoapClient($eticket_url);
      $result = $client->ConsultaEticket( array("xmlIn"=>$xml) );
      //Parsing Results
      $response = new DOMDocument();
      $isValid=$response->loadXML($result->ConsultaEticketResult);
      if(!$isValid){
        //Exception throw when xml is invalid
        throw new Exception("No se pudo procesar la orden");
      }
      //Checking Response
      $domXPath = new DOMXPath($response);
      $mensajes = $domXPath->query("//respuesta_eticket/mensajes/mensaje");
      //Si la cantidad de mensajes es > 0 tenemos un error :(
      $length = $mensajes->length;
      if($length > 0){
        for($i = 0 ; $i < $length; $i++){
          throw new Exception($mensajes->item($i)->nodeValue);
        }
      }

      $params = array();
      $eticketNode = $domXPath->query('//respuesta_eticket/pedido/operacion/campo[@id="estado"]');
      $params['estado'] = $eticketNode->item(0)->nodeValue;

      $codTienda = $domXPath->query('//respuesta_eticket/pedido/operacion/campo[@id="cod_tienda"]');
      $params['cod_tienda'] = $codTienda->item(0)->nodeValue;

      $norden = $domXPath->query('//respuesta_eticket/pedido/operacion/campo[@id="nordent"]');
      $params['nordent'] = $norden->item(0)->nodeValue;

      $amount = $domXPath->query('//respuesta_eticket/pedido/operacion/campo[@id="imp_autorizado"]');
      $params['importe'] = $amount->item(0)->nodeValue;

      if($params['estado'] != "AUTORIZADO"){
        throw new Exception('Tu transaccion fue denegada.');
      }
      if($params['importe'] != $order->getGrandTotal()){
        throw new Exception('El monto de la transaccion es incorrecta.');
      }
      if($params['nordent'] != $order->getId()){
        throw new Exception('Tu orden es la incorrecta .');
      }
      return $params;

    }
    /**
     * Notification Endpoint
     *
     * @return Nothing
     */
    public function endpointAction()
    {
      //Retrieve some data from the conf
        $shop_code = 101087626;
        $store_data = "Test Message";
        $debug_mode = True;

      //Si es nulo, mostramos error y nos vamos
      if(!isset($_POST['eticket'])){
        $this->_getCheckout()->addError("No pudimos procesar tu pedido en estos momentos");
        $this->_redirect('checkout/cart');
        return;
      }
      //Retrieve the eticket
      $eticket = $_POST['eticket'];

      //Obtenemos la orden basandonos en el eticket
      $read = Mage::getSingleton('core/resource')->getConnection('core_read');
      $query =
      $order_id = $read->fetchOne("SELECT order_id FROM etickets WHERE eticket='{$eticket}' LIMIT 1");

      if(!$order_id){
        $this->_getCheckout()->addError("Tu eticket es invalido");
        $this->_redirect('checkout/cart');
        return;
      }
      //Load the order
      $order = Mage::getModel('sales/order');
      $order->load($order_id);
      //Retrieve params or error

      try{
        $params = $this->checkEticket($order, $eticket, $shop_code, $debug_mode);
        //Order is correct
        $order->setStatus('payment_confirmed_visanet');
        #$order->setData('state','complete');
        $history = $order->addStatusHistoryComment(
                         __('Orden pagada con Visanet')
            );
        $history->setIsCustomerNotified(true);
        $order->save();
        
        // Enviar email.
        $order->sendOrderUpdateEmail(true, 'Pago confirmado por Visanet');
        
        //$event = Mage::getModel('moneybookers/event')->setEventData($this->getRequest()->getParams());
        $quoteId = "Pago Completo";
        $this->_getCheckout()->setLastSuccessQuoteId($quoteId);
        $this->_redirect('checkout/onepage/success');
        return;
      }catch (Exception $e){
            /*$order->setState(Mage_Sales_Model_Order::STATE_CANCELED,
                Mage_Sales_Model_Order::STATE_CANCELED,
                $e->getMessage(),
                false
            );
            $order->save();*/
            $order->cancel();
            $order->addStatusToHistory(Mage_Sales_Model_Order::STATE_CANCELED, $e->getMessage());
            $order->save();
            
            $order->sendOrderUpdateEmail(true, $e->getMessage());
            
            $msg  = Mage::helper('visanet')->__('La transacci&oacute;n de tu orden N: '.$order->getId().' ha sido denegada.');
            Mage::getSingleton('checkout/session')->addException($e,
              $msg
            );
            //$this->send_mail($e->getMessage());
            parent::_redirect('checkout/cart');
            return;
      }

    }

    public function generateEticket($shop_code,$order_id,$mount,$message,$debug=False){
      //Url for Eticket Generation
      if($debug){
        $eticket_url = "http://cal2testing.sytes.net/WSGenerarEticket/WSEticket.asmx?WSDL";
      }else{
        $eticket_url = "http://www.multimerchantvisanet.com/WSGenerarEticket/WSEticket.asmx";
      }

      //Generating the base document
      $dom = new DOMDocument('1.0', 'utf-8');
      //Parameters
      $canal = $dom->createElement('parametro', 3);
      $canalAttribute = $dom->createAttribute('id');
      $canalAttribute->value = 'CANAL';
      $canal->appendChild($canalAttribute);

      $producto = $dom->createElement('parametro', 1);
      $productoAttribute = $dom->createAttribute('id');
      $productoAttribute->value = 'PRODUCTO';
      $producto->appendChild($productoAttribute);

      $codTienda = $dom->createElement('parametro', $shop_code);
      $codTiendaAttribute = $dom->createAttribute('id');
      $codTiendaAttribute->value = 'CODTIENDA';
      $codTienda->appendChild($codTiendaAttribute);

      $numOrden = $dom->createElement('parametro', $order_id);
      $numOrdenAttribute = $dom->createAttribute('id');
      $numOrdenAttribute->value = 'NUMORDEN';
      $numOrden->appendChild($numOrdenAttribute);

      $mount = $dom->createElement('parametro', number_format($mount, 2));
      $mountAttribute = $dom->createAttribute('id');
      $mountAttribute->value = 'MOUNT';
      $mount->appendChild($mountAttribute);

      $datoComercio = $dom->createElement('parametro',$message);
      $datoComercioAttribute = $dom->createAttribute('id');
      $datoComercioAttribute->value = 'DATO_COMERCIO';
      $datoComercio->appendChild($datoComercioAttribute);

      //End Parameters
      $parametros = $dom->createElement('parametros');
      //Adding Parameters to the parameters tag
      $parametros->appendChild($canal);
      $parametros->appendChild($producto);
      $parametros->appendChild($codTienda);
      $parametros->appendChild($numOrden);
      $parametros->appendChild($mount);
      $parametros->appendChild($datoComercio);
      //End Adding
      $nuevo_eticket = $dom->createElement('nuevo_eticket');
      $nuevo_eticket->appendChild($parametros);
      $dom->appendChild($nuevo_eticket);
      $xml = $dom->saveXML();
      //Soap Client
      $client = new SoapClient($eticket_url);
      $result = $client->GeneraEticket( array("xmlIn"=>$xml) );
      //Parse Result
      $response = new DOMDocument();
      $isValid=$response->loadXML($result->GeneraEticketResult);
      if(!$isValid){
        //Exception throw when xml is invalid
        throw new Exception('La transacci&oacute;n de tu orden N: '.$order_id.' ha sido denegada');
      }
      //Checking Response
      $domXPath = new DOMXPath($response);
      $mensajes = $domXPath->query("//eticket/mensajes/mensaje");
      //Si la cantidad de mensajes es > 0 tenemos un error :(
      $length = $mensajes->length;
      if($length > 0){
        for($i = 0 ; $i < $length; $i++){
          throw new Exception($mensajes->item($i)->nodeValue);
        }
      }
      //We dont have any error, lets return the eticket
      $eticketNode = $domXPath->query('//eticket/registro/campo[@id="ETICKET"]');
      $eticket = $eticketNode->item(0)->nodeValue;
      return $eticket;
    }
    /**
     * Generates Redirect Url for payment method
     */
    public function getRedirectUrl($shop_code,$order_id,$mount,$message,$eticket,$debug=False){
      //Url for Eticket Generation

      if($debug){
        $form_url = "http://cal2testing.sytes.net/formularioweb/formulariopago.asp";
      }else{
        $form_url = "http://www.multimerchantvisanet.com/formularioweb/formulariopago.asp";
      }

      $params = implode("&",array(
                      'codtienda='.$shop_code,
                      'numorden='.$order_id,
                      'mount='.number_format($mount, 2),
                      'dato_comercio='.urlencode($message),
                      'eticket='.$eticket
                                  ));
      $ch = curl_init($form_url);
      curl_setopt($ch, CURLOPT_HEADER, FALSE);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
      curl_setopt($ch, CURLOPT_POST, TRUE);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
      $content = curl_exec($ch);
      curl_close($ch);
      $redirect = str_replace("formulariopago.aspx",$form_url,$content);
      return $redirect;
    }

    /**
     * Order Place and redirect to SafetyPay Express service
     */
    public function paymentAction()
    {

        //Retrieve some data from the conf
        $shop_code = 101087626;
        $store_data = "Test Message";
        $debug_mode = True;
        try
        {
             //$this->loadLayout();
             //$this->renderLayout();
        }
        catch (Exception $e)
        {
            //Who cares?
        }

        try {
            $session = $this->_getCheckout();
            $order = Mage::getModel('sales/order');
            $order->loadByIncrementId($session->getLastRealOrderId());
            $order->save();
            $eticket = $this->generateEticket($shop_code, $order->getId(), $order->getGrandTotal(), $store_data,$debug_mode);
            $redirect_content = $this->getRedirectUrl($shop_code, $order->getId(), $order->getGrandTotal(), $store_data,$eticket,$debug_mode);
            $session->getQuote()->setIsActive(false)->save();
            /*$session->setSafetypayQuoteId($session->getQuoteId());
            $session->setSafetypayRealOrderId($session->getLastRealOrderId());
            */
            $session->clear();
            $this->loadLayout();
            $this->renderLayout();

            if (!$order->getId()) {
                Mage::throwException('No order for processing found');
            }
            $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT,
                Mage_Sales_Model_Order::STATE_PENDING_PAYMENT,
                Mage::helper('visanet')->__('The shopper has been redirected to VisaNet service using the Token URL.'),
                true
            );
            $order->sendNewOrderEmail();
            $order->setEmailSent(true);
            $order->save();
            //Write Model - Hacky
            $write = Mage::getSingleton('core/resource')->getConnection('core_write');
            $write->query("INSERT INTO etickets values(?,?)",array($eticket,$order->getId()));
            $write->commit();
            echo $redirect_content;
        } catch (Exception $e){
            $order->setState(Mage_Sales_Model_Order::STATE_CANCELED,
                Mage_Sales_Model_Order::STATE_CANCELED,
                $e->getMessage(),
                false
            );
            $order->save();
             $msg  = Mage::helper('visanet')->__('La transacci&oacute;n de tu orden N: '.$order->getId().' ha sido denegada.');
            Mage::getSingleton('checkout/session')->addException($e,
               $msg
            );
            $this->send_mail($e->getMessage());
            parent::_redirect('checkout/cart');
        }
    }
    function send_mail($error){
      //Mage::helper('checkout')->sendPaymentFailedEmail($this->getOnepage()->getQuote(), $error);
    }
    /**
     * Action to which the customer will be returned when the payment is made.
     *
     * @return Nothing
     */
    public function successAction()
    {

      /*$event = Mage::getModel('safetypay/event')
                 ->setEventData($this->getRequest()->getParams());
        try {
            $quoteId = $event->successEvent();

            $message = $event->confirmationEvent();
            $this->getResponse()->setBody($message);

            $this->_getCheckout()->setLastSuccessQuoteId($quoteId);
            $this->_redirect('checkout/onepage/success');
            return;
        } catch (Mage_Core_Exception $e) {
            $this->_getCheckout()->addError($e->getMessage());
        } catch(Exception $e) {
            Mage::logException($e);
        }
        $this->_redirect('checkout/cart');*/
    }

    /**
     * Action to which the customer will be returned if the payment process is
     * cancelled.
     * Cancel order and redirect user to the shopping cart.
     */
    /*public function cancelAction()
    {
        $event = Mage::getModel('safetypay/event')
                 ->setEventData($this->getRequest()->getParams());
        $message = $event->cancelEvent();
        $this->_getCheckout()->setQuoteId($this->_getCheckout()->getSafetypayQuoteId());
        $this->_getCheckout()->addError($message);
        $this->_redirect('checkout/cart');
    }*/
}
