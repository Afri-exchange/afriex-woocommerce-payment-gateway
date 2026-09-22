# Testing

The architecture document specifies nine tests. This is how to run them.

Everything here is local and disposable: a WordPress + WooCommerce site in Docker via [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env), with a signing keypair generated on your machine so the real `openssl_verify()` path is exercised rather than stubbed.

Nothing in this directory ships in the release package — see [`.distignore`](../.distignore).

## Setup

Docker must be running.

```bash
npm install
npm run build          # the block bundle, or block checkout has nothing to load
npm run env:start      # first run pulls images; a few minutes
npm run env:activate   # wp-env does not always activate on first start
npm run env:setup      # generates a keypair, seeds the store and the gateway
```

`env:setup` enables the gateway, loads the generated public key into its settings, sets the store to NGN/Nigeria, and creates a 49.99 test product.

| | |
|---|---|
| Store | http://localhost:8888 |
| Admin | http://localhost:8888/wp-admin (`admin` / `password` on a fresh environment; localhost only, see the tunnel section) |
| Gateway settings | WooCommerce → Settings → Payments → Afriex |
| Webhook endpoint | http://localhost:8888/wp-json/afriex/v1/webhook |

Useful while working:

```bash
npm run env:logs                    # PHP error log
npm run env:cli -- wp plugin list   # anything WP-CLI can do
npm run env:stop
npm run env:destroy                 # wipe and start over
```

Plugin logs land in WooCommerce → Status → Logs under the `afriex` source. `env:setup` turns logging on.

Every `wp-env run` call takes roughly 45 seconds on a Windows-drive checkout — that is container startup over a slow bind mount. From a checkout inside WSL2's own filesystem it is a second or two.

> **Keep WooCommerce first in the `plugins` list in `.wp-env.json`.** wp-env activates plugins in list order, and this plugin's `Requires Plugins: woocommerce` header makes WordPress refuse to activate it before WooCommerce. When that happens wp-env treats the start as failed and **destroys the containers it just created** — you are left with nothing running and no obvious error beyond "Error while running docker compose command".

> The `env:order` and `env:show` scripts hard-code the container path `wp-content/plugins/afriex-woocommerce-payment-gateway/`. wp-env mounts the project under **its folder name**, not the plugin slug, so adjust those two scripts if you rename the checkout directory. `env:setup` derives the path on its own.

## Exposing the site to Afriex

Real Afriex webhooks cannot reach `localhost`, so closing tests 1, 2 and 9 means putting the site behind a tunnel (Cloudflare, ngrok, whatever you use). Point the tunnel at `http://localhost:8888`.

**Through a tunnel, only the webhook route answers. Everything else returns 404.** Use wp-admin at `http://localhost:8888` only.

This is deliberate. An open tunnel would publish the whole dev site: a well-known admin login, WordPress admin (which amounts to running PHP) over a **read-write mount of this repo**, and the test signing key and `.git`, which Apache serves as plain files. Two layers enforce it, and `npm run env:setup` applies both:

- [`tests/tools/tunnel-lockdown.mjs`](tools/tunnel-lockdown.mjs) writes a rewrite rule at the top of the site's `.htaccess`. It covers static files, which never reach PHP. The file belongs to wp-env's copy of WordPress, outside this repo, and `wp-env destroy` recreates it, which is why setup re-applies it every time. `npm run env:lockdown` applies it on its own.
- [`tests/mu-plugins/00-tunnel.php`](mu-plugins/00-tunnel.php) returns the same 404 from PHP, as a backstop if the `.htaccess` rule is ever lost.

Requests are recognised as local by their Host header (`localhost` or `127.0.0.1`, optionally `:8888`). The tunnel forwards the public hostname as the Host, so tunnel traffic never matches.

A fresh environment starts with `admin` / `password`. If a site was ever reachable without the lockdown, change the password (`npm run env:cli -- wp user update admin --user_pass=<new>`) and generate a new keypair (`node tests/tools/webhook.mjs keygen`, then `npm run env:setup`).

Give Afriex this as the webhook URL:

```
https://your-tunnel-host/wp-json/afriex/v1/webhook
```

Verify the whole path before wiring up the dashboard:

```bash
npm run env:order
node tests/tools/webhook.mjs send --url https://your-tunnel-host/wp-json/afriex/v1/webhook \
  --payment-method-id <id from above> --status COMPLETED --amount 49.99
npm run env:show
```

## Getting an order to test against

The webhook matches a deposit to an order by the Afriex payment method id stored on it.

Without live Afriex credentials, `process_payment()` cannot issue an account, so checkout can't reach the on-hold state on its own. `env:order` creates the order the way `store_payment_details()` would have, and prints the id to drive the webhook with:

```bash
npm run env:order
```

```
Order id:          11
Status:            on-hold
Total:             49.99
Payment method id: pm_test_6944
```

Inspect an order at any point — status, Afriex meta and the full note history:

```bash
npm run env:show -- 11     # or omit the id for the most recent Afriex order
```

With real sandbox credentials in the gateway settings, place an order through the store instead and read the id off the order screen.

## The nine tests

### 1. Classic shortcode checkout — *needs credentials*

Create a page containing `[woocommerce_checkout]`, set it as the checkout page, confirm Afriex appears and processes.

### 2. Block checkout — *needs credentials*

Use the default Checkout page (blocks). **Afriex must be visible.** This catches the single most common gateway bug — a gateway implementing only `WC_Payment_Gateway` is invisible here.

