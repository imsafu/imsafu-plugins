<?php
/**
 * Copyright © 2023 imsafu. All rights reserved.
 * See LICENSE for license details.
 */
namespace Imsafu\Payment\Model;

use Magento\Quote\Api\Data\CartInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Sales\Model\Order;

class PaymentMethod extends AbstractMethod
{
    const IMSAFU_API_URL = 'https://allowpay-api-mainnet.fly.dev/v1';
    const IMSAFU_WEB_URL = 'https://imsafu.com';

    const IMSAFU_API_URL_TEST = 'https://allowpay-api-devnet.fly.dev/v1';
    const IMSAFU_WEB_URL_TEST = 'https://devnet.imsafu.com';

    const CODE = 'imsafupayment';
    const POST = "[POST to imsafu]";
 
    protected $_code = self::CODE;
    
    protected $_isInitializeNeeded      = true;
    
    protected $_formBlockType = 'Imsafu\Payment\Block\Form';
    protected $_infoBlockType = 'Imsafu\Payment\Block\Info';
 
    protected $_isGateway                   = false;
    protected $_canAuthorize                = false;
    protected $_canCapture                  = false;
    protected $_canCapturePartial           = false;
    protected $_canRefund                   = false;
    protected $_canRefundInvoicePartial     = false;
    protected $_canVoid                     = false;
    protected $_canUseInternal              = false;
    protected $_canUseCheckout              = true;
    protected $_canUseForMultishipping      = false;
    protected $_canSaveCc                   = false;
    
    protected $urlBuilder;
    protected $_moduleList;
    protected $checkoutSession;
    protected $_orderFactory;
 
    
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\Module\ModuleListInterface $moduleList,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Framework\Url $urlBuilder,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []){
        $this->urlBuilder = $urlBuilder;
        $this->_moduleList = $moduleList;
        $this->checkoutSession = $checkoutSession;
        $this->_orderFactory = $orderFactory;
        parent::__construct($context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data);
    }
    
    /**
     *  Redirect URL
     *
     *  @return   string Redirect URL
     */
    public function getOrderPlaceRedirectUrl()
    {
        return $this->urlBuilder->getUrl('imsafupayment/payment/redirect', ['_secure' => true]);
    }

    /**
     *  Gateway URL
     *
     *  @return   string Gateway URL
     */
    public function getGatewayUrl()
    {
        $web_url = $this->getConfigData('test_mode') ? self::IMSAFU_WEB_URL_TEST : self::IMSAFU_WEB_URL;
        return $web_url . '/payment_qrcode';
    }

    public function getAPIUrl()
    {
        $api_url = $this->getConfigData('test_mode') ? self::IMSAFU_API_URL_TEST : self::IMSAFU_API_URL;
        return $api_url;
    }


    public function canUseForCurrency($currencyCode)
    {
        return true;   
    }
    
    public function initialize($paymentAction, $stateObject)
    {
        $payment = $this->getInfoInstance();
        //$order = $payment->getOrder();

        $state = $this->getConfigData('new_order_status');

        //$state = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
        $stateObject->setState($state);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);
    }
   
    public function getCheckoutParameter()
    {
        $orderIncrementId = $this->checkoutSession->getLastRealOrderId();
        $order = $this->_orderFactory->create()->loadByIncrementId($orderIncrementId);

        //支付币种
        $order_currency    = $order->getOrderCurrencyCode();
        //金额
        $order_amount      = sprintf('%.2f', $order->getGrandTotal());
        //imsafu订单号
        $imsafu_order_id   = $this->strTo256BitHexString($orderIncrementId);
        //返回地址
        $redirect_url      = $this->urlBuilder->getUrl('imsafupayment/payment/back', ['_secure' => true,'_nosid' => true]);
        //通知地址
        $notify_url        = $this->urlBuilder->getUrl('imsafupayment/payment/notice', ['_secure' => true,'_nosid' => true]);

        $deadline = date('c', strtotime('+1 day'));

        $imsafu_payment    = [
            'orderID' => $imsafu_order_id,
            'receiver' => $this->getConfigData('receiving_address'),
            'amount' => $order_amount,
            'currency' => $order_currency,
            'deadline' => $deadline,
        ];

        $payment_json = array(
            'payment' => $imsafu_payment,
            'notifyURL' => $notify_url
        );

        $payment_json_str = json_encode($payment_json);
        $imsafu_api_url = $this->getAPIUrl();
        $imsafu_payment_submit_url = $imsafu_api_url . '/depositPay';
        $api_key = $this->getConfigData('merchant_secret');
        if ($api_key == null) {
          $api_key = "placeholder";
        }
        list($return_code, $return_content) = $this->http_post_json($imsafu_payment_submit_url, $payment_json_str, $api_key);
        $depost_pay_response_json = json_decode($return_content);
        $payID = $depost_pay_response_json->payment->payID;

        $parameter = array('payID'=>$payID,
            'brand'=> $this->getConfigData('brand_name'),
            'memo' => 'Order #' . $orderIncrementId,
            'currency' => $order_currency,
            'redirect_url' => $redirect_url,
        );

        //记录提交日志
        $this->postLog(self::POST, $parameter);


        return $parameter;
    }
    
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        
        if (parent::isAvailable($quote) && $quote){
            return true;
        }
        return false;
    }
   

    /**
     * post log
     */
    public function postLog($logType, $data){
    
        $filedate   = date('Y-m-d');
        $newfile    = fopen(  dirname(dirname(__FILE__)) . "/imsafu_log/" . $filedate . ".log", "a+" );      
        $return_log = date('Y-m-d H:i:s') . $logType . "\r\n";  
        foreach ($data as $k=>$v){
            $return_log .= $k . " = " . $v . "\r\n";
        }   
        $return_log .= '*****************************************' . "\r\n";
        $return_log = $return_log.file_get_contents( dirname(dirname(__FILE__)) . "/imsafu_log/" . $filedate . ".log");     
        $filename   = fopen( dirname(dirname(__FILE__)) . "/imsafu_log/" . $filedate . ".log", "r+" );      
        fwrite($filename,$return_log);
        fclose($filename);
        fclose($newfile);
    
    }

    function strTo256BitHexString($string)
    {
        $hexString = bin2hex($string);
        return '0x' . str_pad($hexString, 64, '0', STR_PAD_RIGHT);
    }

    function http_post_json($url, $jsonStr, $bearerToken)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonStr);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array(
                'Content-Type: application/json; charset=utf-8',
                'Content-Length: ' . strlen($jsonStr),
                'Authorization: Bearer ' . $bearerToken,
            )
        );
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return array($httpCode, $response);
    }
}
