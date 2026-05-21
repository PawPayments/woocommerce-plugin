<?php
/**
 * Plugin Name: PawPayments for WooCommerce
 * Plugin URI: https://pawpayments.com
 * Description: Accept cryptocurrency payments via PawPayments
 * Version: 2.0.0
 * Author: PawPayments
 * Author URI: https://pawpayments.com
 * License: MIT
 * Requires at least: 6.0
 * Tested up to: 6.7
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 * Requires PHP: 7.4
 * Text Domain: pawpayments
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('PAWPAYMENTS_VERSION', '2.0.0');
define('PAWPAYMENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));

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
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});
