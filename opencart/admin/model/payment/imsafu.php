<?php
namespace Opencart\Admin\Model\Extension\OpencartImsafuExtension\Payment;

class Imsafu extends \Opencart\System\Engine\Model
{
	public function charge(int $customer_id, int $customer_payment_id, float $amount): int
	{
		$this->load->language('extension/opencart_imsafu_extension/payment/imsafu');

		$json = [];

		if (!$json) {

		}

		return $this->config->get('config_subscription_active_status_id');
	}
}