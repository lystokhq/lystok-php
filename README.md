# Lystok for PHP

The official library for the [Lystok](https://lystok.com) email API: send transactional email,
keep contacts in step and check webhooks. No dependencies beyond the `curl` and `json` extensions;
PHP 8.2+.

```sh
composer require lystokhq/lystok-php
```

## Send an email

Create an API key in Lystok → Settings → For developers, and verify your sender domain.

```php
use Lystok\Lystok;

$lystok = new Lystok(); // reads LYSTOK_API_KEY

$email = $lystok->emails->send([
    'from' => 'Shop <orders@shop.example>',
    'to' => 'olena@example.com',
    'subject' => 'Your order #1042',
    'html' => '<p>Thank you! We are packing your order.</p>',
]);
echo $email['id'];
```

`new Lystok('lk_live_…')` takes the key directly. Keys belong on your server: never put one in a
browser or a mobile app. Answers come back as associative arrays, with keys as the API sends them
(`created_at`).

### With a template

```php
$lystok->emails->send([
    'from' => 'Shop <orders@shop.example>',
    'to' => 'olena@example.com',
    'template_id' => '019ab3f2-…', // Templates → ⋯ → Copy ID
    'data' => ['first_name' => 'Olena', 'order' => ['number' => 1042]],
    'locale' => 'uk',
]);
```

An empty PHP array is sent as a JSON list (`[]`); where the API wants an object, such as empty
`data`, leave the key out or pass `new stdClass()`.

### Attachments, tags, tracking

```php
$lystok->emails->send([
    'from' => 'Shop <orders@shop.example>',
    'to' => 'olena@example.com',
    'subject' => 'Invoice #1042',
    'html' => '<p>Your invoice is attached.</p>',
    'attachments' => [[
        'filename' => 'invoice-1042.pdf',
        'content' => base64_encode(file_get_contents('invoice-1042.pdf')),
    ]],
    'tags' => ['type' => 'invoice'],
    'tracking' => ['opens' => true, 'clicks' => true],
]);
```

### Many at once, and what happened

```php
$res = $lystok->emails->sendBatch([
    ['from' => 'Shop <orders@shop.example>', 'to' => 'olena@example.com', 'template_id' => '…', 'data' => ['first_name' => 'Olena']],
    ['from' => 'Shop <orders@shop.example>', 'to' => 'taras@example.com', 'template_id' => '…', 'data' => ['first_name' => 'Taras']],
]); // up to 100; ids in the same order

$email = $lystok->emails->get($res['data'][0]['id']);
$email['state']; // 'queued' | 'sent' | 'delivered' | 'bounced' | 'complained' | 'failed' | 'suppressed'
```

## Sending once

Every send carries an `Idempotency-Key`, so the library's own retries never send an email twice.
To be safe across your own retries too (a crashed job, a repeated webhook), pass your own key —
the same key within 24 hours gets the first answer back:

```php
$lystok->emails->send($email, idempotencyKey: "order-{$order->id}");
```

## Contacts

Needs a key with full access.

```php
$contact = $lystok->contacts->upsert([
    'email' => 'olena@example.com',
    'first_name' => 'Olena',
    'lists' => ['Customers'],
    'tags' => ['bought-online'],
    'consent_source' => 'Newsletter checkbox at checkout', // required for a new contact
]);

$lystok->contacts->get('olena@example.com'); // by address or by id
$lystok->contacts->update('olena@example.com', ['remove_tags' => ['bought-online'], 'fields' => ['city' => 'Lviv']]);
$lystok->contacts->update('olena@example.com', ['status' => 'unsubscribed']);
$lystok->contacts->delete($contact['id']);
```

## Errors

Every failure is a `LystokException`:

```php
use Lystok\LystokException;

try {
    $lystok->emails->send($email);
} catch (LystokException $e) {
    $e->status;       // 400, 401, 403, 404, 409, 429, 5xx — or 0 when no answer came
    $e->errorCode;    // 'validation', 'not_found', 'plan_limit', 'access_ended', 'timeout', 'connection_error', …
    $e->getMessage(); // for people
    $e->issues;       // [['path' => 'to', 'message' => '…']] for validation errors
    $e->requestId;    // quote it when you write to support
}
```

## Retries and timeouts

Network errors, timeouts, `429` and `5xx` are tried again twice, waiting a little longer each time
(or as long as `Retry-After` says). Each try may take 30 seconds.

```php
$lystok = new Lystok(null, ['max_retries' => 4, 'timeout' => 10]);
$lystok->emails->get($id, timeout: 5);
```

## Webhooks

Lystok signs every webhook ([Standard Webhooks](https://www.standardwebhooks.com)). Check the
**raw body** before parsing it; `Webhook::verify` returns the event or throws
`WebhookVerificationException`. The secret (`whsec_…`) is in the webhook's menu → Signing secret.

Plain PHP:

```php
use Lystok\Webhook;
use Lystok\WebhookVerificationException;

try {
    $event = Webhook::verify(file_get_contents('php://input'), getallheaders(), getenv('LYSTOK_WEBHOOK_SECRET'));
} catch (WebhookVerificationException) {
    http_response_code(401);
    exit;
}
http_response_code(200); // answer within 10 seconds: hand slow work to a queue
if ($event['type'] === 'email.bounced') {
    // …
}
```

Laravel and Symfony:

```php
$event = Webhook::verify($request->getContent(), $request->headers->all(), config('services.lystok.webhook_secret'));
```

PSR-7: `Webhook::verify((string) $request->getBody(), $request->getHeaders(), $secret)`.

The same event can arrive twice: skip ones you have handled by the `webhook-id` header.

## Development

```sh
php tests/run.php
LYSTOK_LIVE=1 LYSTOK_API_KEY=lk_… LYSTOK_TEST_FROM=… LYSTOK_TEST_TO=… php tests/run.php   # also against the real API
```

The tests need nothing but PHP. `tests/fixtures/webhooks.json` holds webhook signatures made by the
Lystok platform itself; the same file is checked by every Lystok library.

## License

[MIT](LICENSE)
