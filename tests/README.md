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
  --payment-method-id <id from above> --status SUCCESS --amount 49.99
npm run env:show
```

## Getting an order to test against

The webhook matches a deposit to an order by the Afriex payment method id stored on it.

```bash
npm run env:order          # writes the meta store_payment_details() would write
npm run env:order -- real  # calls process_payment(), so the mock issues the account
```

```
Order id:          143
Status:            on-hold
Total:             49.99 NGN
Expected (minor):  4999
Environment:       sandbox
Payment method id: pm_test_9427
Account number:    0123456789
Bank:              Test Bank
```

The default writes the meta by hand and works with or without the mock. `real` goes through the gateway, so every field is what the plugin genuinely stores and the account exists in the mock's fake Afriex account — which is what you want when the plugin will look the transaction up again. `real` needs the mock (below); without it the call is refused locally and nothing is created.

`real` also shows the two things phase C still owes Bank Transfer: the billing phone is sent raw, so a shopper who types `08012345678` gets a `400` from Afriex, and `_afriex_expected_amount_minor` is not written at checkout yet.

Inspect an order at any point — status, every `_afriex_*` key and the full note history:

```bash
npm run env:show -- 143    # or omit the id for the most recent Afriex order
```

With real sandbox credentials in the gateway settings, place an order through the store instead and read the id off the order screen.

## The development mock Afriex API

[`tests/mu-plugins/afriex-mock-api.php`](mu-plugins/afriex-mock-api.php) is a local stand-in for the whole Afriex Business API. It answers `pre_http_request` for `afriex.com` and its subdomains, keeps a fake account in one option, and delivers **genuinely signed** webhooks back at the site, so the plugin's real `openssl_verify()` path runs.

It is inert unless **both** are true: `AFRIEX_DEV_MOCK_API` is `true` and `wp_get_environment_type()` is `local` or `development`. The constant comes from `.wp-env.json`:

```json
"config": { "AFRIEX_DEV_MOCK_API": true }
```

```bash
npx wp-env start --update                       # after editing .wp-env.json
npm run env:cli -- config get AFRIEX_DEV_MOCK_API   # prints 1
```

It implements customer create (including the `409` that carries `details.data.customerId`) and lookup, virtual-account create and get, transaction get and list (zero-based paging, comma-separated filters, more than 100 rows), and checkout-session create with the documented validation rules. Errors have Afriex's shape, `{code, error, details: {errorMessage, friendlyMessage, data}}`, and `friendlyMessage` is often empty, as it is in real life.

### Driving it

```bash
npm run webhook:deposit -- --payment-method-id pm_test_9427 --amount 49.99
npm run webhook:deposit -- --payment-method-id pm_test_9427 --amount 49.99 --status PENDING
npm run webhook:status  -- --transaction-id 40947b10e6dc8ba8d083cb9d --status SUCCESS
npm run webhook:session -- --reference wc_143_ab12cd34ef56 --transaction-id 40947b...
npm run webhook:pm      -- --payment-method-id pm_test_9427 --status expired
npm run mock:state -- --section requests     # every call the plugin made, and where
npm run mock:fail  -- --endpoint checkout_session --mode unavailable --times 1
npm run mock:reset
```

`deposit` records the transaction and then has the site deliver `TRANSACTION.CREATED` for it; `status` moves it on and re-delivers `TRANSACTION.UPDATED`. `pm` moves a virtual account's status and delivers `PAYMENT_METHOD.UPDATED`. Because everything is in the fake account, every later re-fetch, list and sweep finds it too.

**Use these, not `webhook:send`, for anything that should change an order.** Since 1.1 a webhook is only a trigger: the plugin re-fetches the transaction from Afriex and acts on what comes back. A payload that names a transaction the mock has never heard of gets a `404` on the re-fetch, and the plugin answers `200 refetch_refused:404` and changes nothing — which is the point. `webhook:send` is for testing the route itself: a tampered body, a missing signature, a replay, an event nothing knows about.

### Driving the Checkout return without a webhook

The return handler and the status endpoint are the *other* two ways a Checkout payment gets confirmed, and the interesting case is the one where the webhook never arrives. Create the transaction with `"deliver": "none"` so the mock records it and stays quiet:

```bash
curl -s -X POST http://localhost:8888/wp-json/afriex-mock/v1/transaction \
  -H 'Content-Type: application/json' \
  -d '{"merchantReference":"wc_143_ab12cd34ef56","amount":"49.99","currency":"NGN","status":"SUCCESS","deliver":"none"}'
