<?php
namespace Opencart\Catalog\Model\Extension\OpencartImsafuExtension\Payment;

class Imsafu extends \Opencart\System\Engine\Model
{
	public function getMethods(array $address): array
	{
		$this->load->language('extension/opencart_imsafu_extension/payment/imsafu');

		$option_data['imsafu'] = [
			'code' => 'imsafu.imsafu',
			'name' => $this->language->get('heading_title')
		];

		$method_data = [
			'code' => 'imsafu',
			'name' => $this->language->get('heading_title'),
			'option' => $option_data,
			'sort_order' => $this->config->get('payment_imsafu_sort_order')
		];

		return $method_data;
	}
}