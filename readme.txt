=== Afriex Gateway for WooCommerce ===
Contributors: afriex
Tags: payments, woocommerce, payment gateway, bank transfer, mobile money
Requires at least: 6.2
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

The customer is sent to a secure payment page hosted by Afriex, pays there, and comes back to your store. Depending on your store currency and your Afriex account, the page can offer card, bank transfer and mobile money. You choose which of these to allow, and Afriex shows only the ones it can collect in your store currency.

= Afriex Bank Transfer =

Each order gets its own Nigerian naira (NGN) bank account from Afriex. The account details are shown on the order-received page and in the order email. The customer transfers the order total, and the order is confirmed when the money arrives. Because every order has its own account, the customer does not have to type a payment reference.

Afriex Bank Transfer is for stores that sell in NGN. Afriex issues these accounts in its **production** environment only, so this method is hidden while the plugin is set to sandbox.

= Features =

* **One Afriex account for both methods.** Enter your API key once.
* **Orders confirm themselves.** Afriex sends a signed notification (webhook) when a payment succeeds, and the order moves to Processing.
* **Nothing is lost if a notification is missed.** Unpaid orders are checked with Afriex on a schedule.
* **Wrong payments are caught.** A payment that does not match the order's total or currency is never accepted automatically. The order is flagged, with a note saying what arrived and what was expected.
* **Secure by design.** Webhooks are verified with Afriex's signature before anything is read or changed, and a repeated delivery is applied once. The customer's return from Afriex is signed by your store.
* **Sandbox and production.** Each environment has its own API key and webhook key.
* Works on the classic checkout and on the Cart & Checkout blocks, and with High-Performance Order Storage (HPOS).
* The order-received page shows whether an Afriex Checkout payment is confirmed, processing or failed, and updates itself.
* An **Afriex payment** box on the order screen, with a **Check Afriex payment now** button.
* Unpaid orders are cancelled after a payment window you set. A payment still in progress at the deadline gets more time.
* Optional debug logging to **WooCommerce > Status > Logs**.

= Source code =

The block checkout scripts in `assets/js/build/` are compiled from `assets/js/src/`, which ships with the plugin together with `package.json` and `webpack.config.js`. To rebuild them, run `npm install` and then `npm run build`. The full source is at [github.com/Afri-exchange/afriex-woocommerce-payment-gateway](https://github.com/Afri-exchange/afriex-woocommerce-payment-gateway).

== Installation ==

1. Install and activate WooCommerce, then install and activate this plugin from **Plugins > Add New**.
2. Go to **WooCommerce > Settings > Payments** and click **Manage** on **Afriex Checkout** or **Afriex Bank Transfer**. The Afriex account section at the top is shared by both methods.
3. **Environment** - choose **Sandbox** to test, or **Production** to take real payments.
4. **API key** - paste the key for that environment from the API keys page of your [Afriex Business dashboard](https://business.afriex.com/).
5. **Webhook public key** - paste the key for each environment you use, from **Developers > Webhooks** in the Afriex dashboard.
6. **Webhook URL** - copy the URL shown on the settings page and save it as your webhook URL in the Afriex dashboard.
7. **Enable** the method, and optionally change its **Title**, **Description** and **Afriex logo** setting.
8. For Afriex Checkout, choose the **Allowed payment options** and the **Payment window (minutes)**. For Afriex Bank Transfer, set the **Instructions** and the **Payment window (hours)**.
9. Click **Save changes**, then place a test order.

The webhook public key is required. Without it the plugin rejects Afriex's notifications, and orders are only confirmed by the scheduled check.

== Frequently Asked Questions ==

= What do I need to use this plugin? =

An [Afriex Business account](https://business.afriex.com/), WooCommerce 8.0 or later, and a store served over HTTPS.

= A payment method does not appear at checkout. Why? =

Check that the method is enabled, that an API key is saved for the environment you chose, and that your store uses HTTPS. Afriex Bank Transfer also needs the store currency to be NGN and the environment to be Production.

= Which currencies are supported? =

Afriex Checkout works in the currencies Afriex has enabled for checkout on your account, and Afriex decides which payment options it shows for each. If Afriex cannot collect your store currency with any option you allowed, the plugin shows you a notice and the customer is asked to choose another payment method. Afriex Bank Transfer works in NGN only.

= Why is an Afriex Bank Transfer order "On hold" after checkout? =

The customer still has to send the transfer. The order moves to Processing when Afriex confirms the money has arrived, or is cancelled when the payment window ends.

= Why is an Afriex Checkout order "Pending payment" after the customer returns? =

Returning to your store is not proof of payment. The order is confirmed when Afriex confirms the payment, usually within seconds.

= What happens if a customer pays the wrong amount? =

The order is not completed. It is flagged for review, and an order note records what arrived and what was expected.

= Can I refund through this plugin? =

Not yet. The Afriex Business API does not offer refunds, so they have to be arranged with Afriex. If Afriex reports a payment as refunded, the plugin adds a note to the order.

== External services ==

This plugin connects to the Afriex Business API, provided by Afriex. Neither payment method works without it. The plugin talks to `https://api.afriex.com` in production and `https://sandbox.api.afriex.com` in sandbox.

**What is sent, and when**

* **When a customer places an order with Afriex Checkout:** the customer's billing name, email address, phone number and billing country; the order total and currency; the payment options you allowed; a payment reference; the address the customer returns to on your store; and the order ID, order number, your site's domain and the plugin version.
* **When a customer places an order with Afriex Bank Transfer:** the customer's billing name, email address, phone number and billing country, to create or match a customer record in your Afriex account; then the order total, to create the order's bank account.
* **While an order is awaiting payment, and when you click "Check Afriex payment now":** for Afriex Checkout, the order's payment reference. For Afriex Bank Transfer, a request for recent NGN bank-transfer deposits (type, channel, currency and start date, no customer details), and, when you check one order, its Afriex account and transaction IDs.

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
* New: Afriex Checkout, a hosted Afriex payment page (card, bank transfer or mobile money, depending on currency).
* New: One shared Afriex account section, with separate sandbox and production webhook keys.
* New: Live payment status on the order-received page, and an Afriex payment box on the order screen.
* New: Payment windows. Unpaid orders are cancelled at the deadline.
* New: Optional Afriex logo at checkout.
* Improved: Every Afriex Bank Transfer order gets its own NGN account.
* Improved: Unpaid orders are checked every few minutes instead of hourly.
* Improved: Mismatched or ambiguous payments are flagged for review, and repeated webhooks are applied once.
* Changed: The shared pool account from 1.0 is withdrawn. Its unpaid orders are flagged for review.
* Changed: Requires WordPress 6.2 and WooCommerce 8.0 or later.

== Upgrade Notice ==

= 1.1.0 =
Adds Afriex Checkout. The 1.0 shared pool account is withdrawn and its unpaid orders are flagged for review. Requires WordPress 6.2 and WooCommerce 8.0.