```

Then send the shopper back the way Afriex would. The return URL carries the reference and an HMAC, and nothing else — no order key:

```
http://localhost:8888/?wc-api=afriex_checkout_return&ref=<reference>&sig=<hmac>
```

The signature is `Afriex_Checkout_Gateway::return_signature( $reference )`, so the whole URL is easiest to read off the mock's own record of the session:

```bash
npm run mock:state -- --section sessions     # each session's redirectUrl
```

A bad or missing `sig` redirects to the shop page and does nothing at all. A good one asks Afriex about the reference, settles what comes back, and redirects to the order-received page, so that page is drawn from the fresh status.

While an order is still unconfirmed the order-received page shows a *Confirming your payment* panel that polls
`/wp-json/afriex/v1/order-status?order={id}&key={order key}` every five seconds for two minutes:

```bash
curl -i "http://localhost:8888/wp-json/afriex/v1/order-status?order=143&key=wc_order_xxxxxxxxxxxxx"
```

It answers `{"status":"paid"|"pending"|"failed"}` and nothing else, `Cache-Control: no-store, private`, and the **same** `404` for a wrong key, an unknown order and an order paid by another method. Both it and the return handler share one throttle — at most one upstream call per order per 15 seconds, and 30 a minute across the site — so fifty parallel polls cost one call to Afriex, which `npm run mock:state -- --section requests` will show.

### The fake hosted payment page

`POST /checkout-session` answers with a `checkoutUrl` of `/?afriex-mock-pay={reference}`, and that page is the hosted checkout: it shows the session's amount, currency, reference, expiry and channels, and offers four buttons — **Pay now**, **Fail the payment**, **Leave it processing** and **Succeed after N seconds**. Pressing one records a transaction in the fake account, fires the signed `TRANSACTION.*` and `CHECKOUT_SESSION.CREATED` webhooks, and `302`s to the session's `redirectUrl`, which is the plugin's own signed return URL. Nothing in the plugin can tell it is not Afriex.

The page answers only to a `localhost` or `127.0.0.1` Host header, like the control routes, and it carries no nonce: the page it imitates is on another origin and has never heard of WordPress.

Three of its fields have store-wide defaults, set through `POST /settings`:

| Field | Setting | |
|---|---|---|
| Order | `webhook_order` | `transaction_first` (default) or `session_first` |
| Delay | `webhook_delay_seconds` | Hold the webhooks back this many seconds |
| Lose the webhook | `lose_webhook` | Deliver nothing at all, and record that it was dropped on purpose |

Two more boxes pay the **wrong amount** or in the **wrong currency**, which the store must refuse.

Anything deferred — a delayed webhook, "succeed after N seconds" — goes in a queue. WP-Cron runs it when somebody visits the site; a test that will not wait runs it itself:

```bash
curl -s -X POST http://localhost:8888/wp-json/afriex-mock/v1/tick              # whatever is due
curl -s -X POST http://localhost:8888/wp-json/afriex-mock/v1/tick -d force=1   # everything, now
```

The same buttons without a browser, which is how the scenario suite presses them:

```bash
curl -s -X POST http://localhost:8888/wp-json/afriex-mock/v1/pay \
  -H 'Content-Type: application/json' \
  -d '{"merchantReference":"wc_143_ab12cd34ef56","outcome":"success"}'
```

`outcome` is `success`, `failure`, `processing` or `delayed`; the other fields are `channel`, `delay`, `webhook_order`, `webhook_delay`, `lose_webhook`, `amount` and `currency`. It answers with the transaction it recorded, where it would have redirected, and what it delivered or queued.

**Opening the page in a browser on this http site.** The stored `checkoutUrl` is `https`, because Afriex's own page always is and because the plugin refuses any other scheme (plan 5.1 step 7) — a rule worth keeping under test. This site has no TLS, so the mock rewrites only the redirect handed to the browser (classic, block and order-pay checkout) to `http`, and a page opened over `http` sends the shopper back over `http` too. A normal checkout in a browser therefore just works; the stored meta stays `https`. If you open a stored `https://localhost:8888/?afriex-mock-pay=…` link by hand, change it to `http`.

