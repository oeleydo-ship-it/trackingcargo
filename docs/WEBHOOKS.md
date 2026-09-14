# Webhooks

CargoFlow can notify an external system when certain events happen, by
sending an HTTP `POST` request to a URL you configure under
**Settings → Webhooks**.

## Configuring an endpoint

1. Go to **Settings → Webhooks** and click **New endpoint**.
2. Enter an `https://` URL and pick which event types it should receive.
3. On creation, a **secret** is shown once in a banner at the top of the
   page. Copy it immediately — it is never shown again, and is not
   retrievable through the API or the endpoint listing (it is a hashed/
   hidden field on the model).

If you lose the secret, delete the endpoint and create a new one.

## Event types

| Event type                 | Fired when                                                  |
|-----------------------------|--------------------------------------------------------------|
| `shipment.status_changed`   | A shipment's status changes (any transition).                 |
| `invoice.paid`               | An invoice's balance reaches zero (fully paid).                |
| `*`                          | Wildcard — receive every event type above.                    |

Every delivery is sent **after** the triggering database transaction has
committed. A rejected or invalid action (a validation failure, a blocked
transition) never reaches this point, so it never sends anything — there
are no "phantom" webhooks for actions that didn't actually happen.

## Request format

```
POST <your configured URL>
Content-Type: application/json
X-CargoFlow-Event: shipment.status_changed
X-CargoFlow-Delivery: 5b1f2c3e-....  (a UUID, unique per delivery attempt)
X-CargoFlow-Signature: sha256=<hex-encoded HMAC-SHA256>

{"shipment_id": 123, "tracking_number": "GLX-DXB-00000001", ...}
```

The body is the exact JSON payload shown in the request — no pretty-printing,
no trailing newline.

## Verifying the signature

The signature is an **HMAC-SHA256** of the **raw request body bytes**,
keyed with your endpoint's secret, hex-encoded, and prefixed with `sha256=`.

To verify a delivery, recompute the same value over the exact bytes you
received (before any JSON parsing/re-serialization, since re-encoding can
change byte-for-byte formatting) and compare using a constant-time
comparison function.

**PHP:**

```php
$signature = $_SERVER['HTTP_X_CARGOFLOW_SIGNATURE']; // "sha256=...."
$expected = 'sha256=' . hash_hmac('sha256', file_get_contents('php://input'), $secret);

if (! hash_equals($expected, $signature)) {
    http_response_code(401);
    exit;
}
```

**Node.js:**

```js
const crypto = require('crypto');

const expected = 'sha256=' + crypto.createHmac('sha256', secret).update(rawBody).digest('hex');

if (!crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(signature))) {
    return res.status(401).end();
}
```

This exact algorithm is reproduced and asserted against in
`tests/Feature/Webhooks/WebhookDeliveryTest.php::test_a_shipment_status_change_sends_a_correctly_signed_webhook`
— the test independently recomputes the signature from the captured
request body and the endpoint's known secret, and asserts it matches the
`X-CargoFlow-Signature` header the app actually sent.

## Retries

A failed delivery (a non-2xx response, a timeout, or a connection error) is
retried automatically with backoff: 10s, 30s, 60s, 5m, then 15m — five
attempts total. After the fifth failed attempt the delivery is marked
`failed` permanently (visible on the Settings → Webhooks page, including
the attempt count and the last HTTP response received) and every user
holding the `company-admin` role is notified. There is no manual "retry"
button in this version — recreate the delivery by re-triggering the
underlying domain event, or fix the endpoint and wait for the next natural
event.

## Idempotency

Each delivery carries a unique `X-CargoFlow-Delivery` id. If your endpoint
might receive the same delivery more than once (e.g. you process it, but
your `200 OK` response is lost in transit and CargoFlow retries), use this
id to detect and ignore duplicates on your side.
