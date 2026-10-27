# Drupal.org publication record

- Project: https://www.drupal.org/project/commerce_paymos
- Release: https://www.drupal.org/project/commerce_paymos/releases/1.0.0-alpha1
- Source: https://git.drupalcode.org/project/commerce_paymos
- Branch: `1.0.x`; tag: `1.0.0-alpha1`; source commit: `41e128e`.
- Composer identity: `drupal/commerce_paymos`.
- GitHub manual package: https://github.com/Paymos-labs/drupal-commerce/releases/tag/v1.0.0

Checked on 2026-10-26. Drupal.org generated ZIP and tar.gz; its public Composer
metadata advertises `1.0.0-alpha1`, Drupal `^10.3 || ^11`, Commerce `^3.0`,
PHP `^8.1`, and SDK `^1.6.1`. Composer installs the SDK dependency. The separate
GitHub manual ZIP bundles it.

This is an alpha integration without Drupal security advisory coverage.
Connecting requires a matching Paymos backend with Drupal Commerce registered.
Publishing these files does not deploy that backend. Native validation with mock
payment transport does not establish production end-to-end readiness.

Detailed verification and limits are recorded in the source monorepo change
`openspec/changes/drupal-commerce-publication/verification.md`.
