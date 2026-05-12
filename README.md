# PawPayments for WooCommerce

Accept cryptocurrency payments in WooCommerce via PawPayments. Customers are
redirected to the PawPayments paywall to choose an asset and network; the
order is updated automatically once the on-chain payment confirms.

---

## Features

- **Checkout only** — no top-up/wallet feature (WooCommerce has no native
  credit balance).
- HPOS-compatible (High-Performance Order Storage); works on single-site and
  multisite installations.
- Automatic order status updates: `on-hold` → `processing` / `completed` on
  payment.
- Webhooks verified via `X-Paw-Signature` (HMAC-SHA256 of the raw body); requests without a valid header are rejected with HTTP 401.
- Idempotent: `payment_complete()` is natively idempotent in WooCommerce.
- Webhooks with `permanent_address_id` are silently acknowledged (200 OK).
- Currency / network selection happens on the PawPayments paywall — the
  plugin does not need to know about supported assets.

---

## Requirements

| Component   | Minimum version |
| ----------- | --------------- |
| WordPress   | 6.0             |
| WooCommerce | 7.0             |
| PHP         | 7.4 (8.0+ recommended) |
| PHP extensions | `curl`, `json` |

You must already have:

- A working WooCommerce store reachable over **HTTPS** (PawPayments requires
  TLS for webhook delivery).
- A PawPayments merchant account with an API key — obtain it from the
  [merchant dashboard](https://pawpayments.com).

---

## 1. Build / obtain the plugin zip

If you received `pawpayments-for-woocommerce-<version>.zip` directly, skip to
the next section.

To build from source:

```bash
cd plugins/woocommerce
zip -r pawpayments-for-woocommerce.zip pawpayments-for-woocommerce \
    -x "*/node_modules/*" "*/.git/*" "*/.DS_Store"
```

The archive must contain a single top-level folder
`pawpayments-for-woocommerce/` with `pawpayments-for-woocommerce.php` inside.

---

## 2. Install the plugin

### Option A — WordPress admin UI (recommended)

1. Log in to WordPress admin.
2. Go to **Plugins → Add New → Upload Plugin**.
3. Select `pawpayments-for-woocommerce.zip` and click **Install Now**.
4. Click **Activate Plugin**.

### Option B — WP-CLI

```bash
wp plugin install /path/to/pawpayments-for-woocommerce.zip --activate
```

### Option C — Manual upload (SFTP / shell)

1. Unzip `pawpayments-for-woocommerce.zip`.
2. Upload the resulting `pawpayments-for-woocommerce/` folder to
   `wp-content/plugins/`.
3. Set ownership and permissions:

   ```bash
   chown -R www-data:www-data wp-content/plugins/pawpayments-for-woocommerce
   find wp-content/plugins/pawpayments-for-woocommerce -type d -exec chmod 755 {} \;
   find wp-content/plugins/pawpayments-for-woocommerce -type f -exec chmod 644 {} \;
   ```

4. Activate the plugin via **Plugins** in WP admin or with
   `wp plugin activate pawpayments-for-woocommerce`.

---

## 3. Configure the gateway

1. Go to **WooCommerce → Settings → Payments**.
2. Find **PawPayments (Crypto)** in the list and click **Manage** (or toggle
   it on).
3. Fill the fields:

   | Field             | Description                                                  |
   | ----------------- | ------------------------------------------------------------ |
   | **Enable/Disable**| Tick to enable the gateway at checkout.                      |
   | **Title**         | Text shown to customers (e.g. *Pay with Crypto*).            |
   | **Description**   | Optional helper text shown under the title.                  |
   | **API Key**       | The API key from your PawPayments merchant dashboard.        |
   | **API Base URL**  | Leave the default `https://api.pawpayments.com`.              |
   | **Debug Log**     | Enable to log events to **WooCommerce → Status → Logs**.     |

4. Click **Save changes**.

---

## 4. Webhook URL

WooCommerce auto-registers the webhook handler at:

```
https://<your-store>/?wc-api=pawpayments
```

The plugin sends this URL to PawPayments as `notify_url` on each invoice
creation, so **no manual webhook setup is required** in the PawPayments
dashboard.

If your site uses pretty permalinks, both of these URLs work:

```
https://<your-store>/wc-api/pawpayments
https://<your-store>/?wc-api=pawpayments
```

---

## 5. Test the integration

1. Place a test order:
   - Add a product to the cart.
   - Checkout and select **Pay with Crypto**.
   - You should be redirected to a `https://paw.now/invoice#…` paywall page.
2. In **WooCommerce → Orders**, the order status should be **On hold** with a
   note *Awaiting crypto payment* and a meta `_pawpayments_order_id`.
3. Complete payment on the paywall using a small amount of any supported
   cryptocurrency.
4. Once the on-chain payment confirms, PawPayments delivers a webhook. The
   order status should change to **Processing** (or **Completed** for virtual
   products) with a note *PawPayments: Paid X USDT (order …)*.

### Sanity check via curl (simulating a webhook)

```bash
ORDER_ID="<paw_order_id_from_meta>"
EXTRA="<wc_order_id>"
KEY="<your_api_key>"
BODY="{\"order_id\":\"$ORDER_ID\",\"extra\":\"$EXTRA\",\"status\":\"success\",\"amount\":\"10\",\"asset\":\"USDT\"}"
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$KEY" | awk '{print $2}')
curl -X POST "https://<your-store>/?wc-api=pawpayments" \
  -H "Content-Type: application/json" \
  -H "X-Paw-Signature: $SIG" \
  -d "$BODY"
```

A successful response is `OK` with HTTP 200.

---

## 6. Troubleshooting

| Symptom | Cause / Fix |
| ------- | ----------- |
| Gateway not visible at checkout | Make sure it is enabled in **WooCommerce → Settings → Payments** and that the cart currency is supported by your PawPayments account. |
| `Payment error: Invalid API key` | Wrong API key in settings, or the merchant account has not been activated yet. |
| Order stays **On hold** after payment | Webhook not delivered. Check that the store is reachable from the public internet over HTTPS, and that no firewall blocks outbound connections from PawPayments. |
| Webhook returns HTTP 401 | Signature mismatch. Confirm the **API Key** in the settings matches the one used when the invoice was created. |
| No log entries | Enable **Debug Log** in the gateway settings and check **WooCommerce → Status → Logs → pawpayments**. |

---

## 7. Uninstall

1. **Plugins → Installed Plugins → PawPayments for WooCommerce → Deactivate**.
2. **Delete** the plugin.
3. Plugin settings remain in `wp_options.woocommerce_pawpayments_settings`. To
   remove them:

   ```bash
   wp option delete woocommerce_pawpayments_settings
   ```

The plugin does not create any custom database tables.
