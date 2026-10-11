<?php

use PHPUnit\Framework\TestCase;

/**
 * WC_PawPayments_Blocks: what the Checkout block gets to show the gateway.
 */
class BlocksTest extends TestCase
{
    protected function setUp(): void
    {
        WcState::reset();
        $GLOBALS['wc']['options'] += ['enabled' => 'yes', 'title' => 'Crypto', 'description' => 'Pay with crypto'];
        $GLOBALS['wc']['gateways'] = ['pawpayments' => new WC_Gateway_PawPayments()];
    }

    private function blocks(): WC_PawPayments_Blocks
    {
        $blocks = new WC_PawPayments_Blocks();
        $blocks->initialize();

        return $blocks;
    }

    public function testNamedAfterTheGateway(): void
    {
        $this->assertSame((new WC_Gateway_PawPayments())->id, $this->blocks()->get_name());
    }

    public function testActiveWhileTheGatewayIsEnabled(): void
    {
        $this->assertTrue($this->blocks()->is_active());
    }

    public function testInactiveWhenDisabledOrNotLoaded(): void
    {
        $GLOBALS['wc']['options']['enabled'] = 'no';
        $GLOBALS['wc']['gateways'] = ['pawpayments' => new WC_Gateway_PawPayments()];
        $this->assertFalse($this->blocks()->is_active());

        $GLOBALS['wc']['gateways'] = [];
        $this->assertFalse($this->blocks()->is_active());
    }

    public function testDataIsTheGatewaysTitleDescriptionAndSupportedFeatures(): void
    {
        $this->assertSame(
            ['title' => 'Crypto', 'description' => 'Pay with crypto', 'supports' => ['products']],
            $this->blocks()->get_payment_method_data()
        );
    }

    public function testScriptIsRegisteredForTheBlocksRegistry(): void
    {
        $this->assertSame(['wc-pawpayments-blocks'], $this->blocks()->get_payment_method_script_handles());

        $script = $GLOBALS['wc']['scripts']['wc-pawpayments-blocks'];
        $this->assertSame(PAWPAYMENTS_PLUGIN_URL . 'assets/js/blocks.js', $script['src']);
        $this->assertContains('wc-blocks-registry', $script['deps']);
        $this->assertContains('wc-settings', $script['deps']);
    }

    public function testScriptRegistersTheSameNameAndReadsItsData(): void
    {
        // The block pairs the script with the PHP side by name.
        $js = file_get_contents(dirname(__DIR__) . '/pawpayments-for-woocommerce/assets/js/blocks.js');
        $name = $this->blocks()->get_name();

        $this->assertStringContainsString("name: '{$name}'", $js);
        $this->assertStringContainsString("getSetting('{$name}_data'", $js);
    }
}
