# Blokbay crypto payments for EC-CUBE 4.2 / 4.3

Accept Bitcoin (BTC), USDC, USDT and ETH in your EC-CUBE shop. At checkout the customer is sent to the secure Blokbay payment
page, pays from any wallet, and the money goes straight to your own wallet. Blokbay never holds your funds.

Pricing: 1% of each payment, withheld automatically. No monthly fee, no setup fee.
Japanese instructions: README.ja.md

## Requirements

- EC-CUBE 4.2 or 4.3 (plugin code `BlokbayPayment`).
- Shop currency **USD**. The Blokbay API works in USD only and the plugin does not convert currencies. In `.env` set
  `ECCUBE_CURRENCY=USD`. With another currency (for example JPY) the payment method stays hidden at checkout and the
  settings page shows a warning.
- A public https address for the webhook.

## Install

1. Admin: Owner's Store > Plugin > Installed plugins > upload the file `blokbay-eccube.zip`
   (or from the command line: `bin/console eccube:plugin:install --path=blokbay-eccube.zip`).
2. Enable "Blokbay crypto payments" (`bin/console eccube:plugin:enable --code=BlokbayPayment`).
3. Open the plugin settings (Owner's Store > Plugin > Installed plugins > Blokbay > settings icon) and paste the secret key,
   the public key and the webhook signing secret from the Blokbay dashboard (Payments from your site). The API base URL can stay empty.
4. In the Blokbay dashboard set the webhook URL `https://your-shop/blokbay_payment/webhook` (shown on the settings page).
5. The method "Crypto (Blokbay)" is added to every delivery method. You can change this in Settings > Shop settings > Delivery methods.

## How it works

- The order goes to "Pending payment" (決済処理中) and the customer is redirected to the Blokbay payment page.
- A signed `payment.paid` webhook (header `Blokbay-Signature: t=<unix>,v1=<HMAC-SHA256>`) marks the order "Paid" (入金済み).
  The amount and the currency must match the order, otherwise the order is not marked as paid and a note is added.
- When the customer comes back the plugin asks the API for the status, so the order is right even if the webhook is late.
  Still pending: the order is placed as "New" (新規受付) and the webhook marks it paid later.
- `payment.expired` cancels the order only if that payment is still the current one: an old attempt never cancels a new one.
- A payment that arrives after the expiry reopens the cancelled order and marks it paid (the funds are already in your wallet);
  check the stock, as written in the shop memo of the order.
- Cancel on the payment page, or an error while creating the payment: the stock is released and the cart is kept.
- The same event delivered twice changes nothing. Payments are created with an Idempotency-Key per order, amount and attempt.

## Support

mail@blokbay.com, documentation https://blokbay.com/docs/api

License: MIT. Author: Blokbay.
