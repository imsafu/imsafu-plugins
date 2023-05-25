<?php

namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use Illuminate\Http\Request;
use Yansongda\Pay\Pay;

class ImsafuController extends PayController
{
    // for live mode
    private $live_api_url = 'https://allowpay-api-mainnet.fly.dev/v1';

    private $live_web_url = 'https://imsafu.com';

    // for test mode
    private $test_api_url = 'https://allowpay-api-devnet.fly.dev/v1';
    private $test_web_url = 'https://devnet.imsafu.com';

    private $is_testmode = true;

    public function gateway(string $payway, string $orderSN)
    {
        try {
            // 加载网关
            $this->loadGateWay($orderSN, $payway);

            // 参数定义
            $store_name = $this->payGateway->merchant_id;
            $imsafu_api = $this->is_testmode ? $this->test_api_url : $this->live_api_url;
            $receiver = $this->payGateway->merchant_key;
            $amount = $this->order->actual_price;
            $deadline = date('c', strtotime('+1 day'));
            $notify_url = url($this->payGateway->pay_handleroute . '/notify_url');
            $redirect_url = url('detail-order-sn', ['orderSN' => $this->order->order_sn]);
            $api_key = $this->payGateway->merchant_pem;
            $currency = 'CNY';

            // imsafu: creat mechant order
            $depost_pay_url = $imsafu_api . '/depositPay';
            $payment_json = array(
                'payment' => array(
                    'orderID' => $this->convertTo64BitHex($this->order->order_sn),
                    'receiver' => $receiver,
                    'amount' => $amount,
                    'currency' => $currency,
                    'deadline' => $deadline
                ),
                'notifyURL' => $notify_url
            );

            $payment_json_str = json_encode($payment_json);
            list($return_code, $return_content) = $this->http_post_json($depost_pay_url, $payment_json_str, $api_key);
            $depost_pay_response = $return_content;
            if ($return_code === 200) {
                $depost_pay_response_json = json_decode($depost_pay_response);
                $payID = $depost_pay_response_json->payment->payID;

                $params = array(
                    'payID' => $payID,
                    'brand' => $store_name,
                    'memo' => 'Order #' . $this->order->order_sn,
                    'redirect_url' => $redirect_url,
                    'currency' => $currency,
                );

                $payment_url = ($this->is_testmode ? $this->test_web_url : $this->live_web_url) . '/payment_qrcode?' . http_build_query($params);
                return redirect()->away($payment_url);

            } else {
                return 'imsafu支付接口请求失败:' . $depost_pay_response;
            }
        } catch (RuleValidationException $exception) {
            return $this->err($exception->getMessage());
        }
    }

    public function notifyUrl(Request $request)
    {
        // {"payment":{"payID":"pay_01GZ8FYDR1E7VNM1J85EP4T0EG"}}
        $json_str = file_get_contents('php://input');
        $json = json_decode($json_str);
        if (!isset($json->payment) || !isset($json->payment->payID)) {
            wp_die('Invalid request');
        }
        $imsafu_pay_id = $json->payment->payID;

        // fetch imsafu order status
        $imsafu_order_json = $this->getImsafuOrderDetail($imsafu_pay_id);

        if ($imsafu_order_json !== null && $imsafu_order_json->status === 'success') {
            $order_sn = $this->convertFrom64BitHex($imsafu_order_json->payment->orderID);

            if ($order_sn !== null) {
                $order = $this->orderService->detailOrderSN($order_sn);

                if ($order !== null) {
                    // 更新订单状态
                    $this->orderProcessService->completedOrder($order_sn, $order->actual_price, $imsafu_pay_id);
                }
            }
        }
    }

    function getImsafuOrderDetail($imsafu_pay_id)
    {
        $imsafu_api = $this->is_testmode ? $this->test_api_url : $this->live_api_url;
        try {
            $deposit_pays_url = $imsafu_api . '/depositPays/' . $imsafu_pay_id;
            list($return_code, $return_content) = $this->http_get($deposit_pays_url);
            $return_content_json = json_decode($return_content);
            return $return_content_json;
        } catch (Exception $e) {
            return null;
        }
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

    function convertTo64BitHex($string)
    {
        $hexString = bin2hex($string);
        return '0x' . str_pad($hexString, 64, '0', STR_PAD_RIGHT);
    }

    function convertFrom64BitHex($hexString)
    {
        $hexString = str_replace('0x', '', $hexString);
        $hexString = rtrim($hexString, '0');
        return hex2bin($hexString);
    }

}