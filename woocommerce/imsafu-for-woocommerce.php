<?php
/*
 * Plugin Name: 		imsafu Payment Gateway
 * Plugin URI: 			https://imsafu.com/
 * Description: 		Instant Payment API for E-commerce. Receive Crypto Payments from All Over the World.
 * Version: 			1.0
 * License:           	GPL v2 or later
 * License URI:       	https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 7.4
 * WC tested up to: 7.4
 */

define('imsafu_Payment_ID', 'imsafu-payment-wc');

// Make sure WooCommerce is active.
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
	return;
}

// Add Hooks:
add_filter('woocommerce_payment_gateways', 'imsafu_add_payment_gateway');
add_action('plugins_loaded', 'imsafu_init_gateway_class');
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'imsafu_add_action_links', 10, 1);

function imsafu_add_payment_gateway($gateways)
{
	$gateways[] = "WC_Payment_Gateway_imsafu";
	return $gateways;
}

function imsafu_init_gateway_class()
{
	// Define the payment gateway class.
	class WC_Payment_Gateway_imsafu extends WC_Payment_Gateway
	{
		// for live mode
		private $live_api_url = 'https://allowpay-api-mainnet.fly.dev/v1';
		// private $live_api_url = 'https://allowpay-api-staging.fly.dev/v1';

		private $live_web_url = 'https://imsafu.com';
		// private $live_web_url = 'https://staging.imsafu.com';

		// for test mode
		private $test_api_url = 'https://allowpay-api-devnet.fly.dev/v1';
		private $test_web_url = 'https://devnet.imsafu.com';

		public function __construct()
		{
			$this->id = imsafu_Payment_ID;
			$this->icon = '';
			$this->has_fields = false;

			$this->method_title = 'imsafu Payment Gateway';
			$this->method_description = 'Instant Payment API for E-commerce.';

			$this->init_form_fields();
			$this->init_settings();

			$this->title = $this->get_option('title');
			$this->description = $this->get_option('description');

			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			add_action('woocommerce_api_imsafu_callback', array($this, 'callback'));
			add_action('woocommerce_api_imsafu_redirect', array($this, 'redirect'));

		}

		public function init_form_fields()
		{
			$this->form_fields = array(
				'enabled' => array(
					'title' => __('Enable/Disable', 'woocommerce'),
					'type' => 'checkbox',
					'label' => __('Enable Payment Gateway', 'woocommerce'),
					'default' => 'no',
				),
				'test_mode' => array(
					'title' => __('Test Mode', 'woocommerce'),
					'type' => 'checkbox',
					'label' => __('Enable Test Mode', 'woocommerce'),
					'default' => 'no',
				),
				'title' => array(
					'title' => __('Title', 'woocommerce'),
					'type' => 'text',
					'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
					'default' => __('imsafu Payment', 'woocommerce'),
					'desc_tip' => true,
				),
				'description' => array(
					'title' => __('Description', 'woocommerce'),
					'type' => 'textarea',
					'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
					'default' => __('Instant Payment API for E-commerce.', 'woocommerce'),
				),
				'receiver_wallet_address' => array(
					'title' => __('Receiver Wallet Address', 'woocommerce'),
					'type' => 'text',
					'description' => __('You can use either the wallet address or the exchange\'s deposit address as the receiver address.', 'woocommerce'),
					'desc_tip' => true

				),
				'api_key' => array(
					'title' => __('API Key', 'woocommerce'),
					'type' => 'text',
					'description' => __('To obtain an API key, simply click \'Join Waitlist\' on https://imsafu.com and complete the form provided.', 'woocommerce'),
					'desc_tip' => true

				),
				'currency' => array(
					'title' => __('Currency', 'woocommerce'),
					'type' => 'select',
					'description' => __('By default, USD is the currency used for payment. However, if you choose a different currency (such as CNY), it will be automatically converted to USD based on the prevailing exchange rate.', 'woocommerce'),
					'default' => 'USD',
					'desc_tip' => true,
					'options' => array(
						'USD' => 'USD',
						'CNY' => 'CNY',
					),
				),
			);
		}

		public function process_payment($order_id)
		{
			// get order info
			$order = wc_get_order($order_id);

			// imsafu order info
			$is_testmode = $this->get_option('test_mode') === 'yes';
			$store_name = get_bloginfo('name');
			$imsafu_api = $is_testmode ? $this->test_api_url : $this->live_api_url;
			$imsafu_order_id = $this->convertTo64BitHex($order_id . '|' . time());
			$receiver = $this->get_option('receiver_wallet_address');
			$amount = $order->total;
			$deadline = date('c', strtotime('+1 day'));
			$notify_url = WC()->api_request_url('imsafu_callback');
			$redirect_url = WC()->api_request_url('imsafu_redirect');
			$api_key = $this->get_option('api_key');
			$currency = $this->get_option('currency');

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

			$response = wp_remote_post(
				$depost_pay_url,
				array(
					'method' => 'POST',
					'headers' => array('Content-Type' => 'application/json; charset=utf-8', 'Authorization' => 'Bearer ' . $api_key),
					'body' => json_encode($payment_json),
				)
			);

			if (is_wp_error($response)) {
				return array(
					'result' => 'error',
					'message' => $response->get_error_message(),
				);
			}

			$depost_pay_response = wp_remote_retrieve_body($response);
			$depost_pay_response_json = json_decode($depost_pay_response);
			$return_code = wp_remote_retrieve_response_code($response);

			if ($return_code === 200) {
				$depost_pay_response_json = json_decode($depost_pay_response);
				$payID = $depost_pay_response_json->payment->payID;

				// save imsafu_pay_id to order meta_data
				$order->update_meta_data('imsafu_pay_id', $payID);

				// update order status
				$order->update_status('pending', __('Awaiting payment confirmation from imsafu Payment Gateway.', 'woocommerce'));

				$params = array(
					'payID' => $payID,
					'brand' => $store_name,
					'memo' => 'Order #' . $order_id,
					'redirect_url' => $redirect_url,
					'currency' => $currency,
				);

				return array(
					'result' => 'success',
					'depost_pay_url' => $depost_pay_url,
					'$return_code' => $return_code,
					'$depost_pay_response' => $depost_pay_response,
					'redirect' => ($is_testmode ? $this->test_web_url : $this->live_web_url) . '/payment_qrcode?' . http_build_query($params),
				);

			} else {
				return array(
					'result' => 'error',
					'code' => $return_code,
				);
			}
		}

		public function callback()
		{
			// {"payment":{"payID":"pay_01GZ8FYDR1E7VNM1J85EP4T0EG"}}
			$json_str = file_get_contents('php://input');
			$json = json_decode($json_str);
			if (!isset($json->payment) || !isset($json->payment->payID)) {
				wp_die('Invalid request');
			}
			$imsafu_pay_id = $json->payment->payID;

			// fetch imsafu order detail
			$imsafu_order_json = $this->getImsafuOrderDetail($imsafu_pay_id);

			// update order status
			if ($imsafu_order_json !== null && $imsafu_order_json->status === 'success') {
				$order_id = explode("|", $this->convertFrom64BitHex($imsafu_order_json->payment->orderID))[0];
				$order = wc_get_order($order_id);
				$order->update_status('processing', __('imsafu payment received.', 'woocommerce'));
			}

			$response = array(
				'status' => 'ok',
			);
			wp_send_json($response);
		}

		public function redirect()
		{
			if (isset($_GET['payID']) && !empty($_GET['payID'])) {
				$imsafu_pay_id = sanitize_text_field($_GET['payID']);
				if ($imsafu_pay_id) {

					// fetch imsafu order detail
					$imsafu_order_json = $this->getImsafuOrderDetail($imsafu_pay_id);

					if ($imsafu_order_json !== null && $imsafu_order_json->status === 'success') {
						$order_id = explode("|", $this->convertFrom64BitHex($imsafu_order_json->payment->orderID))[0];
						$order = wc_get_order($order_id);
						$success_page_url = esc_url($this->get_return_url($order));

						// empty cart if paid successfully
						WC()->cart->empty_cart();

						wp_safe_redirect($success_page_url);
						exit;
					} else {
						$error_page_url = esc_url(wc_get_page_permalink('checkout'));
						wp_safe_redirect($error_page_url);
						exit;
					}
				}
			}
		}

		function getImsafuOrderDetail($imsafu_pay_id)
		{
			$is_testmode = $this->get_option('test_mode') === 'yes';
			$imsafu_api = $is_testmode ? $this->test_api_url : $this->live_api_url;
			try {
				$deposit_pays_url = $imsafu_api . '/depositPays/' . $imsafu_pay_id;
				$response = wp_remote_get($deposit_pays_url);
				if (is_wp_error($response)) {
					throw new Exception($response->get_error_message());
				}

				$return_content = wp_remote_retrieve_body($response);
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
	}
}

function imsafu_add_action_links($links)
{
	return array_merge(
		array(
			'settings' => '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=' . imsafu_Payment_ID) . '">' . __('Settings', 'woocommerce') . '</a>'
		),
		$links
	);
}