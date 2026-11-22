# Blokbay Crypto Payments for Magento 2 (2.4.6 to 2.4.9)

Accept Bitcoin, USDC, USDT and ETH. Funds go from the buyer straight to your wallet; Blokbay withholds 1% of each payment, no monthly fee.
Package `blokbay/module-payment`, module `Blokbay_Payment`, license MIT (see LICENSE.txt).

## Install
Composer: `composer require blokbay/module-payment`, or copy this folder to `app/code/Blokbay/Payment`. Then:

    bin/magento module:enable Blokbay_Payment
    bin/magento setup:upgrade
    bin/magento setup:di:compile
    bin/magento cache:flush

## Configure
1. Blokbay dashboard > **Payments on your site**: create the **secret key** (shown once), copy the **public key** and the **webhook signing secret**.
2. Stores > Configuration > Sales > Payment Methods > **Crypto (Blokbay)**: enable, paste the values (stored encrypted), save.
3. In Blokbay set the webhook URL `https://your-store/blokbay/webhook/index` and press **Send a test event** (answer `pong`).
4. Try a small real order. Order currency must be **USD**.

## How it works
The order is placed as *Pending payment*; the customer is redirected (`blokbay/payment/redirect`) to the Blokbay payment page and returns to the success page. The signed webhook `payment.paid` (HMAC SHA-256, constant time, 5 minute tolerance, public key, payment id and amount checked) creates an offline invoice and moves the order to *Processing*; `payment.expired` cancels a pending order. If the webhook is late, the success page asks the API.
