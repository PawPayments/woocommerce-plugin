<?php

use PHPUnit\Framework\TestCase;

/**
 * WC_Gateway_PawPayments currency rules: the gateway is offered only when the
 * currency to be paid can be invoiced as is, and process_payment() never
 * re-labels a total in another currency.
 */
class GatewayTest extends TestCase
{
    protected function setUp(): void
    {
        WcState::reset();
    }

    public function testAvailableForASupportedStoreCurrency(): void
    {
        $GLOBALS['wc']['store_currency'] = 'EUR';

        $this->assertTrue((new WC_Gateway_PawPayments())->is_available());
    }

    public function testHiddenForAnUnsupportedStoreCurrency(): void
    {
        $GLOBALS['wc']['store_currency'] = 'RUB';

        $this->assertFalse((new WC_Gateway_PawPayments())->is_available());
    }

    public function testOnOrderPayTheOrdersCurrencyDecides(): void
    {
        // A currency switcher may show USD while the order was placed in RUB.
        $GLOBALS['wc']['store_currency'] = 'USD';
        $GLOBALS['wc']['endpoint'] = 'order-pay';
        $GLOBALS['wc']['query'] = ['order-pay' => '12'];
        $GLOBALS['wc']['orders'][12] = new FakeOrder('pawpayments', [], 'RUB');

        $this->assertFalse((new WC_Gateway_PawPayments())->is_available());

        $GLOBALS['wc']['store_currency'] = 'RUB';
        $GLOBALS['wc']['orders'][12] = new FakeOrder('pawpayments', [], 'USD');

        $this->assertTrue((new WC_Gateway_PawPayments())->is_available());
    }

    public function testOrderPayForAnUnknownOrderFallsBackToTheStoreCurrency(): void
    {
        $GLOBALS['wc']['store_currency'] = 'USD';
        $GLOBALS['wc']['endpoint'] = 'order-pay';
        $GLOBALS['wc']['query'] = ['order-pay' => '999'];

        $this->assertTrue((new WC_Gateway_PawPayments())->is_available());
    }

    public function testNeverAvailableWhenWooCommerceSaysNo(): void
    {
        $GLOBALS['wc']['parent_available'] = false;

        $this->assertFalse((new WC_Gateway_PawPayments())->is_available());
    }

    public function testUnsupportedOrderCurrencyIsRefusedNotConvertedToUsd(): void
    {
        $GLOBALS['wc']['orders'][5] = new FakeOrder('pawpayments', [], 'rub', '1000.00');

        $result = (new WC_Gateway_PawPayments())->process_payment(5);

        $this->assertNull($result);
        $this->assertSame(
            [['error', 'PawPayments does not accept payments in RUB. Please choose another payment method.']],
            $GLOBALS['wc']['notices']
        );
    }

    public function testMissingOrderIsRefused(): void
    {
        $this->assertNull((new WC_Gateway_PawPayments())->process_payment(404));
        $this->assertSame([['error', 'Order not found']], $GLOBALS['wc']['notices']);
    }
}
