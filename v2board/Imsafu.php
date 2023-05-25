<?php

namespace App\Payments;

use Illuminate\Support\Carbon;

class Imsafu
{
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'auth_key' => [
                'label' => '商户密钥',
                'description' => '联系 imsafu 获取',
                'type' => 'input',
            ],
            'receiver' => [
                'label' => '收款地址',
                'description' => '仅支持 ETH 格式地址，请勿使用 Tron 地址',
                'type' => 'input',
            ],
            'currency' => [
                'label' => '货币单位',
                'description' => 'CNY/USD 默认 USD',
                'type' => 'input',
            ],
            'brand' => [
                'label' => '品牌名称，将显示在支付页面',
                'description' => 'V2Board',
                'type' => 'input',
            ],
            'test_mode' => [
                'label' => '测试模式',
                'description' => 'true/false',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $payment = [
            'orderID' => '0x' . self::strTo256BitHexString($order['trade_no']),
            'receiver' => $this->config['receiver'],
            'amount' => sprintf('%.2f', $order['total_amount'] / 100),
            'currency' => $this->config['currency'] ?? 'USD',
            'deadline' => Carbon::now()->addDay()->toISOString(),
        ];

        $paymentRequest = [
            'payment' => $payment,
            'notifyURL' => $order['notify_url'],
        ];

        $api_gateway = $this->config['test_mode'] == "true" ? 'https://allowpay-api-devnet.fly.dev' : 'https://allowpay-api-mainnet.fly.dev/';

        $ret_raw = self::_curlPost($api_gateway . '/v1/depositPay', $paymentRequest);

        $ret = json_decode($ret_raw, true);

        if (empty($ret['payment']['payID'])) {
            error_log($ret_raw);
            abort(500, "生成 Imsafu 订单失败！");
        }

        $payment_url = $this->config['test_mode'] == "true" ? 'https://devnet.imsafu.com' : 'https://imsafu.com';

        return [
            // 0:qrcode 1:url
            'type' => 1,
            'data' => $payment_url . "/payment_qrcode?" . "payID=" . $ret['payment']['payID'] . "&" . "brand=" . $this->config['brand'] . "&" . "memo=" . '订单号 ' . $order['trade_no'] . "&" . "currency=" . $this->config['currency'] . "&" . "redirect_url=" . $order['return_url'],
        ];
    }

    public function notify($params)
    {
        $payload = trim(file_get_contents('php://input'));
        $json_param = json_decode($payload, true);

        $pay_id = $json_param['payment']['payID'];

        if (empty($pay_id)) {
            return false;
        }

        $api_gateway = $this->config['test_mode'] == "true" ? 'https://allowpay-api-devnet.fly.dev' : 'https://allowpay-api-mainnet.fly.dev/';

        $ret_raw = self::_curlGet($api_gateway . '/v1/depositPays/' . $pay_id);

        $ret = json_decode($ret_raw, true);

        if (isset($ret['status']) && $ret['status'] == "success") {
            return [
                'trade_no' => self::hex256BitStringToStr(substr($ret['payment']['orderID'], 2)),
                'callback_no' => $pay_id,
            ];
        } else {
            return false;
        }
    }

    private function _curlPost($url, $data = false)
    {

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array('Authorization: ' . 'Bearer ' . $this->config['auth_key'], 'Content-Type: application/json')
        );
        curl_setopt($ch, CURLOPT_POST, 1);
        $jsonDataEncoded = json_encode($data);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonDataEncoded);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    private function _curlGet($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array('Authorization: ' . 'Bearer ' . $this->config['auth_key'], 'Content-Type: application/json')
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    private function strTo256BitHexString($str)
    {
        // Convert the text string to a binary string
        $bin = '';
        for ($i = 0; $i < strlen($str); $i++) {
            $bin .= str_pad(decbin(ord($str[$i])), 8, '0', STR_PAD_LEFT);
        }

        // Convert the binary string to a hexadecimal string
        $hex = '';
        for ($i = 0; $i < strlen($bin); $i += 4) {
            $hex .= dechex(bindec(substr($bin, $i, 4)));
        }

        // Pad the hexadecimal string with zeros up to 256 bits (64 characters)
        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    function hex256BitStringToStr($hex)
    {
        // Validate the input
        // if (strlen($hex) != 64 || !ctype_xdigit($hex)) {
        //     throw new InvalidArgumentException("Invalid 256-bit hex string.");
        // }

        // Remove leading zeros from the hexadecimal string
        $hex = ltrim($hex, '0');

        // Convert the hexadecimal string to a binary string
        $bin = '';
        for ($i = 0; $i < strlen($hex); $i++) {
            $bin .= str_pad(decbin(hexdec($hex[$i])), 4, '0', STR_PAD_LEFT);
        }

        // Convert the binary string back to a text string
        $str = '';
        for ($i = 0; $i < strlen($bin); $i += 8) {
            $str .= chr(bindec(substr($bin, $i, 8)));
        }

        return $str;
    }
}
