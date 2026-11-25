<?php

/*
 * Against a real Lystok API; skipped unless LYSTOK_LIVE=1. Needs a full-access key and a sender on a
 * verified domain of that account:
 *
 *   LYSTOK_LIVE=1 LYSTOK_API_KEY=lk_... LYSTOK_BASE_URL=http://localhost:3200 \
 *   LYSTOK_TEST_FROM=hello@your-domain LYSTOK_TEST_TO=you@your-domain php tests/run.php
 */

declare(strict_types=1);

use Lystok\Lystok;
use Lystok\LystokException;

function live(): array
{
    if (getenv('LYSTOK_LIVE') !== '1') {
        skip('set LYSTOK_LIVE=1 to run');
    }
    $lystok = new Lystok(null, ['base_url' => getenv('LYSTOK_BASE_URL') ?: 'https://api.lystok.com']);
    $email = fn (string $subject) => ['from' => getenv('LYSTOK_TEST_FROM'), 'to' => getenv('LYSTOK_TEST_TO'), 'subject' => $subject, 'text' => 'lystok-php live test'];
    return [$lystok, $email];
}

test('live: send and read back', function () {
    [$lystok, $email] = live();
    $sent = $lystok->emails->send([...$email('lystok-php live test'), 'tags' => ['sdk' => 'php']]);
    $got = $lystok->emails->get($sent['id']);
    assertSame([$sent['id'], getenv('LYSTOK_TEST_TO'), ['sdk' => 'php']], [$got['id'], $got['to'], $got['tags']]);
});

test('live: once per idempotency key', function () {
    [$lystok, $email] = live();
    $key = 'lystok-php-' . hrtime(true);
    $first = $lystok->emails->send($email('lystok-php idempotency'), idempotencyKey: $key);
    $second = $lystok->emails->send($email('lystok-php idempotency'), idempotencyKey: $key);
    assertSame($first['id'], $second['id']);
});

test('live: batch', function () {
    [$lystok, $email] = live();
    $res = $lystok->emails->sendBatch([$email('lystok-php batch 1'), $email('lystok-php batch 2')]);
    assertSame(2, count($res['data']));
});

test('live: a validation error', function () {
    [$lystok] = live();
    $err = assertThrows(LystokException::class, fn () => $lystok->emails->send(['from' => getenv('LYSTOK_TEST_FROM'), 'to' => 'not-an-address', 'subject' => 's', 'text' => 't']));
    assertSame([400, 'validation'], [$err->status, $err->errorCode]);
    assertTrue((bool) $err->requestId, 'a request id');
});

test('live: a contact', function () {
    [$lystok] = live();
    $address = 'lystok-php-' . hrtime(true) . '@example.com';
    $made = $lystok->contacts->upsert(['email' => $address, 'first_name' => 'Olena', 'tags' => ['sdk-test'], 'consent_source' => 'lystok-php live test']);
    assertSame(['subscribed', ['sdk-test']], [$made['status'], $made['tags']]);
    $changed = $lystok->contacts->update($address, ['last_name' => 'Shevchenko', 'remove_tags' => ['sdk-test']]);
    assertSame(['Shevchenko', []], [$changed['last_name'], $changed['tags']]);
    assertSame($address, $lystok->contacts->get($made['id'])['email']);
    $lystok->contacts->delete($made['id']);
    $err = assertThrows(LystokException::class, fn () => $lystok->contacts->get($address));
    assertSame(404, $err->status);
});
