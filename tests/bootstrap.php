<?php
/**
 * Standalone bootstrap: the WooCommerce plugin outside WordPress.
 *
 * The webhook's verify()/decide() are pure. The gateway's currency rules reach
 * a few WordPress/WooCommerce globals, stubbed below and driven by
 * $GLOBALS['wc'] (reset per test with WcState::reset()).
 */

define('ABSPATH', __DIR__ . '/');
define('PAWPAYMENTS_PLUGIN_URL', 'https://shop.example/wp-content/plugins/pawpayments-for-woocommerce/');
define('PAWPAYMENTS_VERSION', 'test');

final class WcState
{
    public static function reset(): void
    {
        $GLOBALS['wc'] = [
            'options' => ['api_key' => 'test_key', 'api_base_url' => 'https://api.example'],
            'parent_available' => true,
            'endpoint' => '',        // the WooCommerce endpoint of the request, e.g. 'order-pay'
            'query' => [],
            'orders' => [],
            'store_currency' => 'USD',
            'notices' => [],
            'wp_options' => [],      // get_option()
            'gateways' => [],        // WC()->payment_gateways()->payment_gateways()
            'scripts' => [],         // wp_register_script() calls
        ];
    }
}
WcState::reset();

/** The properties WooCommerce's WC_Payment_Gateway declares, so none is created dynamically. */
class WC_Payment_Gateway
{
    public $id;
    public $icon;
    public $has_fields;
    public $method_title;
    public $method_description;
    public $supports = [];
    public $title;
    public $description;
    public $enabled;
    public $form_fields = [];

    public function init_form_fields()
    {
    }

    public function init_settings()
    {
    }

    public function get_option($key, $empty = null)
    {
        return $GLOBALS['wc']['options'][$key] ?? $empty;
    }

    public function is_available()
    {
        return $GLOBALS['wc']['parent_available'];
    }

    public function get_return_url($order = null)
    {
        return 'https://shop.example/return';
    }

    public function supports($feature)
    {
        return in_array($feature, $this->supports, true);
    }
}

function add_action(...$args)
{
}

function __($text, $domain = 'default')
{
    return $text;
}

function absint($value)
{
    return abs((int) $value);
}

function is_wc_endpoint_url($endpoint = false)
{
    return $GLOBALS['wc']['endpoint'] === $endpoint;
}

function get_query_var($var, $default = '')
{
    return $GLOBALS['wc']['query'][$var] ?? $default;
}

function wc_get_order($id)
{
    return $GLOBALS['wc']['orders'][$id] ?? false;
}

function get_woocommerce_currency()
{
    return $GLOBALS['wc']['store_currency'];
}

function wc_add_notice($message, $type = 'success')
{
    $GLOBALS['wc']['notices'][] = [$type, $message];
}

function get_option($name, $default = false)
{
    return $GLOBALS['wc']['wp_options'][$name] ?? $default;
}

function wp_register_script($handle, $src, $deps = [], $ver = false, $in_footer = false)
{
    $GLOBALS['wc']['scripts'][$handle] = ['src' => $src, 'deps' => $deps];
    return true;
}

/** WC()->payment_gateways()->payment_gateways(), the gateways WooCommerce loaded. */
function WC()
{
    return new class {
        public function payment_gateways()
        {
            return new class {
                public function payment_gateways()
                {
                    return $GLOBALS['wc']['gateways'];
                }
            };
        }
    };
}

/** The WC_Order getters the plugin reads. */
class FakeOrder
{
    public $method;
    public $meta;
    public $currency;
    public $total;
    public $paid;
    public $transactionId;

    public function __construct(string $method = 'pawpayments', array $meta = [], string $currency = 'USD', string $total = '50.00', bool $paid = false, string $transactionId = '')
    {
        $this->method = $method;
        $this->meta = $meta;
        $this->currency = $currency;
        $this->total = $total;
        $this->paid = $paid;
        $this->transactionId = $transactionId;
    }

    public function is_paid()
    {
        return $this->paid;
    }

    public function get_transaction_id()
    {
        return $this->transactionId;
    }

    public function get_payment_method()
    {
        return $this->method;
    }

    public function get_meta($key, $single = true)
    {
        return $this->meta[$key] ?? '';
    }

    public function get_currency()
    {
        return $this->currency;
    }

    public function get_total()
    {
        return $this->total;
    }
}

$plugin = dirname(__DIR__) . '/pawpayments-for-woocommerce';
require_once $plugin . '/vendor/pawpayments/sdk/src/Exception/PawPaymentsApiException.php';
require_once $plugin . '/vendor/pawpayments/sdk/src/Version.php';
require_once $plugin . '/vendor/pawpayments/sdk/src/PawPaymentsClient.php';
require_once $plugin . '/vendor/pawpayments/sdk/src/Webhook.php';
require_once $plugin . '/includes/class-wc-gateway-pawpayments.php';
require_once $plugin . '/includes/class-wc-pawpayments-webhook.php';
require_once __DIR__ . '/stubs/blocks.php';
require_once $plugin . '/includes/class-wc-pawpayments-blocks.php';
