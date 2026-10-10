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

    /**
     * Hide the gateway when the currency to be paid cannot be invoiced: the
     * order's own currency on the order-pay page (it can differ from the one a
     * currency switcher shows), the store currency at checkout.
     */
    public function is_available()
    {
        return parent::is_available() && self::supports_currency($this->payable_currency());
    }

    private function payable_currency(): string
    {
        $orderId = function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')
            ? absint(get_query_var('order-pay'))
            : 0;
        $order = $orderId ? wc_get_order($orderId) : null;

        return $order ? (string) $order->get_currency() : (string) get_woocommerce_currency();
    }

    private static function supports_currency(string $currency): bool
    {
        return in_array(strtoupper($currency), self::SUPPORTED_FIATS, true);
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

        // Never re-label the total in another currency: an unsupported currency
        // cannot be paid through this gateway at all.
        $fiatCurrency = strtoupper((string) $order->get_currency());
        if (!self::supports_currency($fiatCurrency)) {
            $this->log('warning', 'Order #' . $order_id . ' currency ' . $fiatCurrency . ' is not supported by PawPayments');
            wc_add_notice(
                sprintf(__('PawPayments does not accept payments in %s. Please choose another payment method.', 'pawpayments'), $fiatCurrency),
                'error'
            );
            return;
        }

        try {
            $data = $client->createInvoice([
                'extra' => (string) $order_id,
                'amount' => (float) $order->get_total(),
                'fiat_currency' => $fiatCurrency,
                // Fixed-price order: STATIC keeps the invoice open after an underpayment so the
                // customer can top it up; VARY (right for balance top-ups) finalises on the
                // first payment, making a shortfall terminal.
                'billing_type' => 'STATIC',
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