`redirect_url_scheme` (`http`, `https` or `as-is`) still overrides the return scheme for every caller, including `POST /pay`. `checkout_url_scheme=http` makes the stored `checkoutUrl` itself `http`, which the plugin then refuses — useful only for testing that refusal.

WooCommerce's coming-soon mode is on for store pages on this site, so a logged-out browser sees a placeholder instead of the order-received page. Switch `woocommerce_coming_soon` to `no` for the run, and back to `yes` afterwards.

### Failure modes

`mock:fail` arms one endpoint. `--times N` fires it N times; leave it out and it stays until `--mode none` clears it.

| Endpoint | |
|---|---|
| `customer_create`, `customer_list` | |
| `virtual_account_create`, `payment_method_get` | |
| `transaction_get`, `transaction_list` | |
| `checkout_session` | |

| Mode | What the plugin sees |
|---|---|
| `validation` | `422 VALIDATION_ERROR` |
| `unsupported_channels` | `422` for "no requested channel works for this currency" |
| `created_then_unavailable` | The session is created, then `503` — the case where the request took effect anyway |
| `unavailable` | `503` with `Retry-After: 1` |
| `rate_limited` | `429` with `Retry-After: 1` |
| `gateway` | `502` with Cloudflare's HTML body and no Afriex JSON |
| `auth` | `401 AUTHENTICATION_ERROR` — hides every Afriex method |
| `forbidden` | `403` with `{"message":"Not allowed"}`, Afriex's body for a checkout session it will not create for this business — on `checkout_session`, hides Afriex Checkout only |
| `server` | `500 INTERNAL_SERVER_ERROR` |
| `network` | A transport failure, as if the host were unreachable |
| `empty_body` | `200` with an empty body |
| `slow` | The call succeeds, `slow_seconds` late (3 by default; `POST /settings` changes it). It never outlives the caller's own timeout: past that it returns the cURL-28 `WP_Error` a real socket gives, so the plugin's re-fetch timeout and its webhook budget can be reached from here |

Three more shape a **successful** `POST /payment-method/virtual-account`
response rather than replacing it, because what they stand for is a 201 whose
payload the plugin has to refuse or interpret:

| Mode | What the plugin sees |
|---|---|
| `no_account_number` | A 201 with no `accountNumber` — nothing for the customer to transfer to (F48) |
| `inactive` | A 201 whose `status` is `pending` rather than active (F48) |
| `no_expiry` | A 201 with no `expiresInMinutes`, so the store's payment window is the whole answer (F33). The stored account loses the field too, so a later `GET /payment-method/{id}` tells the same story |

### Control routes

The same routes the tool calls, under `/wp-json/afriex-mock/v1/`: `GET /state`, `POST /reset`, `POST /settings`, `POST /failure`, `POST /transaction`, `POST /transaction/{id}/status`, `POST /checkout-session-event`, `POST /payment-method-event`, `POST /pay`, `POST /tick`. **They answer only to a `localhost` or `127.0.0.1` Host header** and everything else gets the same `404` an unknown route gets — the tunnel guard already 404s a foreign host before WordPress routes it, and these routes can forge a signed webhook, so they carry their own lock.

`POST /settings` changes `webhook_url`, `va_expires_in_minutes`, `session_ttl_minutes`, `require_api_key`, `unsupported_channels_code`, `slow_seconds`, the hosted page's `webhook_order`, `webhook_delay_seconds` and `lose_webhook`, the `checkout_url_scheme` and `redirect_url_scheme`, and the per-currency `channels` map (NGN collects on `VIRTUAL_BANK_ACCOUNT` only, KES on `MOBILE_MONEY`, USD on `CARD` and `VIRTUAL_BANK_ACCOUNT`, and so on). A currency mapped to `[]` collects on nothing, which is the unsupported-channels `422`.

### What the mock guesses

Afriex has not published these, so the mock takes the plan's assumption (plan section 16) and makes it configurable:

- the **code** for the unsupported-channels `422`. The mock sends `UNSUPPORTED_CHANNELS`; change it with `POST /settings`.
- the **channel matrix** per currency.
- a **reused `merchantReference`** is accepted and overwrites the stored session. The plugin mints a unique one per session, so this is not exercised.

## The nine tests

### 1. Classic shortcode checkout — *needs credentials*

