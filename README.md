# Afriex Gateway for WooCommerce

A WordPress plugin that adds Afriex as a payment method in WooCommerce. Customers pay by bank transfer into an Afriex-generated account, and the order moves to a paid status automatically when Afriex confirms the deposit.

Merchant-facing documentation lives in [readme.txt](readme.txt), which is also what the WordPress.org plugin directory renders. This file is for people working on the plugin.

## Building

The block checkout bundle is **not** committed. Without it the gateway works on the classic shortcode checkout but is invisible on the Cart & Checkout blocks, so a release ZIP must be built before it ships.

```bash
npm install
npm run build      # writes assets/js/build/
npm run start      # watch mode
```

`webpack.config.js` swaps the default dependency-extraction plugin for WooCommerce's. That is what makes `@woocommerce/blocks-registry` and `@woocommerce/settings` resolve to the `wc.*` globals WooCommerce prints on the page, rather than being bundled — they are intentionally not installed as dependencies.

## Releasing

1. Set the same version in the plugin header `Version`, `AFRIEX_WC_VERSION` and readme.txt `Stable tag`. WordPress.org rejects a mismatch, and a suffix such as `-dev`.
2. Run `npm run build`, so the ZIP carries a bundle built from the current source.
3. Build the ZIP with `wp dist-archive . --plugin-dirname=afriex-gateway-for-woocommerce`, which honours [.distignore](.distignore). The folder inside the ZIP must be `afriex-gateway-for-woocommerce/`, to match the main file and the text domain.
4. Run [Plugin Check](https://wordpress.org/plugins/plugin-check/) against the installed ZIP and clear every error.

## Linting

```bash
npm run lint:js
composer install && composer lint    # PHPCS, WordPress coding standards
```

CI runs both, plus `php -l` across PHP 7.4 through 8.3, and fails if the block bundle does not build.

## Testing

A disposable WordPress + WooCommerce site in Docker, with a locally generated keypair so webhook signatures are verified for real rather than stubbed:

```bash
npm run env:start      # Docker must be running; first run pulls images
npm run env:setup      # keypair, seeded store, gateway enabled
npm run webhook:send -- --payment-method-id pm_test_1 --status COMPLETED --amount 49.99
```

[tests/README.md](tests/README.md) maps each of the architecture document's nine tests to a command. Nothing under `tests/` ships in the release package.

## Layout

| Path | Role |
|---|---|
| `afriex-gateway-for-woocommerce.php` | Header, bootstrap, HPOS + blocks compatibility declarations |
| `includes/abstract-afriex-payment-gateway.php` | Base for the payment methods: the shared account section, setup state, notices |
| `includes/class-afriex-gateway.php` | `WC_Payment_Gateway` subclass — Bank Transfer: classic checkout, settings, payment instructions |
| `includes/class-afriex-blocks-support.php` | `AbstractPaymentMethodType` subclass — block checkout registration |
| `includes/class-afriex-account-settings.php` | The one Afriex account both methods share: key, environment, webhook keys |
| `includes/class-afriex-api-client.php` | Thin REST client over `wp_remote_*`, one per environment |
| `includes/class-afriex-webhook-handler.php` | REST route, RSA-SHA256 verification, the event claim, dispatch |
| `includes/class-afriex-settlement.php` | The one place an Afriex transaction changes an order |
| `includes/class-afriex-settlement-decision.php` | The decision table, with no WordPress in it |
| `includes/class-afriex-scheduler.php` | The `afriex_wc_sweep` Action Scheduler action and the cancellation rule |
| `includes/class-afriex-ledger.php` | The plugin table: claims, locks and throttles |
| `includes/class-afriex-migration.php` | The 1.0 to 1.1 upgrade, under a version check |
| `includes/class-afriex-order-meta.php` | HPOS-safe order meta access and the exact look-ups |
| `includes/class-afriex-status-mapper.php` | Afriex's status enum, sorted into four classes |
| `includes/class-afriex-money.php`, `includes/class-afriex-phone.php` | Minor-unit money and E.164 phone numbers |
| `includes/class-afriex-logger.php` | `WC_Logger` wrapper with redaction |

## Things worth knowing before changing this code

**There is no Afriex PHP SDK.** `@afriex/sdk` is Node/TypeScript; WooCommerce is PHP. Every call goes directly against the REST API through the WordPress HTTP API, and webhook signatures are verified with `openssl_verify()`. Bundling Guzzle is not an option — WordPress.org review scrutinises bundled dependencies. `Afriex_Api_Client` is deliberately the only class that knows about HTTP, so a future PHP SDK can replace it without touching anything else.

**Both checkouts, one processing path.** The JS module's `name` routes back to `Afriex_Gateway::process_payment()`. The blocks class reads the classic gateway's settings and availability rather than duplicating them, so the two checkouts cannot drift.

**Orders start at `on-hold`, and that has a trap.** `WC_Order::needs_payment()` returns true only for `pending` and `failed`. An on-hold order reports `false` from creation, so any expiry or cancellation logic gated behind it never runs — the failure mode that left Mollie's bank-transfer orders stranded indefinitely. Expiry here is decided from Afriex transaction status and the account's own expiry window, never inferred from `needs_payment()`, and the `afriex_wc_sweep` Action Scheduler action in `Afriex_Scheduler` is the backstop for a webhook that never arrives.

**Order meta only through the CRUD API.** `$order->get_meta()` / `$order->update_meta_data()`, never `get_post_meta()`. `wc_get_orders()` for queries, never `WP_Query` against `shop_order`. `Afriex_Order_Meta` is the one place this has to hold.

**Verification is the permission check.** It runs in the route's `permission_callback`, so a forged request is rejected before any order lookup or write happens.

**Amount mismatches are never auto-completed.** An underpayment silently fulfilled is a loss; an overpayment silently kept is a dispute. Both get a note and a human.
