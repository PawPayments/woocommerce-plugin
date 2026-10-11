<?php
/**
 * Plugin Name: PawPayments for WooCommerce
 * Plugin URI: https://pawpayments.com
 * Description: Accept cryptocurrency payments via PawPayments
 * Version: 2.1.1
 * Author: PawPayments
 * Author URI: https://pawpayments.com
 * License: MIT
 * Requires at least: 6.0
 * Tested up to: 7.1
 * WC requires at least: 7.0
 * WC tested up to: 11.2
 * Requires PHP: 7.4
 * Text Domain: pawpayments
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('PAWPAYMENTS_VERSION', '2.1.1');
define('PAWPAYMENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PAWPAYMENTS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once PAWPAYMENTS_PLUGIN_DIR . 'vendor/pawpayments/sdk/src/Exception/PawPaymentsApiException.php';
require_once PAWPAYMENTS_PLUGIN_DIR . 'vendor/pawpayments/sdk/src/Version.php';
require_once PAWPAYMENTS_PLUGIN_DIR . 'vendor/pawpayments/sdk/src/PawPaymentsClient.php';
require_once PAWPAYMENTS_PLUGIN_DIR . 'vendor/pawpayments/sdk/src/Webhook.php';

add_action('plugins_loaded', 'pawpayments_init', 11);

function pawpayments_init()
{
    if (!class_exists('WC_Payment_Gateway')) return;

    require_once PAWPAYMENTS_PLUGIN_DIR . 'includes/class-wc-gateway-pawpayments.php';
    require_once PAWPAYMENTS_PLUGIN_DIR . 'includes/class-wc-pawpayments-webhook.php';

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'WC_Gateway_PawPayments';
        return $gateways;
    });

    add_action('woocommerce_api_pawpayments', ['WC_PawPayments_Webhook', 'handle']);
}

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        foreach (['custom_order_tables', 'cart_checkout_blocks'] as $feature) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility($feature, __FILE__, true);
        }
    }
});

// The Checkout block (the default checkout since WooCommerce 8.3) offers only
// the payment methods registered with it.
add_action('woocommerce_blocks_loaded', function () {
    if (!class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
        return;
    }
    require_once PAWPAYMENTS_PLUGIN_DIR . 'includes/class-wc-pawpayments-blocks.php';
    add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
        $registry->register(new WC_PawPayments_Blocks());
    });
});