Create a page containing `[woocommerce_checkout]`, set it as the checkout page, confirm Afriex appears and processes.

### 2. Block checkout — *needs credentials*

Use the default Checkout page (blocks). **Afriex must be visible.** This catches the single most common gateway bug — a gateway implementing only `WC_Payment_Gateway` is invisible here.

If it's missing: confirm `npm run build` produced `assets/js/build/`, and that an API key is saved (`is_available()` hides the gateway without one).

### 3. HPOS on — the payment still finds its order

```bash
npm run env:hpos:on
npm run env:order -- real                   # prints the order id and its account id
npm run webhook:deposit -- --payment-method-id <id from above> --amount 49.99
npm run env:show -- <order id>
```

Enabling HPOS **requires syncing existing orders first** — WooCommerce refuses the option while any order is unmigrated, which is why `env:hpos:on` runs `wp wc hpos sync` before flipping it. Turning it off again also needs a sync, because the orders you just made exist only in the HPOS tables.

The real risk here is the look-up, not the storage. Every settlement starts by turning an Afriex transaction into an order id, and that goes through `Afriex_Order_Meta::query_orders()`, which has a different branch per storage: HPOS takes a `meta_query`, and the posts storage silently drops one, so it gets a private query var merged in instead. Get that wrong and a payment binds to the wrong order. Run tests 5 to 8 in **both** modes.

### 4. HPOS off — same again

```bash
npm run env:cli -- wc hpos sync             # bring the new orders back to the posts tables
npm run env:hpos:off
```

Repeat test 3.

### 5. Duplicate delivery → one transition

```bash
npm run webhook:deposit -- --payment-method-id <id> --amount 49.99
npm run webhook:deposit -- --payment-method-id <id> --amount 49.99 --transaction-id <the same id>
```

Both deliveries return 200; the second says `"result":"duplicate"`. The order transitions **once** — one "Afriex payment confirmed by …" note, not two.

Two deliveries **at the same time** are the harder case: one gets `200`, the other `409 in_progress`, and Afriex retries the loser later. The event claim is a three-state row in `wp_afriex_ledger` (`processing` for 60 s, then `done` for a week), so two PHP processes cannot both do the work.

### 6. Tampered body → rejected before any write

```bash
npm run webhook:send -- --payment-method-id <id> --status SUCCESS --amount 49.99 --tamper
```

The tool signs the real body, then alters it before sending — exactly what a forged request looks like. Expect **401**, no order note, no status change, and no ledger row. Rejection happens in `permission_callback`, so nothing downstream runs. `--no-signature` and a junk signature must both be 401 too.

### 7. SUCCESS with a mismatched amount → no completion

```bash
npm run webhook:deposit -- --payment-method-id <id> --amount 10.00
```

Against a 49.99 order: a note recording received against expected, `_afriex_needs_review` set, and the order **stays on-hold**. Check the amounts render as currency (`₦10.00`), not escaped entities. The wrong **currency** does the same.

### 8. IN_REVIEW then SUCCESS

```bash
npm run webhook:deposit -- --payment-method-id <id> --amount 49.99 --status IN_REVIEW
npm run webhook:status  -- --transaction-id <the id it printed> --status SUCCESS
```

The first must leave the order on-hold with one note. The second completes it. `IN_REVIEW` is not a failure and must never move the order out of on-hold, and delivering it twice must not add a second note.

### 9. No webhook at all → the sweep still resolves it

The backstop for the on-hold trap. It is the Action Scheduler action `afriex_wc_sweep`, every 5 minutes, in the group `afriex-gateway-for-woocommerce`. Confirm it exists:

```bash
npm run env:cli -- action-scheduler action list --hook=afriex_wc_sweep --status=pending
```

or look under WooCommerce > Status > Scheduled Actions. It re-creates itself on the next request if it is deleted. Then trigger it by hand:

```bash
npm run env:sweep
```

What a run does (plan 6.4, 5.6 and 9):

