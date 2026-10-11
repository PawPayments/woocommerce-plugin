=== PawPayments for WooCommerce ===
Contributors: pawpayments
Tags: cryptocurrency, payment, bitcoin, ethereum, usdt, crypto
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 11.2
Stable tag: 2.1.1
License: MIT

Accept cryptocurrency payments in your WooCommerce store via PawPayments.

== Description ==

PawPayments for WooCommerce allows you to accept cryptocurrency payments including Bitcoin, Ethereum, USDT, and many more.

Features:
* Easy setup — just add your API key
* Customer chooses cryptocurrency on the PawPayments paywall
* Automatic order status updates via webhooks
* Support for 23 fiat currencies (USD, EUR, GBP, CAD, AUD, CHF, JPY, NZD, SGD, HKD, NGN, KRW, ILS, RON, ARS, INR, IDR, MXN, MYR, TRY, PLN, BRL, THB) — the gateway is hidden for any other shop currency
* Works with the Checkout block (default since WooCommerce 8.3) and the classic checkout
* Idempotent webhook processing

== Installation ==

1. Upload the `pawpayments-for-woocommerce` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce → Settings → Payments → PawPayments
4. Enter your API key from the PawPayments merchant dashboard
5. Save changes

Note: This plugin does not support permanent deposit addresses. For topup/wallet functionality, integrate directly with the PawPayments API.

== Changelog ==

= 2.1.1 =
* Works with the Checkout block, the default checkout of new stores since WooCommerce 8.3 (the method was not offered there before)
* An order is completed only by a signed webhook for its own PawPayments invoice and payment method, and only when that invoice was issued for the order total in the order's currency; money on any other invoice becomes an order note
* An invoice that expires after a partial payment keeps the order open with a note to apply the funds by hand, instead of cancelling it
* The gateway is hidden, and refuses to pay, when the store or order currency is not supported; a total is never re-labelled as USD
* A repeated webhook adds no second note
* Bundled PawPayments PHP SDK 2.1.1 (empty keys and malformed signatures refused)

= 2.0.1 =
* Updated bundled PawPayments PHP SDK (full API surface, payout `fee_bearer`)

= 2.0.0 =
* Complete rewrite for PawPayments API v2
* Uses shared PHP SDK
* Guard against permanent address webhooks
* HPOS compatibility
