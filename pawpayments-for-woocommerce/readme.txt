=== PawPayments for WooCommerce ===
Contributors: pawpayments
Tags: cryptocurrency, payment, bitcoin, ethereum, usdt, crypto
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 9.0
Stable tag: 2.0.0
License: MIT

Accept cryptocurrency payments in your WooCommerce store via PawPayments.

== Description ==

PawPayments for WooCommerce allows you to accept cryptocurrency payments including Bitcoin, Ethereum, USDT, and many more.

Features:
* Easy setup — just add your API key
* Customer chooses cryptocurrency on the PawPayments paywall
* Automatic order status updates via webhooks
* Support for 23 fiat currencies (USD, EUR, GBP, CAD, AUD, CHF, JPY, NZD, SGD, HKD, NGN, KRW, ILS, RON, ARS, INR, IDR, MXN, MYR, TRY, PLN, BRL, THB) — other shop currencies auto-fallback to USD
* Idempotent webhook processing

== Installation ==

1. Upload the `pawpayments-for-woocommerce` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce → Settings → Payments → PawPayments
4. Enter your API key from the PawPayments merchant dashboard
5. Save changes

Note: This plugin does not support permanent deposit addresses. For topup/wallet functionality, integrate directly with the PawPayments API.

== Changelog ==

= 2.0.0 =
* Complete rewrite for PawPayments API v2
* Uses shared PHP SDK
* Guard against permanent address webhooks
* HPOS compatibility
