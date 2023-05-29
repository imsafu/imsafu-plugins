<?php
namespace Opencart\Admin\Controller\Extension\OpencartImsafuExtension\Payment;

class Imsafu extends \Opencart\System\Engine\Controller
{
	public function index(): void
	{
		$this->load->language('extension/opencart_imsafu_extension/payment/imsafu');

		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment')
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/opencart_imsafu_extension/payment/imsafu', 'user_token=' . $this->session->data['user_token'])
		];

		$data['save'] = $this->url->link('extension/opencart_imsafu_extension/payment/imsafu|save', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');

		$data['payment_imsafu_response'] = $this->config->get('payment_imsafu_response');
		$data['payment_imsafu_api_key'] = $this->config->get('payment_imsafu_api_key');
		$data['payment_imsafu_currency'] = $this->config->get('payment_imsafu_currency');
		$data['payment_imsafu_receiver'] = $this->config->get('payment_imsafu_receiver');
		$data['payment_imsafu_testmode'] = $this->config->get('payment_imsafu_testmode');
		$data['payment_imsafu_approved_status_id'] = $this->config->get('payment_imsafu_approved_status_id');
		$data['payment_imsafu_failed_status_id'] = $this->config->get('payment_imsafu_failed_status_id');

		$this->load->model('localisation/order_status');

		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		$data['payment_imsafu_geo_zone_id'] = $this->config->get('payment_imsafu_geo_zone_id');

		$this->load->model('localisation/geo_zone');

		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		$data['payment_imsafu_status'] = $this->config->get('payment_imsafu_status');
		$data['payment_imsafu_sort_order'] = $this->config->get('payment_imsafu_sort_order');

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/opencart_imsafu_extension/payment/imsafu', $data));
	}

	public function save(): void
	{
		$this->load->language('extension/opencart_imsafu_extension/payment/imsafu');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/opencart_imsafu_extension/payment/imsafu')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$this->load->model('setting/setting');

			$this->model_setting_setting->editSetting('payment_imsafu', $this->request->post);

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function order()
	{
		return '';
	}

	// install hook
	public function install(): void
	{
	}

	// uninstall hook
	public function uninstall(): void
	{
	}
}