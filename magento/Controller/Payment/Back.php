<?php 

namespace Imsafu\Payment\Controller\Payment; 



use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\RequestInterface;

class Back extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
{

    const PUSH          = "[PUSH]";
    const BrowserReturn = "[Browser Return]";

    protected $_processingArray = array('processing', 'complete');


    /**
     * Customer session model
     *
     * @var \Magento\Customer\Model\Session
     */
    protected $_customerSession;
    protected $resultPageFactory;
    protected $checkoutSession;
    protected $orderRepository;
    protected $_scopeConfig;
    protected $_orderFactory;
    protected $creditmemoSender;
    protected $orderSender;
    protected $urlBuilder;


	
    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     */
    public function __construct(
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\App\Action\Context $context,
        \Imsafu\Payment\Model\PaymentMethod $paymentMethod,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Sales\Model\Order\Email\Sender\CreditmemoSender $creditmemoSender,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Framework\Url $urlBuilder,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->_customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->urlBuilder = $urlBuilder;
        $this->orderRepository = $orderRepository;
        parent::__construct($context);
        $this->_scopeConfig = $scopeConfig;
        $this->_orderFactory = $orderFactory;
        $this->_paymentMethod = $paymentMethod;
        $this->creditmemoSender = $creditmemoSender;
        $this->orderSender = $orderSender;
    }


    protected function _createInvoice($order)
    {
        if (!$order->canInvoice()) {
            return;
        }
        
        $invoice = $order->prepareInvoice();
        if (!$invoice->getTotalQty()) {
            throw new \RuntimeException("Cannot create an invoice without products.");
        }

        $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
        $invoice->register();
        $order->addRelatedObject($invoice);
    }

    public function execute()
    {
        $this->returnLog(self::BrowserReturn, $this->getRequest()->getPost());

        $model = $this->_paymentMethod;

        $imsafu_pay_id = $this->getRequest()->getParam('payID');
        
        if (!isset($imsafu_pay_id)) {
            $this->redirectToFailure();
            return;
        }

        $imsafu_order = $this->getImsafuOrderDetail($model->getAPIUrl(), $imsafu_pay_id);

        if ($imsafu_order == null || $imsafu_order->status !== 'success') {
            $this->redirectToFailure();
            return;
        }

        $imsafu_payment = $imsafu_order->payment;

        $order_number = $this->hex256BitStringToStr($imsafu_payment->orderID);

        $order = $this->_orderFactory->create()->loadByIncrementId($order_number);

        if ($order == null) {
            $this->redirectToFailure();
            return;
        }

        $history = ' (imsafu_pay_id:'.$imsafu_pay_id.' | order_number:'.$imsafu_payment->orderID.' | amount:'.$imsafu_payment->amount.' | original_amount:'.$imsafu_payment->originalAmount.')';

        $order->setState($model->getConfigData('success_order_status'));
        $order->setStatus($model->getConfigData('success_order_status'));
        $order->addStatusToHistory($model->getConfigData('success_order_status'), __(self::BrowserReturn.'Payment Success!'.$history));
        $order->save();

        $url = $this->urlBuilder->getUrl('checkout/onepage/success');
        $this->_redirect($url);
    }

    private function redirectToFailure() {
        $url = $this->urlBuilder->getUrl('checkout/onepage/failure');
        $this->_redirect($url);
    }

    function getImsafuOrderDetail($imsafu_api, $imsafu_pay_id)
    {
        try {
            $deposit_pays_url = $imsafu_api . '/depositPays/' . $imsafu_pay_id;
            list($return_code, $return_content) = $this->http_get($deposit_pays_url);
            $return_content_json = json_decode($return_content);
            return $return_content_json;
        } catch (Exception $e) {
            return null;
        }
    }

    function http_get($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return array($httpCode, $response);
    }

    function hex256BitStringToStr($hexString)
    {
        $hexString = str_replace('0x', '', $hexString);
        $hexString = rtrim($hexString, '0');
        return hex2bin($hexString);
    }

    /**
     * return log
     */
    public function returnLog($logType, $data){
    
        $filedate   = date('Y-m-d');
        $newfile    = fopen(  dirname(dirname(dirname(__FILE__))) . "/imsafu_log/" . $filedate . ".log", "a+" );      
        $return_log = date('Y-m-d H:i:s') . $logType . "\r\n";  
        foreach ($data as $k=>$v){
            $return_log .= $k . " = " . $v . "\r\n";
        }   
        $return_log .= '*****************************************' . "\r\n";
        $return_log = $return_log.file_get_contents( dirname(dirname(dirname(__FILE__))) . "/imsafu_log/" . $filedate . ".log");     
        $filename   = fopen( dirname(dirname(dirname(__FILE__))) . "/imsafu_log/" . $filedate . ".log", "r+" );      
        fwrite($filename,$return_log);
        fclose($filename);
        fclose($newfile);
    
    }


    /**
     *  JS 
     *
     */
    public function getParentLocationReplace($url)
    {
        return '<script type="text/javascript">parent.location.replace("'.$url.'");</script>';
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

}