- at most **25 due orders**, chosen by `_afriex_next_check`;
- each order then waits **5 min, 15 min, 1 h, 6 h, 24 h** before the next check, counted in `_afriex_check_count`;
- **Bank Transfer is listed once per run, and is note-only.** One `GET /transaction` per run per currency, with the documented filters only — `type=DEPOSIT&channel=VIRTUAL_BANK_ACCOUNT&currency={store}&fromDate={oldest open order}`, `page` from 0 and `limit=100`, at most 5 pages — and the rows are matched to orders **in memory**, on `sourceId`/`destinationId`. Thirty due orders cost one listing, not thirty. A row that matches no order is never applied; a row whose account names two orders is refused and **both** orders are flagged `account_ambiguous` (F30). Nothing is settled: each matched deposit gets one note saying exactly what settling would have done, from the real decision table. That is deliberate until Afriex answers questions 7 to 9 of plan section 16, and turning it on afterwards is one line — `Afriex_Bank_Transfer_Sweep::SETTLE_FROM_LISTING`, or the filter `woocommerce_afriex_settle_bank_transfer_from_sweep` for a test;
- **Afriex Checkout does settle.** A Checkout payment is bound to its order by a reference this plugin minted, so a listing for that reference is positive identification and none of plan 16's open questions apply. Each run lists **every** reference the order has issued — not just the current one — and settles any `SUCCESS`; anything else gets one deduplicated note;
- at the order's **deadline** it runs the expire routine, which settles any `SUCCESS` first, extends the deadline if a payment is still moving, and only cancels when everything failed or nothing exists;
- a **429 or 503** stops the whole run and pauses the sweep for as long as `Retry-After` says, up to 30 minutes;
- an order another process has locked is skipped, not waited for.

To watch the backoff and the expiry without waiting, set the meta by hand:

```bash
npm run env:cli -- eval '$o=wc_get_order(123); $o->update_meta_data("_afriex_next_check",time()-1); $o->update_meta_data("_afriex_deadline",time()-60); $o->save();'
npm run env:sweep
```

**Afriex Checkout orders** are not cancelled by WooCommerce's hold-stock timer: `Afriex_Scheduler::keep_unpaid_order()` returns false for any `afriex_checkout` order that has a session. The plugin's own deadline ends them instead. To see both halves of that, age an order past the hold-stock minutes and run WooCommerce's own timer by hand — an identical order **without** a reference is cancelled, and the one with a reference is not:

```bash
npm run env:cli -- eval 'wc_cancel_unpaid_orders();'
```

### Late payments on a closed Checkout order

A cancelled or failed Checkout order that still holds a reference stays in the sweep for the payment window plus **48 hours**, so a `SUCCESS` that turns up after the store gave up is found. It is flagged, never applied: `Afriex_Settlement` refuses to revive a cancelled order, and adds a note, a `_afriex_needs_review` flag and an admin notice instead. A `failed` order the plugin itself failed does complete.

The end of that watch is stored on the order as `_afriex_watch_until`, and the due-order query filters on it, so the order leaves the sweep on its own with nothing to clean up:

```bash
npm run env:cli -- eval '$o=wc_get_order(123); echo $o->get_meta("_afriex_watch_until");'
```

### The Afriex box and "Check Afriex payment now"

Every Afriex order — either method — has an **Afriex** box on its edit screen: the method, the environment, the key it was taken with, the virtual account or **every** Checkout reference with its session id, expiry and channels, the channel used, the settled transaction, the last Afriex status and the review flag. It is read-only.

**Check Afriex payment now** is in the *Order actions* list, so WooCommerce's own order-edit nonce and `edit_shop_order` capability are what guard it; there is no second URL. For a Checkout order it runs `Afriex_Checkout_Return::maybe_check()`, the same throttled, locked check the return page runs, and settles a payment Afriex has but the store has not applied. For a Bank Transfer order it makes the same strict listing for that one order, and past the payment window it runs the expire routine exactly as the sweep would (plan 4.3, plan 9) — which settles any `SUCCESS` first and cancels only on positive evidence that every payment failed. Either way the result is a one-line admin notice on the order screen.

Because it shares the page throttle, pressing it twice inside fifteen seconds says so rather than calling Afriex again.

## The Checkout scenario matrix

Plan section 15 lists the scenarios Afriex Checkout has to survive. All of them can be produced locally, and this is the shortest route to each one. `{ref}` is the order's current reference, from `npm run mock:state -- --section sessions` or `_afriex_checkout_current_reference` on the order.

