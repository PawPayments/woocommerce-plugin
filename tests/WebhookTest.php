<?php

use PHPUnit\Framework\TestCase;

/**
 * WC_PawPayments_Webhook: verify() — what reaches an order at all — and
 * decide() — what a verified webhook does to it. Order 7 is paid with this
 * gateway, invoice po1 stored for it, 50.00 USD, unless a test says otherwise.
 */
class WebhookTest extends TestCase
{
    private const KEY = 'woo_test_key_0123456789';

    private function order(array $over = []): FakeOrder
    {
        return new FakeOrder(
            $over['method'] ?? 'pawpayments',
            $over['meta'] ?? ['_pawpayments_order_id' => 'po1'],
            $over['currency'] ?? 'USD',
            $over['total'] ?? '50.00'
        );
    }

    private function decide(array $payload, ?FakeOrder $order = null): array
    {
        return WC_PawPayments_Webhook::decide($payload + ['extra' => '7', 'order_id' => 'po1'], $order ?? $this->order(), 'pawpayments');
    }

    // ─── verify() ───────────────────────────────────────────────────────────

    public function testSignedWebhookYieldsItsPayload(): void
    {
        $body = '{"extra":"7","order_id":"po1","status":"success"}';

        [$payload, $http] = WC_PawPayments_Webhook::verify(self::KEY, $body, hash_hmac('sha256', $body, self::KEY));

        $this->assertSame(['extra' => '7', 'order_id' => 'po1', 'status' => 'success'], $payload);
        $this->assertSame(0, $http);
    }

    public function testEmptyKeyAnswers503EvenForAMatchingSignature(): void
    {
        $body = '{"extra":"7","order_id":"po1","status":"success"}';

        $this->assertSame(
            [null, 503, 'Gateway not configured'],
            WC_PawPayments_Webhook::verify('', $body, hash_hmac('sha256', $body, ''))
        );
    }

    public function testEmptyBodyAnswers400(): void
    {
        $this->assertSame([null, 400, 'Empty body'], WC_PawPayments_Webhook::verify(self::KEY, '', hash_hmac('sha256', '', self::KEY)));
    }

    /** @dataProvider badSignatures */
    public function testBadSignatureAnswers401(string $signature): void
    {
        $body = '{"extra":"7","order_id":"po1","status":"success"}';

        $this->assertSame([null, 401, 'Invalid signature'], WC_PawPayments_Webhook::verify(self::KEY, $body, $signature));
    }

    public function badSignatures(): array
    {
        $body = '{"extra":"7","order_id":"po1","status":"success"}';

        return [
            'missing' => [''],
            'another key' => [hash_hmac('sha256', $body, 'another_store_key')],
            'another body' => [hash_hmac('sha256', '{"extra":"8","order_id":"po1","status":"success"}', self::KEY)],
            'not hex' => [str_repeat('x', 64)],
        ];
    }

    public function testPermanentAddressDepositIsAcknowledgedWithoutAction(): void
    {
        $body = '{"order_id":"po1","status":"success","permanent_address_id":"pa1"}';

        $this->assertSame([null, 200, ''], WC_PawPayments_Webhook::verify(self::KEY, $body, hash_hmac('sha256', $body, self::KEY)));
    }

    // ─── decide(): paid ─────────────────────────────────────────────────────

    public function testPaidWebhookCoveringTheTotalCompletesTheOrder(): void
    {
        $action = $this->decide(['status' => 'success', 'fiat_amount' => 50.0, 'initial_fiat_amount' => 50.0, 'fiat_currency' => 'USD', 'amount' => 50, 'asset' => 'usdt_tron']);

        $this->assertTrue($action['complete']);
        $this->assertNull($action['cancel']);
        $this->assertSame(['PawPayments: Paid 50 usdt_tron (order po1)'], $action['notes']);
        $this->assertSame([200, 'OK'], [$action['http'], $action['body']]);
    }

    public function testPaidOverCompletesTheOrder(): void
    {
        $this->assertTrue($this->decide(['status' => 'paid_over', 'fiat_amount' => 70.0, 'initial_fiat_amount' => 50.0, 'fiat_currency' => 'USD'])['complete']);
    }

    public function testPaidWithinTheMerchantsOwnToleranceCompletesTheOrder(): void
    {
        // The merchant accepts 20% short (dashboard setting, not in the webhook):
        // `success` for 40 received on a 50.00 invoice pays the order.
        $this->assertTrue($this->decide(['status' => 'success', 'fiat_amount' => 40.0, 'initial_fiat_amount' => 50.0, 'fiat_currency' => 'USD'])['complete']);
    }

    /** @dataProvider notCovering */
    public function testPaidWebhookNotCoveringTheOrderLeavesItUnpaid(array $payload, array $order): void
    {
        $action = $this->decide($payload + ['status' => 'success'], $this->order($order));

        $this->assertFalse($action['complete']);
        $this->assertNull($action['cancel']);
        $this->assertCount(1, $action['notes']);
        $this->assertStringContainsString('not marked as paid', $action['notes'][0]);
        $this->assertSame('warning', $action['log'][0]);
    }

