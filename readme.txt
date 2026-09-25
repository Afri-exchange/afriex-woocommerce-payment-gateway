=== Afriex Gateway for WooCommerce ===
Contributors: afriex
Tags: afriex, woocommerce, payment gateway, bank transfer, mobile money
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept payments in your WooCommerce store through Afriex: a hosted Afriex Checkout page, or a dedicated Nigerian bank account for every order.

== Description ==

Afriex helps businesses collect, hold, convert and move money across Africa and beyond through the Afriex Business API. This plugin connects your WooCommerce store to your Afriex Business account, so customers can pay you with the methods Afriex supports and each order is confirmed automatically when Afriex confirms the payment.

The plugin adds two payment methods. You can turn on either or both.

= Afriex Checkout =

The customer is sent to a secure payment page hosted by Afriex, pays there, and comes back to your store. Depending on your store currency and your Afriex account, the page can offer:

* Card
* Bank transfer
* Mobile money

You choose which of these to allow. Afriex shows only the ones it can collect in your store currency.

= Afriex Bank Transfer =

Each order gets its own Nigerian naira (NGN) bank account from Afriex. The account details are shown on the order-received page and in the order email. The customer transfers the order total, and the order is confirmed when the money arrives. Because every order has its own account, the customer does not have to type a payment reference, and nothing depends on them getting one right.

Afriex Bank Transfer is for stores that sell in NGN. Afriex issues virtual accounts in its **production** environment only, so this method is hidden while the plugin is set to sandbox.

= Why Afriex? =

* **One Afriex account for both methods.** Enter your API key once. Both methods share it.
* **Orders confirm themselves.** Afriex sends a signed notification (webhook) when a payment succeeds, and the order moves to Processing without you checking anything.
* **Nothing is lost if a notification is missed.** The plugin checks unpaid orders with Afriex on a schedule, so a payment still confirms its order if a webhook never arrives.
* **Wrong payments are caught.** A payment that does not match the order's total or currency is never accepted automatically. The order is flagged and a note says what arrived and what was expected.
* **Test before you go live.** Switch between Afriex's sandbox and production environments. Each environment has its own webhook key.

= Plugin features =

* Works on the classic checkout and on the Cart & Checkout blocks.
* Compatible with WooCommerce High-Performance Order Storage (HPOS).
* Webhooks are verified with Afriex's RSA-SHA256 signature before anything is read or changed. A delivery Afriex repeats is recognised and applied once.
* The customer's return from the Afriex page is signed by your store, and the plugin only sends customers to Afriex's own domain.
* After returning from Afriex Checkout, the order-received page shows whether the payment is confirmed, still processing or failed, and updates itself.
* An **Afriex payment** box on the order screen shows the Afriex details for the order, with a **Check Afriex payment now** button.
* Unpaid orders are cancelled after a payment window you set (in minutes for Afriex Checkout, in hours for Afriex Bank Transfer). A payment still in progress at the deadline gets more time.
* Optional debug logging to **WooCommerce > Status > Logs**.

== Installation ==

= Automatic installation =

1. Go to **Plugins > Add New** in your WordPress admin.
2. Search for **Afriex Gateway for WooCommerce**.
3. Click **Install Now**, then **Activate**.

WooCommerce must be installed and active.

= Afriex setup and configuration =

