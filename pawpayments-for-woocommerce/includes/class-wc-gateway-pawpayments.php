<?php

if (!defined('ABSPATH')) exit;

class WC_Gateway_PawPayments extends WC_Payment_Gateway
{
    const SUPPORTED_FIATS = [
        'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'NZD', 'SGD', 'HKD',
        'NGN', 'KRW', 'ILS', 'RON', 'ARS', 'INR', 'IDR', 'MXN', 'MYR', 'TRY',
        'PLN', 'BRL', 'THB',
    ];

    public function __construct()
    {
        $this->id = 'pawpayments';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'PawPayments (Crypto)';
        $this->method_description = 'Accept cryptocurrency payments via PawPayments';
        $this->supports = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'pawpayments'),
                'type' => 'checkbox',
                'label' => __('Enable PawPayments', 'pawpayments'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'pawpayments'),
                'type' => 'text',
                'description' => __('Payment method title shown to customer', 'pawpayments'),
                'default' => 'Cryptocurrency',
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'pawpayments'),
                'type' => 'textarea',
                'description' => __('Description shown during checkout', 'pawpayments'),
                'default' => 'Pay with Bitcoin, Ethereum, USDT or other cryptocurrencies',
            ],
            'api_key' => [
                'title' => __('API Key', 'pawpayments'),
                'type' => 'text',
                'description' => __('Your PawPayments API key from the merchant dashboard', 'pawpayments'),
                'default' => '',
            ],
            'api_base_url' => [
                'title' => __('API Base URL', 'pawpayments'),
                'type' => 'text',
                'description' => __('PawPayments API base URL', 'pawpayments'),
                'default' => 'https://api.pawpayments.com',
            ],
            'debug_log' => [
                'title' => __('Debug Log', 'pawpayments'),
                'type' => 'checkbox',
                'label' => __('Enable debug logging', 'pawpayments'),
                'default' => 'no',
                'description' => __('Log events to WooCommerce logs', 'pawpayments'),
            ],
        ];
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Order not found', 'pawpayments'), 'error');
            return;
        }

        $apiKey = $this->get_option('api_key');
        $baseUrl = $this->get_option('api_base_url') ?: 'https://api.pawpayments.com';

        $client = new \PawPayments\Sdk\PawPaymentsClient($apiKey, $baseUrl);

        $orderCurrency = strtoupper((string) $order->get_currency());
        $fiatCurrency = in_array($orderCurrency, self::SUPPORTED_FIATS, true) ? $orderCurrency : 'USD';
        if ($fiatCurrency !== $orderCurrency) {
            $this->log('warning', 'Order #' . $order_id . ' currency ' . $orderCurrency . ' is not supported by PawPayments; falling back to USD');
        }

        try {
            $data = $client->createInvoice([
                'extra' => (string) $order_id,
                'amount' => (float) $order->get_total(),
                'fiat_currency' => $fiatCurrency,
                'billing_type' => 'VARY',
                'on_paid_url' => $this->get_return_url($order),
                'on_cancel_url' => $order->get_cancel_order_url_raw(),
                'notify_url' => WC()->api_request_url('pawpayments'),
                'metadata' => [
                    'source' => 'woocommerce',
                    'flow' => 'checkout',
                    'order_id' => (string) $order_id,
                ],
            ]);
        } catch (\PawPayments\Sdk\Exception\PawPaymentsApiException $e) {
            $this->log('error', 'Invoice creation failed for order #' . $order_id . ': ' . $e->getMessage());
            wc_add_notice(__('Payment error: ', 'pawpayments') . $e->getMessage(), 'error');
            return;
        }

        $paymentUrl = $data['payment_url'] ?? '';
        if (!$paymentUrl) {
            wc_add_notice(__('No payment URL returned', 'pawpayments'), 'error');
            return;
        }

        $pawOrderId = $data['order_id'] ?? '';
        $order->update_meta_data('_pawpayments_order_id', $pawOrderId);
        $order->update_status('on-hold', __('Awaiting crypto payment', 'pawpayments'));
        $order->save();

        WC()->cart->empty_cart();

        return [
            'result' => 'success',
            'redirect' => $paymentUrl,
        ];
    }

    private function log(string $level, string $message): void
    {
        if ($this->get_option('debug_log') === 'yes') {
            $logger = wc_get_logger();
            $logger->log($level, $message, ['source' => 'pawpayments']);
        }
    }
}
