<?php
// The part of WooCommerce Blocks' AbstractPaymentMethodType the plugin relies on.

namespace Automattic\WooCommerce\Blocks\Payments\Integrations;

abstract class AbstractPaymentMethodType
{
    protected $name = '';
    protected $settings = [];

    abstract public function initialize();

    public function get_name()
    {
        return $this->name;
    }

    public function get_setting($name, $default = '')
    {
        return $this->settings[$name] ?? $default;
    }
}