1. Go to **WooCommerce > Settings > Payments**. You will see **Afriex Checkout** and **Afriex Bank Transfer**. Click **Manage** on either one. The Afriex account section at the top of the page is shared by both methods.
2. **Environment** - choose **Sandbox** to test, or **Production** to take real payments. Your Afriex Business dashboard has a Test / Live toggle, and each mode has its own API key.
3. **API key** - paste the API key for the environment you chose, from the API keys page of your [Afriex Business dashboard](https://business.afriex.com/).
4. **Webhook public key** - paste the webhook public key for each environment you use, from **Developers > Webhooks** in the Afriex dashboard. Sandbox and production have different keys.
5. **Webhook URL** - copy the URL shown on the settings page. It looks like `https://your-store.example/wp-json/afriex/v1/webhook`. Save it as your webhook URL in the Afriex dashboard.
6. **Enable** the method, and optionally change its **Title** and **Description** (what the customer sees at checkout).
7. For **Afriex Checkout**, choose the **Allowed payment options** and the **Payment window (minutes)**.
8. For **Afriex Bank Transfer**, set the **Instructions** shown with the account details and the **Payment window (hours)**.
9. Click **Save changes**, then place a test order.

The webhook public key is required. Without it the plugin cannot verify Afriex's notifications, so it rejects them, and orders are only confirmed by the scheduled check.

If a method does not appear at checkout, check that:

* the method is enabled,
* an API key is saved for the environment you chose,
* your store is served over HTTPS (Afriex Checkout needs it),
* for Afriex Bank Transfer, the store currency is NGN and the environment is Production.

== Frequently Asked Questions ==

= What do I need to use this plugin? =

* An Afriex Business account: [business.afriex.com](https://business.afriex.com/).
* WooCommerce 8.0 or later.
* A store served over HTTPS. Afriex only sends customers back to secure addresses.

= Which currencies are supported? =

Afriex Checkout works in the currencies Afriex has enabled for checkout on your business account. Afriex decides which payment options it shows for each currency. For example, Afriex's documentation says a KES payment gets mobile money and a USD payment gets card. If Afriex cannot collect your store currency with any of the options you allowed, the plugin shows you a notice and the customer is asked to choose another payment method.

Afriex Bank Transfer works in NGN only.

= Why is an Afriex Bank Transfer order "On hold" after checkout? =

That is expected. The customer still has to send the transfer. The order moves to Processing when Afriex confirms the money has arrived. If the transfer never arrives, the order is cancelled when the payment window ends.

= Why is an Afriex Checkout order "Pending payment" after the customer returns? =

Returning to your store is not proof of payment. The order is confirmed only when Afriex confirms the payment, which is usually within seconds. The order-received page shows the customer the current status.

= What happens if a customer pays the wrong amount? =

The order is not completed. It is flagged for review, and an order note records what arrived and what was expected, so you can decide what to do.

= Can I refund through this plugin? =

Not yet. The Afriex Business API does not currently offer refunds, so refunds have to be arranged with Afriex outside WooCommerce. If Afriex reports a payment as refunded, the plugin adds a note to the order.

= Does this work with High-Performance Order Storage (HPOS)? =

Yes. The plugin reads and writes order data only through WooCommerce's own order functions, and declares HPOS compatibility.

= Does it work with the Cart & Checkout blocks? =

Yes. Both methods work on the block checkout and on the classic checkout.

== External services ==

This plugin connects to the Afriex Business API, provided by Afriex, Inc. It needs this service to take payments: without it neither payment method works. The plugin talks to `https://api.afriex.com` in production and `https://sandbox.api.afriex.com` in sandbox.

**What is sent, and when**

* **When a customer places an order with Afriex Checkout:** the customer's billing name, email address, phone number and billing country; the order total and currency; the payment options you allowed; a payment reference for the order; the address the customer returns to on your store; and the order ID, order number, your site's domain and the plugin version.
* **When a customer places an order with Afriex Bank Transfer:** the customer's billing name, email address, phone number and billing country, to create or match a customer record in your Afriex account; then the order total, to create the order's bank account.
* **While an order is awaiting payment, and when you click "Check Afriex payment now":** the order's Afriex payment reference or account identifier, to look up its payment.

Nothing is sent to Afriex for customers who do not choose an Afriex payment method.

**What is received**

* The Afriex Checkout page address, or the bank account details shown to the customer.
* Payment status notifications, which Afriex sends to `/wp-json/afriex/v1/webhook` on your site, signed by Afriex.

Afriex terms and conditions: https://www.afriex.com/terms-and-condition
Afriex privacy policy: https://www.afriex.com/privacy-policy

== Screenshots ==

1. Afriex Checkout and Afriex Bank Transfer on the WooCommerce Payments settings page.
2. The Afriex settings page, with the shared Afriex account section.
3. Afriex Checkout on the block checkout.
4. The bank account details shown to the customer after an Afriex Bank Transfer order.

== Changelog ==

= 1.1.0 =
* New: Afriex Checkout, a second payment method that sends the customer to a secure Afriex-hosted payment page (card, bank transfer or mobile money, depending on currency).
* New: One shared Afriex account section for both methods, with separate sandbox and production webhook keys.
* New: The order-received page shows the payment's status after an Afriex Checkout payment and updates itself.
* New: An Afriex payment box on the order screen, with a "Check Afriex payment now" button.
* New: Payment windows. Unpaid orders are cancelled at the deadline, and a payment still in progress gets more time.
* New: The Afriex logo on the checkout and on the Payments settings page.
* Improved: Afriex Bank Transfer gives every order its own NGN account, confirmed automatically when the transfer arrives.
* Improved: Unpaid orders are checked with Afriex every few minutes, backing off over time, instead of hourly.
* Improved: A payment in the wrong amount or currency, or one that could belong to more than one order, is flagged for review and never applied automatically.
* Improved: A repeated webhook delivery is applied once, and two deliveries arriving together cannot both complete an order.
* Changed: The shared pool account from 1.0 is no longer offered. Unpaid orders placed with it are flagged for review, because a payment into a shared account cannot be matched to an order automatically.
* Changed: Requires WooCommerce 8.0 or later. Tested with WordPress 7.1 and WooCommerce 11.1.