| Scenario | How |
|---|---|
| Paid, webhook first | `POST /pay` with `outcome=success` |
| Paid, return first | `outcome=success`, `lose_webhook=1`, then open the return URL |
| Paid, webhook lost | `outcome=success`, `lose_webhook=1`, then `Afriex_Scheduler::check_order( $id )` |
| Failure, then retry | `outcome=failure`, then age the reference past its expiry and pay again |
| A second payment on an old session | pay twice on the same reference; the second is flagged, never applied |
| Wrong amount or currency | `POST /pay` with `amount` or `currency` set to something else |
| A type that is not `DEPOSIT` | `POST /transaction` with `type=WITHDRAWAL` |
| An unmatched or forged reference | `POST /transaction` with a reference no order issued |
| A replayed webhook | deliver the same payload twice; the second answers `duplicate` |
| A duplicate while the first is in flight | `node sign-send.mjs payload.json --concurrent 3`: one `200`, the rest `409 in_progress` |
| A webhook slower than the page | `webhook_delay=30`, confirm from the page, then `POST /tick -d force=1` |
| Webhook, return and sweep at once | run the three in parallel; one settlement, no second-payment alarm |
| Double submit, a second tab | call `process_payment()` again: the same hosted page comes back, and no new session is created |
| "Pay again" while `PROCESSING` | `outcome=processing`, age the session, pay again: refused, "already in progress" |
| The hold-stock timer | age the order and run `wc_cancel_unpaid_orders()`; a Checkout order with a reference survives |
| The deadline with a payment in flight | `outcome=processing`, age the order **and its references**, sweep: extended, not cancelled |
| A late `SUCCESS` after cancellation | cancel the order, then `outcome=success`: flagged, not revived |
| The deadline after the money arrived | `outcome=success`, `lose_webhook=1`, age, sweep: settled, not cancelled |
| `REFUNDED`, `DISPUTE_LOST` | `POST /transaction/{id}/status` after the order is paid |
| The `422`s, the `503`, the transport failures | `mock:fail --endpoint checkout_session --mode ...` |
| A missing phone number | an order with no billing phone: refused before any call to Afriex |
| A `checkoutUrl` off the allow-list | remove the mock's `woocommerce_afriex_checkout_url_hosts` filter for the call |
| A non-https store, an exponent-0 currency | filter `woocommerce_afriex_checkout_return_url_base` to `http`, or `woocommerce_currency` to `UGX` |
| Status-endpoint abuse | a wrong key, then `seq 50 \| xargs -P 50 curl ...`: fifty `200`s and one call to Afriex |

The deadline is measured from the **first reference's creation**, not from the order's date (plan 5.6), so ageing an order alone leaves the sweep with nothing to do — age the reference rows' `created` too, and their `expires_at` if a `CHECKOUT_SESSION.CREATED` has written one.

**The dev site blocks the real Afriex API.** `tests/mu-plugins/10-afriex-block-live-api.php` refuses every request to `afriex.com` and its subdomains that no mock has answered, so the sweep sees a network error (status 0), changes no order and records no 401. Mocks hooked on `pre_http_request` still answer first. To call the real sandbox on purpose, define `AFRIEX_DEV_ALLOW_LIVE_API` as true in `wp-config.php`.

## Counting what happened in another process

A signed webhook arrives in its own PHP process, so a script that drives one cannot hook `woocommerce_payment_complete` and watch. `tests/mu-plugins/20-afriex-test-probe.php` writes the counts into an option instead. It does nothing until a test switches it on:

```php
update_option( 'afriex_test_probe', array( 'on' => 1 ) );
// ... drive the webhook ...
get_option( 'afriex_test_probe' )['payment_complete:123'];   // how many times it fired
delete_option( 'afriex_test_probe' );
```

It counts `woocommerce_payment_complete` and every order status change. This is what proves F87: an order that is paid, put back on hold by the merchant and then sent the same payment again must **not** fire `payment_complete` a second time.

## Results as of the last run

Run against WordPress (latest) + WooCommerce 11.1.1, PHP 8.3, with the mock API.

