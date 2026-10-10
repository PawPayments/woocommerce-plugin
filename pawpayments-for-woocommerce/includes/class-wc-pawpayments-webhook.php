<?php

if (!defined('ABSPATH')) exit;

/**
 * The webhook endpoint. handle() only does the I/O; what a webhook means for
 * an order is decided by verify() and decide(), which take everything they
 * need as arguments so they can be tested without WordPress (see
 * woocommerce/tests).
 */
class WC_PawPayments_Webhook
{
    public static function handle()
    {
        $gateway = new WC_Gateway_PawPayments();

        [$payload, $httpCode, $body] = self::verify(
            (string) $gateway->get_option('api_key'),
            (string) file_get_contents('php://input'),
            (string) ($_SERVER['HTTP_X_PAW_SIGNATURE'] ?? '')
        );
        if ($payload === null) {
            self::respond($httpCode, $body);
        }

        $orderId = $payload['extra'] ?? '';
        $pawOrderId = (string) ($payload['order_id'] ?? '');
        if (!$orderId || $pawOrderId === '') {
            self::respond(400, 'Missing extra or order_id');
        }

        $order = wc_get_order((int) $orderId);
        if (!$order) {
            self::respond(404, 'Order not found');
        }

        $action = self::decide($payload, $order, $gateway->id);
        if ($action['complete']) {
            $order->payment_complete($pawOrderId);
        }
        foreach ($action['notes'] as $note) {
            $order->add_order_note($note);
        }
        if ($action['cancel'] !== null) {
            $order->update_status('cancelled', $action['cancel']);
        }
        if ($action['log'] !== null) {
            wc_get_logger()->log($action['log'][0], $action['log'][1], ['source' => 'pawpayments']);
        }

        self::respond($action['http'], $action['body']);
    }

    /**
     * [$payload, 0, ''] for a signed webhook to act on, else [null, $code,
     * $body] to answer with: 503 while the API key is empty (an empty HMAC key
     * makes any signature computable), 400/401 for anything that is not a
     * PawPayments webhook, 200 with no action for permanent-address deposits.
     *
     * @return array{0: ?array, 1: int, 2: string}
     */
    public static function verify(string $apiKey, string $rawBody, string $signature): array
    {
        if ($apiKey === '') {
            return [null, 503, 'Gateway not configured'];
        }
        if ($rawBody === '') {
            return [null, 400, 'Empty body'];
        }
        if (!\PawPayments\Sdk\Webhook::verifyRawBody($rawBody, $signature, $apiKey)) {
            return [null, 401, 'Invalid signature'];
        }

        $payload = \PawPayments\Sdk\Webhook::parsePayload($rawBody);
        if (!empty($payload['permanent_address_id'])) {
            return [null, 200, ''];
        }

        return [$payload, 0, ''];
    }

    /**
     * What a verified webhook does to $order: `complete` means
     * payment_complete(), `cancel` is the note to cancel it with (or null),
     * `notes` are order notes to add, `log` is [level, message] (or null).
     *
     * @param WC_Order $order The order named in the webhook's `extra`
     * @param string $gatewayId The id of this gateway
     * @return array{complete: bool, cancel: ?string, notes: string[], log: ?array, http: int, body: string}
     */
    public static function decide(array $payload, $order, string $gatewayId): array
    {
        $orderId = (string) ($payload['extra'] ?? '');
        $pawOrderId = (string) ($payload['order_id'] ?? '');
        $status = (string) ($payload['status'] ?? '');
        $amount = $payload['amount'] ?? 0;
        $asset = $payload['asset'] ?? '';
        $none = ['complete' => false, 'cancel' => null, 'notes' => [], 'log' => null, 'http' => 200, 'body' => 'OK'];

        // `extra` alone is just a number: only orders paid with this gateway, and
        // only the invoice process_payment() stored for them, may change the order.
        // An empty invoice id must not match an order that has none stored.
        if ($order->get_payment_method() !== $gatewayId
            || $pawOrderId === ''
            || (string) $order->get_meta('_pawpayments_order_id') !== $pawOrderId
        ) {
            $action = [
                'body' => 'Ignored',
                'log' => ['warning', "Order #{$orderId}: ignoring PawPayments webhook for {$pawOrderId} ({$status}), not this order's PawPayments invoice"],
            ] + $none;
            // Money sent to an older invoice of this order, or to its PawPayments
            // invoice after the customer switched to another payment method, must
            // stay visible to the merchant.
            if (in_array($status, ['success', 'paid_over', 'partially_paid'], true)) {
                $action['notes'][] = sprintf(
                    'PawPayments: %s, %s %s received on invoice %s, which is not this order\'s current PawPayments invoice (payment method: %s); order not changed',
                    $status,
                    $payload['fiat_amount'] ?? '?',
                    $payload['fiat_currency'] ?? '',
                    $pawOrderId,
                    $order->get_payment_method() ?: 'none'
                );
            }
            return $action;
        }

        switch ($status) {
            case 'success':
            case 'paid_over':
                if (!\PawPayments\Sdk\Webhook::coversAmount($payload, (string) $order->get_currency(), $order->get_total())) {
                    return [
                        'notes' => [sprintf(
                            'PawPayments: %s %s received does not cover the order total %s %s (order %s); not marked as paid',
                            $payload['fiat_amount'] ?? '?',
                            $payload['fiat_currency'] ?? '?',
                            $order->get_total(),
                            $order->get_currency(),
                            $pawOrderId
                        )],
                        'log' => ['warning', "Order #{$orderId} PawPayments amount/currency mismatch ({$pawOrderId})"],
                    ] + $none;
                }
                return [
                    'complete' => true,
                    'notes' => [sprintf('PawPayments: Paid %s %s (order %s)', $amount, $asset, $pawOrderId)],
                    'log' => ['info', "Order #{$orderId} paid via PawPayments ({$pawOrderId})"],
                ] + $none;

            case 'partially_paid':
                return [
                    'notes' => [sprintf('PawPayments: Partial payment %s %s (order %s)', $amount, $asset, $pawOrderId)],
                    'log' => ['info', "Order #{$orderId} partial payment ({$pawOrderId})"],
                ] + $none;

            case 'cancelled':
            case 'failed':
                // `cancelled` after a partial payment is an invoice that expired
                // with money on it, which the merchant keeps. WooCommerce has no
                // customer balance to put it on, so the order is left open with a
                // note for the merchant to settle by hand, never cancelled (which
                // would restock it and bury the payment among cancelled orders).
                if ($status === 'cancelled' && (float) ($payload['fiat_amount'] ?? 0) > 0) {
                    return [
                        'notes' => [sprintf(
                            'PawPayments: invoice %s expired after a partial payment of %s %s. Funds credited, needs to be applied for the customer manually (e.g. refund it, or complete the order once the rest is paid); order not cancelled',
                            $pawOrderId,
                            $payload['fiat_amount'],
                            $payload['fiat_currency'] ?? ''
                        )],
                        'log' => ['warning', "Order #{$orderId} invoice {$pawOrderId} expired after a partial payment; funds need manual handling"],
                    ] + $none;
                }
                return [
                    'cancel' => 'PawPayments: Payment ' . $status,
                    'log' => ['info', "Order #{$orderId} {$status} ({$pawOrderId})"],
                ] + $none;
        }

        return ['log' => ['warning', "Order #{$orderId} unknown status: {$status}"]] + $none;
    }

    private static function respond(int $code, string $body)
    {
        status_header($code);
        exit($body);
    }
}