    public function notCovering(): array
    {
        $paid = ['fiat_amount' => 50.0, 'initial_fiat_amount' => 50.0, 'fiat_currency' => 'USD'];

        return [
            'another currency' => [['fiat_currency' => 'EUR'] + $paid, []],
            // A genuine webhook for a 1.00 invoice whose `extra` names this order.
            'a cheaper invoice' => [['fiat_amount' => 1.0, 'initial_fiat_amount' => 1.0] + $paid, []],
            'no fiat_currency' => [array_diff_key($paid, ['fiat_currency' => 0]), []],
            'no initial_fiat_amount' => [array_diff_key($paid, ['initial_fiat_amount' => 0]), []],
            'order in another currency' => [$paid, ['currency' => 'EUR']],
            'order total raised since' => [$paid, ['total' => '60.00']],
        ];
    }

    // ─── decide(): which order may be changed ───────────────────────────────

    /** @dataProvider notThisOrdersInvoice */
    public function testWebhookForAnotherInvoiceNeverChangesTheOrder(array $order, string $status, bool $noted): void
    {
        $action = $this->decide(['status' => $status, 'fiat_amount' => 50.0, 'fiat_currency' => 'USD'], $this->order($order));

        $this->assertFalse($action['complete']);
        $this->assertNull($action['cancel']);
        $this->assertSame('Ignored', $action['body']);
        $this->assertSame(200, $action['http']);
        // Money that arrived anyway stays visible to the merchant.
        $this->assertCount($noted ? 1 : 0, $action['notes']);
        if ($noted) {
            $this->assertStringContainsString('order not changed', $action['notes'][0]);
        }
    }

    public function notThisOrdersInvoice(): array
    {
        $older = ['meta' => ['_pawpayments_order_id' => 'po0']];
        $switched = ['method' => 'stripe'];

        return [
            'older invoice paid' => [$older, 'success', true],
            'older invoice paid over' => [$older, 'paid_over', true],
            'older invoice partly paid' => [$older, 'partially_paid', true],
            'older invoice failed' => [$older, 'failed', false],
            'older invoice cancelled' => [$older, 'cancelled', false],
            'switched to another gateway, paid' => [$switched, 'success', true],
            'switched to another gateway, failed' => [$switched, 'failed', false],
            'no payment method, paid' => [['method' => ''], 'success', true],
            'no invoice stored' => [['meta' => []], 'success', true],
        ];
    }

    public function testNoteForAnotherInvoiceNamesTheCurrentPaymentMethod(): void
    {
        $action = $this->decide(['status' => 'success', 'fiat_amount' => 50.0, 'fiat_currency' => 'USD'], $this->order(['method' => 'stripe']));

        $this->assertStringContainsString('(payment method: stripe)', $action['notes'][0]);
        $this->assertStringContainsString('50 USD received on invoice po1', $action['notes'][0]);
    }

    public function testEmptyInvoiceIdNeverMatchesAnOrderWithoutOne(): void
    {
        $action = WC_PawPayments_Webhook::decide(
            ['extra' => '7', 'order_id' => '', 'status' => 'success', 'fiat_amount' => 50.0, 'fiat_currency' => 'USD'],
            $this->order(['meta' => []]),
            'pawpayments'
        );

        $this->assertFalse($action['complete']);
        $this->assertSame('Ignored', $action['body']);
    }

    // ─── decide(): other statuses ───────────────────────────────────────────

    public function testPartialPaymentOnlyAddsANote(): void
    {
        $action = $this->decide(['status' => 'partially_paid', 'amount' => 10, 'asset' => 'usdt_tron', 'fiat_amount' => 10.0, 'fiat_currency' => 'USD']);

        $this->assertFalse($action['complete']);
        $this->assertNull($action['cancel']);
        $this->assertSame(['PawPayments: Partial payment 10 usdt_tron (order po1)'], $action['notes']);
    }

    /** @dataProvider cancellingWebhooks */
    public function testFailedOrUnpaidExpiredInvoiceCancelsTheOrder(array $payload): void
    {
        $action = $this->decide($payload);

        $this->assertFalse($action['complete']);
        $this->assertSame('PawPayments: Payment ' . $payload['status'], $action['cancel']);
        $this->assertSame([], $action['notes']);
    }

    public function cancellingWebhooks(): array
    {
        return [
            'expired, nothing paid' => [['status' => 'cancelled', 'fiat_amount' => 0, 'fiat_currency' => 'USD']],
            'expired, no amount' => [['status' => 'cancelled']],
            'failed' => [['status' => 'failed']],
            // Only an expired invoice leaves money with the merchant.
            'failed with an amount' => [['status' => 'failed', 'fiat_amount' => 20.0, 'fiat_currency' => 'USD']],
        ];
    }

    public function testExpiredAfterAPartialPaymentKeepsTheOrderForManualHandling(): void
    {
        $action = $this->decide(['status' => 'cancelled', 'fiat_amount' => 20.0, 'fiat_currency' => 'USD']);

        $this->assertFalse($action['complete']);
        $this->assertNull($action['cancel']);
        $this->assertCount(1, $action['notes']);
        $this->assertStringContainsString('partial payment of 20 USD', $action['notes'][0]);
        $this->assertStringContainsString('Funds credited, needs to be applied for the customer manually', $action['notes'][0]);
        $this->assertSame('warning', $action['log'][0]);
    }

    /** @dataProvider ignoredStatuses */
    public function testOtherStatusesOnlyLog(string $status): void
    {
        $action = $this->decide(['status' => $status, 'fiat_amount' => 50.0, 'fiat_currency' => 'USD']);

        $this->assertFalse($action['complete']);
        $this->assertNull($action['cancel']);
        $this->assertSame([], $action['notes']);
        $this->assertSame('warning', $action['log'][0]);
    }

    public function ignoredStatuses(): array
    {
        return [['high_risk'], ['confirming'], ['expired'], ['']];
    }
}
