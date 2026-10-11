<?php
if (!defined('ABSPATH')) exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * The gateway in the Checkout block, the default checkout of new stores since
 * WooCommerce 8.3, which offers only the payment methods registered with it.
 *
 * The block only shows the method (assets/js/blocks.js). Paying still goes
 * through WC_Gateway_PawPayments::process_payment(), which the Store API calls
 * and whose redirect the block follows; and the Store API offers the method
 * only while the gateway is_available(), so an unsupported store currency
 * hides it here as it does on the classic checkout.
 */
final class WC_PawPayments_Blocks extends AbstractPaymentMethodType
{
    protected $name = 'pawpayments';

    public function initialize()
    {
        $this->settings = (array) get_option('woocommerce_pawpayments_settings', []);
    }

    public function is_active()
    {
        $gateway = $this->gateway();

        return $gateway !== null && $gateway->enabled === 'yes';
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'wc-pawpayments-blocks',
            PAWPAYMENTS_PLUGIN_URL . 'assets/js/blocks.js',
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'],
            PAWPAYMENTS_VERSION,
            true
        );

        return ['wc-pawpayments-blocks'];
    }

    public function get_payment_method_data()
    {
        $gateway = $this->gateway();

        return [
            'title' => $gateway ? $gateway->title : '',
            'description' => $gateway ? $gateway->description : '',
            'supports' => $gateway ? array_values(array_filter($gateway->supports, [$gateway, 'supports'])) : [],
        ];
    }

    /** The gateway instance WooCommerce loaded, with the title and description defaults applied. */
    private function gateway(): ?WC_Gateway_PawPayments
    {
        $gateways = WC()->payment_gateways()->payment_gateways();

        return $gateways[$this->name] ?? null;
    }
}
