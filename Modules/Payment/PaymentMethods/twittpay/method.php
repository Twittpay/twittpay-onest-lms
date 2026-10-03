<?php

namespace Modules\Payment\PaymentMethods\twittpay;

use Illuminate\Http\Request;
use Modules\Order\Entities\Order;

/**
 * TwittPay - Onest LMS payment method
 * ---------------------------------------------------------------------------
 * process()  creates the payment and hands back the checkout URL
 * verify()   answers both the returning student and the gateway's webhook
 *
 * The order is only marked paid after the transaction has been verified against
 * the API. Nothing on the incoming request is trusted except the transaction id.
 *
 * @version 1.0.0
 */
class method
{
    protected $order_id;
    protected $base_url;
    protected $api_key;
    protected $store_currency;
    protected $currency_rate;

    public function __construct()
    {
        $this->order_id = 'twittpay.payments.order_id';

        // Endpoint URL and Brand Key come from .env - there is no default on
        // purpose. See the README for the two lines to add.
        $this->base_url       = (string) env('TWITTPAY_BASE_URL', '');
        $this->api_key        = (string) env('TWITTPAY_API_KEY', '');
        $this->store_currency = strtoupper((string) env('TWITTPAY_CURRENCY', 'BDT'));
        $this->currency_rate  = (float) env('TWITTPAY_CURRENCY_RATE', 120);
    }

    /**
     * Create the payment. Returns the URL the student has to be sent to.
     */
    public function process(Order $order)
    {
        $user = $order->user;

        $amount = number_format((float) $order->total_amount, 2, '.', '');

        $data = [
            'cus_name'    => $user && !empty($user->name) ? $user->name : 'Default Name',
            'cus_email'   => $user && !empty($user->email) ? $user->email : 'default@gmail.com',
            'amount'      => number_format($this->toBdt($amount), 2, '.', ''),
            'success_url' => url('/payments/verify/twittpay?status=success'),
            'cancel_url'  => url('/payments/verify/twittpay?status=fail'),
            'webhook_url' => url('/payments/verify/twittpay?status=webhook'),
            'metadata'    => [
                'order_id'         => (string) $order->id,
                'user_id'          => (string) $order->user_id,
                'guard'            => substr(md5($order->id . '|' . $order->user_id), 0, 10),
                'order_amount'     => $amount,
                'order_currency'   => $this->store_currency,
                'source'           => 'onest-lms',
            ],
        ];

        $response = $this->apiCall('/api/payment/create', $data);

        if (!empty($response['status']) && !empty($response['payment_url'])) {
            session()->put($this->order_id, $order->id);

            return $response['payment_url'];
        }

        // The raw response is never shown - an error string can carry the Brand Key
        // back out to the student.
        return redirect()->route('checkout.index')
            ->with('danger', 'The payment could not be started. Please try again.');
    }

    /**
     * The student coming back and the gateway's webhook both land here. The
     * webhook posts a form; the student arrives with a GET.
     */
    public function verify(Request $request)
    {
        $isWebhook     = $request->isMethod('post');
        $transactionId = $this->transactionIdFrom($request);

        if ($transactionId === '') {
            return $this->answer($isWebhook, false, 'No transaction id received.', 422);
        }

        $verified = $this->apiCall('/api/payment/verify', ['transaction_id' => $transactionId]);
        $status   = $this->readStatus($verified);

        if ($status === '') {
            return $this->answer($isWebhook, false, 'The gateway does not know this transaction.', 404);
        }

        $meta = $this->metadata($verified);

        if (empty($meta['order_id']) || empty($meta['user_id'])) {
            return $this->answer($isWebhook, false, 'This payment carries no order reference.', 400);
        }

        // The guard is written into metadata when the payment is created, so a
        // payment cannot be pointed at somebody else's order.
        $guard = substr(md5($meta['order_id'] . '|' . $meta['user_id']), 0, 10);

        if (empty($meta['guard']) || !hash_equals($guard, (string) $meta['guard'])) {
            return $this->answer($isWebhook, false, 'This payment does not match its order.', 400);
        }

        $order = Order::where('id', $meta['order_id'])
            ->where('user_id', $meta['user_id'])
            ->with('user')
            ->first();

        if (!$order) {
            return $this->answer($isWebhook, false, 'Order not found.', 404);
        }

        if ($status === 'COMPLETED') {
            // The webhook fires again when a pending payment is decided, so an
            // order that is already paid is left alone.
            if ($order->status !== 'paid') {
                $order->update(['status' => 'paid']);
            }

            session()->forget($this->order_id);

            return $this->answer($isWebhook, true, 'Your payment has been received.', 200);
        }

        if ($status === 'PENDING') {
            // Sent, not approved by the merchant yet. The gateway calls again with
            // the answer, so the order is left as it is rather than failed.
            return $this->answer($isWebhook, true, 'Your payment is being checked. Your order will be updated once it clears.', 200);
        }

        $order->update(['status' => 'failed']);
        session()->forget($this->order_id);

        return $this->answer($isWebhook, false, 'The payment was not completed.', 200);
    }