| Test | Result |
|---|---|
| 1. Classic checkout | Not run — needs Afriex credentials |
| 2. Block checkout | Not run — needs Afriex credentials |
| 3. HPOS on | **Pass** — the whole webhook suite, 93 checks, with HPOS authoritative |
| 4. HPOS off | **Pass** — the same 93 checks on the posts storage |
| 5. Duplicate delivery | **Pass** — 200 then 200 duplicate, one transition; two at once give one 200 and one 409 |
| 6. Tampered body | **Pass** — 401, nothing written, no ledger row |
| 7. Amount mismatch | **Pass** — flagged, stayed on-hold; wrong currency the same |
| 8. IN_REVIEW → SUCCESS | **Pass** — one note, then completed |
| 9. Sweep | **Pass** — 53 checks: due orders, backoff, note-only, expiry, the 429 stop and the cancellation filter |

Plugin activation against WooCommerce 11.1 also succeeded with no fatal, which exercises the bootstrap, both compatibility declarations and the activation hook.

## The webhook tool

```bash
node tests/tools/webhook.mjs                # usage for every command
node tests/tools/webhook.mjs keygen
node tests/tools/webhook.mjs send [options]
```

`send` signs the payload in Node and POSTs it straight at the webhook route. Nothing else on the site knows the transaction exists, which is the point: it tests the route. For a payment the plugin will look up again, use `deposit` and `status` above.

| Option | Default | |
|---|---|---|
| `--url` | `http://localhost:8888/wp-json/afriex/v1/webhook` | Target endpoint |
| `--site` | `http://localhost:8888` | Base URL for every command |
| `--payment-method-id` | `pm_test_1` | Sent as `data.destinationId` |
| `--status` | `SUCCESS` | Any Afriex status |
| `--amount` | `0` | Sent as both amounts, as a decimal string |
| `--currency` | `NGN` | |
| `--type` / `--channel` | `DEPOSIT` / `VIRTUAL_BANK_ACCOUNT` | |
| `--event` | `TRANSACTION.UPDATED` | Event name |
| `--transaction-id` | `txn_test_1` | |
| `--reference` | — | Sent as `merchantReference` and `meta.reference` |
| `--legacy-reference` | off | Also sets the top-level `reference` that 1.0 pool matching read |
| `--repeat` | `1` | Deliver the identical payload N times |
| `--tamper` | off | Alter the body after signing |

`pm` takes `--payment-method-id` and `--status` (default `expired`). It moves the account in the mock's store first, so the plugin's re-fetch agrees with the event; a `PAYMENT_METHOD.UPDATED` whose account is still active is answered `200 not_expired` and changes nothing.

`SUCCESS`, not `COMPLETED`: `COMPLETED` is not in Afriex's status enum at all, so a payload carrying it only ever exercised the plugin's fallback handling.

Keys live in `tests/tools/.keys/` and are gitignored. `keygen` overwrites them — rerun `npm run env:setup` afterwards so the site gets the new public key.

## Unit tests (PHPUnit)

`tests/unit/` holds WordPress-free PHPUnit 9.6 tests for the pure classes: `Afriex_Money`, `Afriex_Phone`, `Afriex_Status_Mapper`, reference minting and parsing, the settlement decision table, and `Afriex_Checkout_Fields` — the channel list and the metadata rules of a checkout session, which live outside the gateway precisely so that they can be tested without WooCommerce. `tests/unit/bootstrap.php` defines `ABSPATH` and a few stubs and loads only those classes. PHP is only installed in the wp-env container, so run them there, as the Apache user, from the plugin folder:

```bash
C=$(docker ps --format '{{.Names}}' | grep -- '-wordpress-1$' | head -1)
docker exec -u hp -w /var/www/html/wp-content/plugins/afriex-woocommerce-payment-gateway "$C" composer install
docker exec -u hp -w /var/www/html/wp-content/plugins/afriex-woocommerce-payment-gateway "$C" vendor/bin/phpunit
```

(From Git Bash on Windows, prefix `docker exec` with `MSYS_NO_PATHCONV=1`.) `composer.json` pins the platform to PHP 7.4, so the locked dev dependencies also install on the plugin's minimum PHP.

## What this does not cover

The nine tests above have no automated assertions. They are manual checks against a real site, which is what the architecture document's matrix describes — each is about behaviour in a live WooCommerce install rather than a unit boundary.

Tests 1 and 2 need Afriex sandbox credentials and cannot be closed without them.

Nothing here talks to the real Afriex. Every result above is what the plugin does against the mock, which was written from Afriex's documentation and guesses at the parts the documentation does not cover — the three guesses are listed above. Plan section 16 lists the ten questions that have to be answered before the Bank Transfer sweep can settle anything on its own.
