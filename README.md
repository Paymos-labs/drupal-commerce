# Paymos for Drupal Commerce

Accept payments through Paymos in Drupal Commerce. Version `1.0.0` is the first stable release, available through [Drupal.org](https://www.drupal.org/project/commerce_paymos) and the [official GitHub repository](https://github.com/Paymos-labs/drupal-commerce).

## Requirements

Use Drupal `^10.3 || ^11` with Drupal Commerce `^3`. The module declares PHP `^8.1`; your Drupal version may require a newer PHP version, so follow the stricter requirement. Your store needs a public HTTPS address for the connection and payment notifications.

The module uses the GPL-2.0-or-later license. Its PHP SDK dependency uses the MIT license and accepts compatible 1.x versions from 1.9.1 (`^1.9.1`).

## Install the module

Use Composer to install the stable release and resolve its SDK dependency:

```sh
composer require 'drupal/commerce_paymos:^1.0'
```

You can also install the manual ZIP package. Extract its `commerce_paymos` directory into your site's contributed modules directory. The manual package includes the PHP SDK, so keep the bundled files together. Enable the Paymos module in Drupal after installing it.

After updating the module, rebuild Drupal’s cache: `drush --uri=https://your-store.example cache:rebuild`.

## Connect your store

Open `/admin/commerce/config/payment/paymos` in Drupal. In the Paymos dashboard, select the project you want to use for this store. Choose **Connect Paymos** in Drupal, approve the connection, and return to Drupal to choose **Check approval**.

Then open `/admin/commerce/config/payment-gateways/add`. Create a payment gateway, select Paymos, and choose Sandbox or Live for the environment you intend to use. The connection and the Commerce payment gateway are separate steps; finish both before using the gateway at checkout.

Copy the return addresses shown in Drupal admin into your existing Paymos project: `/paymos/return` for Success URL and `/paymos/return?cancel=1` for Fail URL. Do this once. The hosted checkout uses its Back to store link. Drupal keeps the latest checkout’s native return destinations in the buyer’s browser session for one hour. Another browser, expired session data or a changed order/gateway binding sends the buyer to the store home page. A browser return never confirms payment; verified webhook/API checks and the native Commerce payment determine payment status. Invoice API fields remain unchanged.

Credentials stay in encrypted Drupal state. Encryption uses the Drupal private key and `hash_salt`, and secrets do not appear in configuration exports. If your store's canonical URL changes, reconnect the store to Paymos.

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
