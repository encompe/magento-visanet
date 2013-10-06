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
 * Config model that is aware of all VisaNet_Eticket payment methods
 * Works with VisaNet specific system configuration
 */
class Strobe_VisaNet_Model_Config
{
    protected $_supportedLocal = array('es_PE');

    /**
     * Set method and store id, if specified
     * @param array $params
     */
    public function __construct($params = array())
    {
        if ($params) {
            $method = array_shift($params);
            $this->setMethod($method);
            if ($params) {
                $storeId = array_shift($params);
                $this->setStoreId($storeId);
            }
        }
    }

    /**
     * Method code setter
     *
     * @param string|Mage_Payment_Model_Method_Abstract $method
     * @return Strobe_VisaNet_Model_Config
     */
    public function setMethod($method)
    {
        if ($method instanceof Mage_Payment_Model_Method_Abstract)
            $this->_methodCode = $method->getCode();
        elseif (is_string($method))
            $this->_methodCode = $method;

        return $this;
    }

    /**
     * Payment method instance code getter
     * @return string
     */
    public function getMethodCode()
    {
        return $this->_methodCode;
    }

    /**
     * Store ID setter
     * @param int $storeId
     * @return Strobe_VisaNet_Model_Config
     */
    public function setStoreId($storeId)
    {
        $this->_storeId = (int)$storeId;
        return $this;
    }

    /**
     * Check whether method active in configuration and supported for merchant country or not
     *
     * @param string $method Method code
     * @return bool
     */
    public function isMethodActive($method)
    {
        if ($this->isMethodSupportedForCountry($method)
            && Mage::getStoreConfigFlag("payment/{$method}/active", $this->_storeId))
        {
            return true;
        }
        return false;
    }

    /**
     * Config field magic getter
     * The specified key can be either in camelCase or under_score format
     * Tries to map specified value according to set payment method code, into the configuration value
     * Sets the values into public class parameters, to avoid redundant calls of this method
     *
     * @param string $key
     * @return string|null
     */
    public function __get($key)
    {
        $underscored = strtolower(preg_replace('/(.)([A-Z])/', "$1_$2", $key));
        $value = Mage::getStoreConfig($this->_getSpecificConfigPath($underscored), $this->_storeId);
        $value = $this->_prepareValue($underscored, $value);
        $this->$key = $value;
        $this->$underscored = $value;
        return $value;
    }

    /**
     * Perform additional config value preparation and return new value if needed
     *
     * @param string $key Underscored key
     * @param string $value Old value
     * @return string Modified value or old value
     */
    protected function _prepareValue($key, $value)
    {
        // Always set payment action as "Sale" for Unilateral payments in EC
        if ($key == 'payment_action'
            && $value != self::PAYMENT_ACTION_SALE
            && $this->_methodCode == self::METHOD_VISANET_ETICKET
            && $this->shouldUseUnilateralPayments())
        {
            return self::PAYMENT_ACTION_SALE;
        }
        return $value;
    }

    /**
     * Check whether specified locale code is supported. Default: en_US
     *
     * @param string $lCode
     * @return string
     */
    protected function _getValidLocaleCode($lCode = null)
    {
        if (!$lCode || !in_array($lCode, $this->_supportedLocal)) {
            return 'en_US';
        }
        return $lCode;
    }

    /**
     * Protocol supported
     *
     * @return array
     */
    public function getProtocol()
    {
        return array('http' => Mage::helper('visanet')->__('HTTP'),
                    'https' => Mage::helper('visanet')->__('HTTPS (Secure)')
                    );
    }

    /**
     * Environment supported
     *
     * @return array
     */
    public function getEnvironment()
    {
        return array('Sandbox'
                        => Mage::helper('visanet')->__('Sandbox (Test)'),
                    'Production'
                        => Mage::helper('visanet')->__('Production')
                    );
    }

    /**
     * Allowed Currencies supported
     *
     * @return array
     */
    public function getAllowedCurrencies()
    {
        $codes = Mage::app()->getStore()->getAvailableCurrencyCodes(true);
        if (is_array($codes))
        {
            foreach ($codes as $code)
            {
                $currencies[] = array(
                    'value' => $code,
                    'label' => Mage::app()->getLocale()
                                ->getTranslation($code, 'nametocurrency')
                    );
            }
        }
        return $currencies;
    }
}