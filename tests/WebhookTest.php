<?php

declare(strict_types=1);

use Lystok\Lystok;
use Lystok\Webhook;
use Lystok\WebhookVerificationException;

// Signed by the Lystok platform itself, so passing them means accepting exactly what it sends.
$fixtures = json_decode(file_get_contents(__DIR__ . '/fixtures/webhooks.json'), true, 512, JSON_THROW_ON_ERROR);
$valid = array_values(array_filter($fixtures['cases'], fn ($c) => $c['name'] === 'valid'))[0];

foreach ($fixtures['cases'] as $case) {
    test("webhook: {$case['name']}", function () use ($case) {
        if ($case['valid']) {
            $event = Webhook::verify($case['payload'], $case['headers'], $case['secret'], now: $case['now']);
            assertSame(json_decode($case['payload'], true), $event);
        } else {
            assertThrows(WebhookVerificationException::class, fn () => Webhook::verify($case['payload'], $case['headers'], $case['secret'], now: $case['now']));
        }
    });
}

test('webhook: header names in any case, arrays of values and $_SERVER', function () use ($valid) {
    $upper = array_combine(array_map('strtoupper', array_keys($valid['headers'])), $valid['headers']);
    $lists = array_map(fn ($v) => [$v], $valid['headers']); // Symfony and PSR-7
    $server = [];
    foreach ($valid['headers'] as $k => $v) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
    }
    foreach ([$upper, $lists, $server] as $headers) {
        assertSame('email.delivered', Webhook::verify($valid['payload'], $headers, $valid['secret'], now: $valid['now'])['type']);
    }
});

test('webhook: a secret that is not base64', function () use ($valid) {
    assertThrows(WebhookVerificationException::class, fn () => Webhook::verify($valid['payload'], $valid['headers'], 'whsec_not base64!', now: $valid['now']));
});

test('webhook: on the client', function () use ($valid) {
    $event = (new Lystok('lk_test_x'))->webhooks->verify($valid['payload'], $valid['headers'], $valid['secret'], now: $valid['now']);
    assertSame('email.delivered', $event['type']);
});
