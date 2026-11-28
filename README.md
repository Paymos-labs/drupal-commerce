# Paymos for Drupal Commerce

**English** | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Accept payments through Paymos in Drupal Commerce. Version `1.0.0` is the first stable release, available through [Drupal.org](https://www.drupal.org/project/commerce_paymos) and the [official GitHub repository](https://github.com/Paymos-labs/drupal-commerce).

[![Watch: 12 Official CMS Crypto Plugins — 1-Click Connect (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)
> **Video walkthrough (1:12, subtitles in 6 languages):** [Watch on YouTube](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Requirements

Use Drupal `^10.3 || ^11` with Drupal Commerce `^3`. The module declares PHP `^8.1`; your Drupal version may require a newer PHP version, so follow the stricter requirement. Your store needs a public HTTPS address for the connection and payment notifications.

The module uses the GPL-2.0-or-later license. Its PHP SDK dependency (`paymos/php-sdk`) uses the MIT license and requires version `^1.12.0` (`1.12.0` or newer compatible `1.x` release) for checkout `products[]` normalization via `Paymos\Plugin\InvoicePresentation::normalizeProducts`.

## Install the module

Use Composer to install the stable release and resolve `paymos/php-sdk:^1.12.0`:

```sh
composer require 'drupal/commerce_paymos:^1.0'
```

You can also install the separate manual ZIP package. Extract its `commerce_paymos` directory into your site's contributed modules directory. The manual package bundles `paymos/php-sdk` `1.11.0` inside `vendor/` (`SdkBootstrap` loads it when `Paymos\Client` is not already autoloaded), so keep the bundled files together. Enable the Paymos module in Drupal after installing it.

If a site uses the manual ZIP alongside an older `paymos/php-sdk` already loaded globally by another Composer package, `CheckoutService` checks `method_exists(InvoicePresentation::class, 'normalizeProducts')` and safely falls back to an `amount-only` invoice payload rather than failing checkout.

After updating the module, rebuild Drupal’s cache: `drush --uri=https://your-store.example cache:rebuild`.

## Connect your store

Open `/admin/commerce/config/payment/paymos` in Drupal. In the Paymos dashboard, select the project you want to use for this store. Choose **Connect Paymos** in Drupal, approve the connection, and return to Drupal to choose **Check approval**.

Then open `/admin/commerce/config/payment-gateways/add`. Create a payment gateway, select Paymos, and choose Sandbox or Live for the environment you intend to use. The connection and the Commerce payment gateway are separate steps; finish both before using the gateway at checkout.

Copy the return addresses shown in Drupal admin into your existing Paymos project: `/paymos/return` for Success URL and `/paymos/return?cancel=1` for Fail URL. Do this once. The hosted checkout uses its Back to store link. Drupal keeps the latest checkout’s native return destinations in the buyer’s browser session for one hour. Another browser, expired session data or a changed order/gateway binding sends the buyer to the store home page. A browser return never confirms payment; verified webhook/API checks and the native Commerce payment determine payment status. Invoice requests send only the order reference, `project_id`, `amount`, `currency`, and optional normalized `products[]` without buyer identity fields.

Credentials stay in encrypted Drupal state. Encryption uses the Drupal private key and `hash_salt`, and secrets do not appear in configuration exports. If your store's canonical URL changes, reconnect the store to Paymos.

## Order line items and catalog boundary

When `Paymos\Plugin\InvoicePresentation::normalizeProducts` is available, `CheckoutService` filters out shipping, discount, tax, fee, and note rows and normalizes `1..50` order line items (`title`, positive integer `quantity`, decimal string `unit_amount`, plus optional plain-text `description`) into `products[]` using an all-or-nothing rule: if any product row is invalid or the cart exceeds `50` items, Drupal sends an `amount-only` invoice with the native order total (`amount`) instead of a truncated cart. Blank descriptions are omitted per item without dropping the item. The normalized `products[]` snapshot is persisted in the durable `InvoiceStore` intent so interrupted-create retries reuse its frozen values instead of reading a changed order. For historical snapshots, retries remove only the retired `image_url` key from the outgoing copy, preserving every other value and leaving the stored snapshot unchanged.

Across the 12-plugin Paymos CMS catalog (`plugins/cms-store-catalog.json`):

- All **11** commodity CMS plugins transmit `products[]`: the **10** PHP plugins built on `Paymos\Plugin\InvoicePresentation::normalizeProducts` (`WooCommerce`, `Easy Digital Downloads`, `Drupal Commerce` on `paymos/php-sdk:^1.12.0`, `OpenCart`, `PrestaShop`, `WHMCS`, `CS-Cart`, `Magento 2`, `Shopware 6`, and `HikaShop` `1.1.0` on `^1.11.0`) plus **Odoo** (`20.0.1.1.0`, sending `products` over the bundled Paymos Python SDK `1.x` (`>= 1.6.0`) via `client.request("POST", "/v1/invoices", payload)`).
- Only the donation gateway **`givewp-paymos`** remains `amount-only`, because donations have no product cart.

## Keep payment checks running

Schedule Drupal cron with your store's canonical HTTPS URL. For example:

```sh
drush --uri=https://your-store.example cron
```

Cron checks unresolved payments in bounded batches. Keep it running even when payment notifications arrive normally, so unresolved payments continue to receive checks.

## How orders receive payment

Payment notifications carry a signature and trigger an API check. Before a payment is recorded, the invoice must match the order, Paymos project, environment, amount, and currency. A native SQL transaction records one completed Commerce payment; duplicate notifications do not record it again.

Partial or late payments do not release goods automatically. A payment with mismatched details needs review. Drupal Commerce controls fulfillment through your store's own order workflow, so review that workflow when setting up the gateway.

## Refunds

The module supports native refunds through the Paymos Merchant API (`/v1/refunds`) in two server-calculated modes: `full` (full invoice refund) and `overpayment` (refund of the excess on a `paid_over` invoice). Paymos returns funds as a new outbound blockchain transfer strictly to the verified `first_sender` address recorded on the paid invoice — no recipient wallet address is entered manually, and arbitrary partial refund amounts are not supported.

Authorized administrators can submit and monitor refunds from two protected entry points in Drupal:

- the Commerce payment **Refund** operation form (`RefundForm`) on the order's **Payments** tab, which lets you select **Full refund** (`full`) or **Refund overpayment** (`overpayment`);
- the **Paymos refunds** panel on `/admin/commerce/config/payment/paymos` (`ConnectForm`), which supports starting `full` and `overpayment` refunds for eligible Paymos payments and lists operation states and external refund observations.

Signed webhook notifications at `/paymos/webhook` and Drupal cron reconciliation update pending refund operations and apply the native Commerce payment refund state once Paymos confirms completion (`completed`). In Sandbox mode, terminal refund outcomes (`completed`, `failed`, `cancelled`) can be simulated for testing.

## Remove the module

Disconnecting Paymos on `/admin/commerce/config/payment/paymos` is blocked while refund history records exist for the store. Uninstalling the module is blocked while refund records or unresolved pending Paymos payments exist in the database (completed invoices and webhook event rows do not block disconnect or uninstall). When no pending payments or refund records block removal, uninstalling deletes the encrypted credentials owned by the module and keeps native Drupal Commerce payment records.

## More information

Visit [Paymos](https://paymos.io) for the payment service. See [Paymos documentation](https://paymos.io/docs) for more about the payment service.
