# Changelog

## [Unreleased]

## [1.0.3] - 2026-10-27

- fix(drupal): preserve reproducibility without hiding ZIP upgrade changes
- chore: rebuild canonical CMS package

- Replaced fixed 1980 package timestamps with reproducible source timestamps so Drupal can discover updated YAML definitions after a cache rebuild.

- Added a buyer return bridge for Drupal Commerce, with static success and failure URLs to copy into the existing Paymos project. Payment verification through webhook/API checks and native Commerce payments remains unchanged.

- Add the alpha Drupal Commerce offsite gateway. Connecting requires a matching Paymos backend with Drupal Commerce registered in its CMS catalog.
- Add project approval, Sandbox/Live gateways, and encrypted credentials.
- Verify signed notifications and invoice details through the API; prevent duplicate payments.
- Check unresolved invoices through cron; block uninstall until resolved.
- Keep Commerce payment records after uninstall. Fulfillment remains with Commerce; no automatic refunds.
- GitHub package version `v1.0.0` does not establish production readiness.

## [1.0.2] - 2026-10-27

- fix(drupal): restore native buyer return using existing CMS bridge pattern

- Added a buyer return bridge for Drupal Commerce, with static success and failure URLs to copy into the existing Paymos project. Payment verification through webhook/API checks and native Commerce payments remains unchanged.

- Add the alpha Drupal Commerce offsite gateway. Connecting requires a matching Paymos backend with Drupal Commerce registered in its CMS catalog.
- Add project approval, Sandbox/Live gateways, and encrypted credentials.
- Verify signed notifications and invoice details through the API; prevent duplicate payments.
- Check unresolved invoices through cron; block uninstall until resolved.
- Keep Commerce payment records after uninstall. Fulfillment remains with Commerce; no automatic refunds.
- GitHub package version `v1.0.0` does not establish production readiness.

## [1.0.1] - 2026-10-27

- fix(drupal): show owner-bound Connect approval code and record live checks
- docs(drupal): record verified GitHub and Drupal.org alpha publication

- Add the alpha Drupal Commerce offsite gateway. Connecting requires a matching Paymos backend with Drupal Commerce registered in its CMS catalog.
- Add project approval, Sandbox/Live gateways, and encrypted credentials.
- Verify signed notifications and invoice details through the API; prevent duplicate payments.
- Check unresolved invoices through cron; block uninstall until resolved.
- Keep Commerce payment records after uninstall. Fulfillment remains with Commerce; no automatic refunds.
- GitHub package version `v1.0.0` does not establish production readiness.

## [1.0.0] - 2026-10-26

- fix(drupal): describe alpha rollout requirements before public release

- Add the alpha Drupal Commerce offsite gateway. Connecting requires a matching Paymos backend with Drupal Commerce registered in its CMS catalog.
- Add project approval, Sandbox/Live gateways, and encrypted credentials.
- Verify signed notifications and invoice details through the API; prevent duplicate payments.
- Check unresolved invoices through cron; block uninstall until resolved.
- Keep Commerce payment records after uninstall. Fulfillment remains with Commerce; no automatic refunds.
- GitHub package version `v1.0.0` does not establish production readiness.

