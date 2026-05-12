<?php

if (!defined('ABSPATH')) exit;

class WC_PawPayments_Webhook
{
    public static function handle()
    {
        $rawBody = file_get_contents('php://input');
        if (!$rawBody) {
            status_header(400);
            exit('Empty body');
        }

        $gateway = new WC_Gateway_PawPayments();
        $apiKey = $gateway->get_option('api_key');

        $headerSig = $_SERVER['HTTP_X_PAW_SIGNATURE'] ?? '';
        if (!$headerSig || !\PawPayments\Sdk\Webhook::verifyRawBody($rawBody, $headerSig, $apiKey)) {
            status_header(401);
            exit('Invalid signature');
        }

        $payload = \PawPayments\Sdk\Webhook::parsePayload($rawBody);

        if (!empty($payload['permanent_address_id'])) {
            status_header(200);
            exit;
        }

        $orderId = $payload['extra'] ?? '';
        $pawOrderId = $payload['order_id'] ?? '';
        $status = $payload['status'] ?? '';
        $amount = $payload['amount'] ?? 0;
        $asset = $payload['asset'] ?? '';

        if (!$orderId) {
            status_header(400);
            exit('Missing extra');
        }

        $order = wc_get_order((int) $orderId);
        if (!$order) {
            status_header(404);
            exit('Order not found');
        }

        $logger = wc_get_logger();

        switch ($status) {
            case 'success':
            case 'paid_over':
                $order->payment_complete($pawOrderId);
                $order->add_order_note(
                    sprintf('PawPayments: Paid %s %s (order %s)', $amount, $asset, $pawOrderId)
                );
                $logger->info("Order #{$orderId} paid via PawPayments ({$pawOrderId})", ['source' => 'pawpayments']);
                break;

            case 'partially_paid':
                $order->add_order_note(
                    sprintf('PawPayments: Partial payment %s %s (order %s)', $amount, $asset, $pawOrderId)
                );
                $logger->info("Order #{$orderId} partial payment ({$pawOrderId})", ['source' => 'pawpayments']);
                break;

            case 'cancelled':
            case 'failed':
                $order->update_status('cancelled', 'PawPayments: Payment ' . $status);
                $logger->info("Order #{$orderId} {$status} ({$pawOrderId})", ['source' => 'pawpayments']);
                break;

            default:
                $logger->warning("Order #{$orderId} unknown status: {$status}", ['source' => 'pawpayments']);
                break;
        }

        status_header(200);
        exit('OK');
    }
}
