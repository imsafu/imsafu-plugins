<?php
namespace Opencart\Catalog\Controller\Extension\OpencartImsafuExtension\Payment;

class Imsafu extends \Opencart\System\Engine\Controller
{

	// for live mode
	private $live_api_url = 'https://allowpay-api-mainnet.fly.dev/v1';
	private $live_web_url = 'https://imsafu.com';

	// for test mode
	private $test_api_url = 'https://allowpay-api-devnet.fly.dev/v1';
	private $test_web_url = 'https://devnet.imsafu.com';


	public function index(): string
	{
		$this->load->language('extension/opencart_imsafu_extension/payment/imsafu');

		$data['logged'] = $this->customer->isLogged();
		$data['language'] = $this->config->get('config_language');

		return $this->load->view('extension/opencart_imsafu_extension/payment/imsafu', $data);
	}

	public function confirm(): void
	{
		$this->load->language('extension/opencart_imsafu_extension/payment/imsafu');

		$json = [];

		if (!isset($this->session->data['order_id'])) {
			$json['error']['warning'] = $this->language->get('error_order');
		}

		if (!isset($this->session->data['payment_method']) || $this->session->data['payment_method']['code'] != 'imsafu.imsafu') {
			$json['error']['warning'] = $this->language->get('error_payment_method');
		}

		if (!$json) {
			// Set imsafu response
			if ($this->config->get('payment_imsafu_response')) {
				$this->load->model('checkout/order');

				// get order info
				$order_id = $this->session->data['order_id'];
				$order_info = $this->model_checkout_order->getOrder($order_id);

				// imsafu order info
				$is_testmode = $this->config->get('payment_imsafu_testmode');
				$imsafu_api = $is_testmode ? $this->test_api_url : $this->live_api_url;
				$imsafu_order_id = $this->convertTo64BitHex($order_id . '|' . time());
				$receiver = $this->config->get('payment_imsafu_receiver');
				$amount = $order_info['total'];
				$deadline = date('c', strtotime('+1 day')); // unix timestamp + 1 day 
				$notify_url = $this->url->link('extension/opencart_imsafu_extension/payment/imsafu|callback', '');
				$redirect_url = $this->url->link('extension/opencart_imsafu_extension/payment/imsafu|redirect', '');
				$api_key = $this->config->get('payment_imsafu_api_key');
				$currency = $this->config->get('payment_imsafu_currency');

				// imsafu: creat mechant order
				$depost_pay_url = $imsafu_api . '/depositPay';
				$payment_json = array(
					'payment' => array(
						'orderID' => $imsafu_order_id,
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
						'brand' => $order_info['store_name'],
						'memo' => 'Order #' . $order_id,
						'redirect_url' => $redirect_url,
						'currency' => $currency,
					);

					$json['user_payment_url'] = ($is_testmode ? $this->test_web_url : $this->live_web_url) . '/payment_qrcode?' . http_build_query($params);
					$json['status'] = 'ok';
					$json['message'] = '';
				} else {
					$json['status'] = 'failed';
					$json['message'] = 'Failed to create imsafu order:' . $return_content;
				}
				$this->response->addHeader('Content-Type: application/json');
				$this->response->setOutput(json_encode($json));

			} else {
				$this->load->model('checkout/order');

				$this->model_checkout_order->addHistory($this->session->data['order_id'], $this->config->get('payment_imsafu_failed_status_id'), '', true);

				$json['redirect'] = $this->url->link('checkout/failure', 'language=' . $this->config->get('config_language'), true);
			}
		}


	}

	public function callback(): void
	{
		// {"payment":{"payID":"pay_01GZ8FYDR1E7VNM1J85EP4T0EG"}}
		$json_str = file_get_contents('php://input');
		$json = json_decode($json_str);
		if (!isset($json->payment) || !isset($json->payment->payID)) {
			return;
		}
		$imsafu_pay_id = $json->payment->payID;

		// fetch imsafu order detail
		$imsafu_order_json = $this->getImsafuOrderDetail($imsafu_pay_id);

		// update order status
		if ($imsafu_order_json !== null && $imsafu_order_json->status === 'success') {
			$this->load->model('checkout/order');
			$this->load->model('extension/opencart_imsafu_extension/payment/imsafu');

			$order_id = explode("|", $this->convertFrom64BitHex($imsafu_order_json->payment->orderID))[0];
			$this->model_checkout_order->addHistory($order_id, $this->config->get('payment_imsafu_approved_status_id'), 'imsafu PayID: ' . $imsafu_pay_id, true);
		}

		$response = [];
		$response['status'] = 'ok';
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($response));
	}

	public function redirect(): void
	{
		if ($this->request->get && isset($this->request->get['payID'])) {
			$imsafu_pay_id = $this->request->get['payID'];
			// fetch imsafu order detail
			$imsafu_order_json = $this->getImsafuOrderDetail($imsafu_pay_id);

			if ($imsafu_order_json !== null && $imsafu_order_json->status === 'success') {
				$this->response->redirect($this->url->link('checkout/success', 'language=' . $this->config->get('config_language'), true));
			} else {
				$this->response->redirect($this->url->link('checkout/failure', 'language=' . $this->config->get('config_language'), true));
			}
		}
	}

	function getImsafuOrderDetail($imsafu_pay_id)
	{
		$is_testmode = $this->config->get('payment_imsafu_testmode');
		$imsafu_api = $is_testmode ? $this->test_api_url : $this->live_api_url;
		try {
			$deposit_pays_url = $imsafu_api . '/depositPays/' . $imsafu_pay_id;
			list($return_code, $return_content) = $this->http_get($deposit_pays_url);
			$return_content_json = json_decode($return_content);
			return $return_content_json;
		} catch (Exception $e) {
			return null;
		}
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
}