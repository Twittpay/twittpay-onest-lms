===========================================================================
 TWITTPAY - Onest LMS payment method
===========================================================================

 WHERE IT GOES
   Extract this zip at your Onest LMS root - the folder that has Modules/ and
   artisan in it. One file lands in place:

     Modules/Payment/PaymentMethods/twittpay/method.php

   Keep the folder name exactly as it is. The namespace inside the file matches
   the folder, and Linux servers are case sensitive.

 SETUP - THREE STEPS

   1. Add these lines to your .env file:

        TWITTPAY_BASE_URL="https://checkout.twittpay.com"
        TWITTPAY_API_KEY="your api key"
        TWITTPAY_CURRENCY="BDT"
        TWITTPAY_CURRENCY_RATE="120"

      TWITTPAY_BASE_URL is your own gateway address - the API host shown on
      your gateway's developer page. There is no default on purpose.
      TWITTPAY_API_KEY comes from your gateway dashboard, under Brands.
      TWITTPAY_CURRENCY is the currency your course prices are in.
      TWITTPAY_CURRENCY_RATE is only used when that is not BDT.

   2. Open app/Http/Middleware/VerifyCsrfToken.php and add this to the $except
      array:

        '/payments/verify/twittpay',

      The gateway's server posts the webhook from outside the browser, so it has
      no CSRF token to send.

   3. Clear the config cache so the new .env values are picked up:

        php artisan config:clear

   If "TwittPay" does not show up at checkout, add it in the admin payment
   settings the same way your other payment methods are listed - Onest keeps that
   list in the database, and this zip does not touch your database.

 HOW IT WORKS
   * Choosing this method creates the payment and sends the student to the
     gateway's checkout page.
   * The student comes back to /payments/verify/twittpay with a GET.
   * The gateway's own server posts to the same URL.
   * Both verify the transaction against the API before the order is touched. A
     hand-typed URL does nothing.
   * COMPLETED marks the order paid, and only if it is not already paid - so the
     webhook arriving twice changes nothing the second time.
   * PENDING leaves the order alone. The student has sent the money and your
     merchant has not approved it. The gateway calls again with the answer, and
     that call marks the order paid. Do not ask the student to pay twice.

 CURRENCY
   The gateway charges BDT.

   * TWITTPAY_CURRENCY=BDT sends the price as it is.
   * Anything else is multiplied by TWITTPAY_CURRENCY_RATE, and the order's own
     amount and currency ride along in metadata.

 FIXES OVER THE ORIGINAL
   * The PipraPay version compared an Brand Key sent in a webhook header. This
     gateway's webhook is not signed and sends no key, so that check would have
     refused every real call. It is gone - verification against the API does the
     job, because a made-up transaction id simply does not verify.
   * The original marked an order failed on any status that was not "completed",
     including a pending one that had not been decided yet. Pending is now left
     alone.
   * The original echoed a raw <script> redirect for the browser case. This port
     uses a normal Laravel redirect with a flash message.
   * The original put the raw API response into an alert on failure. An error
     string can carry your Brand Key back out, so this port shows a plain message.
   * A guard value is written into metadata when the payment is created and
     checked on the way back, so a payment cannot be pointed at another order.

 CHECKED
   The PHP was checked with a lexer that balances braces only inside real PHP
   code. PHP itself was NOT run - there is no PHP binary on the machine this was
   built on, so php -l was never executed. Test it on a staging install first.
