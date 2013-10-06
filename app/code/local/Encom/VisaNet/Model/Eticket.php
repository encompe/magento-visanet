<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE_AFL.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @category   	Payment
 * @package    	Strobe_VisaNet
 * @copyright   Copyright (c) 2010 Strobe IT Team
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * VisaNet Payment E-Ticket Model
 */
class Strobe_VisaNet_Model_Eticket extends Mage_Payment_Model_Method_Cc
{
    protected $_formBlockType = 'visanet/form';
    protected $_infoBlockType = 'visanet/info';
    protected $_code = 'visanet_eticket';
    
    public function validate()
    {
        $this->allowCurr = explode(',', $this->getConfigData('abankcurrency'));
        $paymentInfo = $this->getInfoInstance();
        if ($paymentInfo instanceof Mage_Sales_Model_Order_Payment)
            $this->currentCurr = $paymentInfo->getOrder()
                                            ->getOrderCurrencyCode();
        else
            $this->currentCurr = $paymentInfo->getQuote()
                                            ->getQuoteCurrencyCode();

        if (!in_array($this->currentCurr, $this->allowCurr))
            Mage::throwException(
            	  Mage::helper('visanet')
            	  	->__('Select another currency type for your order with VisaNet.') . "\n\n"
	            . Mage::helper('visanet')
	            	->__("Currencies available: %s", $this->getConfigData('abankcurrency')) . "\n\n"
	            . Mage::helper('visanet')
	            	->__("Don't worry, using VisaNet can pay with many other currencies to complete your payment.")
			);

        return $this;
    }
    
    /**
     * Return url for redirection after order placed
     *
     * @return string
     */
    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('visanet/eticket/payment');
    }
}