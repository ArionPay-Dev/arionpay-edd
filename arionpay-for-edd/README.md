# ArionPay for Easy Digital Downloads

Lets your customers pay for downloads with Bitcoin, USDT, Litecoin, Ethereum, TRON and more through [ArionPay](https://arionpay.com). The money goes straight to your own wallet: ArionPay never holds it.

Tested on WordPress 7.1.4 with Easy Digital Downloads 3.7.3 and PHP 8.3: checkout, payment, late payment, payment tolerance, changed order total, "Pay now" from the receipt, and webhook checks.

## What you need

- WordPress 6.0 or newer, Easy Digital Downloads 3.x, PHP 7.4 or newer
- An ArionPay account with a store, and a wallet connected to it for real payments (test mode works without a wallet)
- Your site on a public `https://` address, so ArionPay can tell it when a payment arrives

## Install

1. In WordPress, go to **Plugins > Add New > Upload Plugin**, choose `arionpay-for-edd.zip`, install and activate.
2. Go to **Downloads > Settings > Payments**:
   - **General:** tick **ArionPay (crypto)** under Active Gateways. Keep **Test Mode** on for now and save.
   - **ArionPay:** fill in the three keys (see below) and save.

## Where the keys come from

| Setting | Where in the ArionPay dashboard | Starts with |
| --- | --- | --- |
| API key | Open your store, **API key** | `pk_` |
| API secret | Open your store, **API secret** (asks for your authenticator code) | `sk_` |
| Webhook signing secret | **Webhooks** page, **signing secret** | `whsec_` |

The secrets are never shown again on the settings page; leave the fields empty to keep them.

You do not need to paste any address into ArionPay: each invoice tells ArionPay to send its payment updates to `https://your-site/index.php?edd-listener=arionpay` (shown on the settings page). You can also paste it on the ArionPay **Webhooks** page to use **Send test webhook** there.

Also check, in your ArionPay store settings, that **Old ArionPay (v1) integration compatibility** is off.

## Try it first

1. With EDD **Test Mode** on, buy a download and choose **Crypto (Bitcoin, USDT and more)**.
2. On the ArionPay page, choose a coin and use the test payment buttons. No real money moves.
3. The order should change from **Pending** to **Completed**, with the note "[TEST - no real money] ArionPay invoice ... is paid", and the customer gets the download email.
4. Turn EDD **Test Mode** off before you take real payments.

## How orders change

- At checkout the order is created as **Pending**, and the customer goes to the ArionPay page.
- When ArionPay confirms the full payment, the order is **Completed** (EDD sends the receipt and download links) and the blockchain transaction id is saved on the order.
- The order is completed only if the paid invoice still matches it: same currency and the same total. If the order was edited after checkout, it is not completed and a note explains why.
- A payment that is not exact goes to **Payment Radar** in your ArionPay dashboard. A small difference inside your store's payment tolerance (default 1%) is accepted automatically. For anything else you decide; if you accept it, the order is completed here too, with a note.
- A late payment (after the invoice expired) still completes the order, with a note.
- If more money arrives after an order was completed, you get a note asking you to check your wallet.
- After the ArionPay page, the customer lands on the order receipt (EDD asks guests to confirm their email first). While unpaid, it shows a **Pay now with crypto** button that reopens the open invoice or makes a new one.
- Coming back from the payment page never completes an order.

## Good to know

- ArionPay shows only when your store currency is one ArionPay accepts: USD, EUR, GBP, CAD, AUD, CHF, JPY, TRY, AED, INR, BRL, RUB, CNY, SGD, HKD, KRW, MXN, PLN, SEK, NOK or ZAR.
- Subscriptions (Recurring Payments) are not supported: crypto payments are one-off.
- Refunds are made from your own wallet. The plugin does not send refunds.
- The plugin sends ArionPay the order number, amount, currency and your site name. It does not send the customer's name or email.
- **Debug log** (ArionPay settings) writes to **Downloads > Tools > Debug Log**. Keys are never written.