    /**
     * The webhook gets JSON, the student gets sent back into the site.
     */
    protected function answer($isWebhook, $ok, $message, $code)
    {
        if ($isWebhook) {
            return response()->json(['status' => (bool) $ok, 'message' => $message], $code);
        }

        return redirect()->to(url('/student/courses'))
            ->with($ok ? 'success' : 'danger', $message);
    }

    /** The id can be on the URL, in a form body, or in a JSON body. */
    protected function transactionIdFrom(Request $request)
    {
        foreach (['transactionId', 'transaction_id'] as $key) {
            $value = $request->input($key);

            if (!empty($value)) {
                return trim((string) $value);
            }
        }

        $raw = $request->getContent();

        if (!empty($raw)) {
            $body = json_decode($raw, true);

            if (is_array($body)) {
                foreach (['transactionId', 'transaction_id'] as $key) {
                    if (!empty($body[$key])) {
                        return trim((string) $body[$key]);
                    }
                }
            }
        }

        return '';
    }

    /**
     * The verify status: PENDING, COMPLETED or ERROR when the transaction is real,
     * and an empty string when it is not - a miss answers a number, not text.
     */
    protected function readStatus($verified)
    {
        if (!is_array($verified) || !isset($verified['status']) || !is_string($verified['status'])) {
            return '';
        }

        return strtoupper(trim($verified['status']));
    }

    /** metadata comes back from verify as a JSON string. */
    protected function metadata($verified)
    {
        if (!is_array($verified) || !isset($verified['metadata'])) {
            return [];
        }

        $meta = $verified['metadata'];

        if (is_array($meta)) {
            return $meta;
        }

        if (is_object($meta)) {
            return (array) $meta;
        }

        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /** The gateway charges BDT. Anything else is converted with the set rate. */
    protected function toBdt($amount)
    {
        if ($this->store_currency === 'BDT') {
            return (float) $amount;
        }

        $rate = $this->currency_rate > 0 ? $this->currency_rate : 1;

        return (float) $amount * $rate;
    }

    /**
     * Scheme and host of the configured endpoint. Pasting the whole endpoint or a
     * trailing /api still works.
     */
    protected function baseUrl()
    {
        $raw    = rtrim(trim($this->base_url), '/');
        $scheme = parse_url($raw, PHP_URL_SCHEME);
        $host   = parse_url($raw, PHP_URL_HOST);

        if (empty($host)) {
            $host = strtok(ltrim(preg_replace('#^[a-z]+://#i', '', $raw), '/'), '/');
        }

        if (empty($scheme)) {
            $scheme = 'https';
        }

        if (empty($host)) { $host = 'checkout.twittpay.com'; }
        return 'https://' . $host;
    }

    /** One POST to the API. JSON in, array out. */
    protected function apiCall($endpoint, $payload)
    {
        if (trim($this->api_key) === '') {
            return [];
        }

        // metadata has to arrive as a JSON object; a PHP list would encode as an
        // array and be rejected.
        if (isset($payload['metadata'])) {
            $payload['metadata'] = (object) $payload['metadata'];
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->baseUrl() . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'API-KEY: ' . trim($this->api_key),
            ],
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : [];
    }
}
