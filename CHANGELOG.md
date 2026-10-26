# Changelog

## [Unreleased]

## [1.0.0] - 2026-10-26

- fix(drupal): describe alpha rollout requirements before public release

- Add the alpha Drupal Commerce offsite gateway. Connecting requires a matching Paymos backend with Drupal Commerce registered in its CMS catalog.
- Add project approval, Sandbox/Live gateways, and encrypted credentials.
- Verify signed notifications and invoice details through the API; prevent duplicate payments.
- Check unresolved invoices through cron; block uninstall until resolved.
- Keep Commerce payment records after uninstall. Fulfillment remains with Commerce; no automatic refunds.
- GitHub package version `v1.0.0` does not establish production readiness.