If it's missing: confirm `npm run build` produced `assets/js/build/`, and that an API key is saved (`is_available()` hides the gateway without one).

### 3. HPOS on — meta persists and the order is still findable

```bash
npm run env:hpos:on
npm run env:order
npm run webhook:send -- --payment-method-id <id from above> --status COMPLETED --amount 49.99
npm run env:show
```

Enabling HPOS **requires syncing existing orders first** — WooCommerce refuses the option while any order is unmigrated, which is why `env:hpos:on` runs `wp wc hpos sync` before flipping it.

The real risk this covers is the order lookup, not just storage: `find_order_by_payment_method_id()` uses `wc_get_orders()` with a meta query, and a `WP_Query` against `shop_order` would silently return nothing here. If the webhook finds the order and completes it, that path is HPOS-clean.

### 4. HPOS off — same again

```bash
npm run env:hpos:off
```

Repeat test 3. Both storage modes must work.

### 5. Duplicate delivery → one transition

```bash
npm run webhook:send -- --payment-method-id <id> --status COMPLETED --amount 49.99 --repeat 2
```

Both deliveries return 200. The order transitions **once** — expect a single "Afriex payment confirmed." note in `env:show`, not two.

### 6. Tampered body → rejected before any write

```bash
npm run webhook:send -- --payment-method-id <id> --status COMPLETED --amount 49.99 --tamper
```

The tool signs the real body, then alters it before sending — exactly what a forged request looks like. Expect **401**, no order note, no status change. Rejection happens in `permission_callback`, so nothing downstream runs.

### 7. COMPLETED with a mismatched amount → no completion

```bash
npm run webhook:send -- --payment-method-id <id> --status COMPLETED --amount 10.00
```

Against a 49.99 order: a note recording received vs expected, and the order **stays on-hold**. Check the amounts render as currency (`₦10.00`), not escaped entities.

### 8. IN_REVIEW then COMPLETED

```bash
npm run webhook:send -- --payment-method-id <id> --transaction-id txn_a --status IN_REVIEW --amount 49.99
npm run webhook:send -- --payment-method-id <id> --transaction-id txn_b --status COMPLETED --amount 49.99
```

The first must leave the order on-hold with a note only. The second completes it. `IN_REVIEW` is not a failure and must never move the order out of on-hold.

Each call carries a fresh `updatedAt`, so the second is a new event rather than a suppressed duplicate.

### 9. No webhook at all → the sweep still resolves it — *needs credentials*

The backstop for the on-hold trap. Confirm the hourly job exists:

```bash
npm run env:cli -- wp cron event list
```

`afriex_reconcile_pending_orders` should be listed with a 1 hour recurrence. Then trigger it by hand:

```bash
npm run env:sweep
```

The sweep only looks at orders older than an hour and queries the live Afriex API, so resolving an order this way needs sandbox credentials. Without them the client returns a `not configured` error and the sweep exits cleanly — worth confirming too, since it proves a misconfigured store degrades quietly on cron instead of throwing.

## Results as of the last run

Run against WordPress (latest) + WooCommerce 11.1.0, PHP 8.x.

| Test | Result |
|---|---|
| 1. Classic checkout | Not run — needs Afriex credentials |
| 2. Block checkout | Not run — needs Afriex credentials |
| 3. HPOS on | **Pass** — meta persisted, webhook found the order by meta, completed |
| 4. HPOS off | **Pass** — tests 5–8 all ran in this mode |
| 5. Duplicate delivery | **Pass** — two 200s, one transition, one note |
| 6. Tampered body | **Pass** — 401, nothing written |
| 7. Amount mismatch | **Pass** — note added, stayed on-hold |
| 8. IN_REVIEW → COMPLETED | **Pass** — held, then completed |
| 9. Reconciliation sweep | **Partial** — job scheduled hourly and fires; end-to-end needs credentials |

Plugin activation against WooCommerce 11.1 also succeeded with no fatal, which exercises the bootstrap, both compatibility declarations and the activation hook.

## The webhook tool

```bash
node tests/tools/webhook.mjs keygen
node tests/tools/webhook.mjs send [options]
```

| Option | Default | |
|---|---|---|
| `--url` | `http://localhost:8888/wp-json/afriex/v1/webhook` | Target endpoint |
| `--payment-method-id` | `pm_test_1` | Sent as `data.destinationId` |
| `--status` | `COMPLETED` | Any Afriex status |
| `--amount` | `0` | Sent as `data.destinationAmount` |
| `--event` | `TRANSACTION.UPDATED` | Event name |
| `--transaction-id` | `txn_test_1` | |
| `--reference` | — | For pool-collection matching |
| `--repeat` | `1` | Deliver the identical payload N times |
| `--tamper` | off | Alter the body after signing |

Keys live in `tests/tools/.keys/` and are gitignored. `keygen` overwrites them — rerun `npm run env:setup` afterwards so the site gets the new public key.

## What this does not cover

No automated assertions. These are manual checks against a real site, which is what the architecture document's matrix describes — each is about behaviour in a live WooCommerce install rather than a unit boundary.

A PHPUnit suite over `Afriex_Status_Mapper::resolve_action()` and the amount-mismatch branch would be a reasonable addition and needs no WordPress at all beyond stubs. Tests 1, 2 and 9 need Afriex sandbox credentials and cannot be closed without them.
