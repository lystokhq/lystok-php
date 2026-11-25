<?php

declare(strict_types=1);

namespace Lystok;

/**
 * Checking that a webhook came from Lystok (the Standard Webhooks scheme): the headers
 * `webhook-id`, `webhook-timestamp` and `webhook-signature` sign `id.timestamp.body` with
 * HMAC-SHA256.
 */
final class Webhook
{
    private const PREFIX = 'whsec_';

    /**
     * Checks a webhook and returns its event.
     *
     * Pass the **raw body**, exactly as received, before any JSON parsing
     * (`file_get_contents('php://input')`, `$request->getContent()` in Laravel and Symfony,
     * `(string) $request->getBody()` in PSR-7).
     *
     * @param array<string, string|list<string>> $headers Any of `getallheaders()`, `$_SERVER`,
     *     `$request->headers->all()` (Laravel, Symfony) or `$request->getHeaders()` (PSR-7).
     * @param string $secret The webhook's signing secret (`whsec_...`, from its menu → Signing secret).
     * @param int $tolerance How far the timestamp may be from now, in seconds.
     * @param int|null $now The current time as a Unix timestamp; for tests.
     * @return array{type: string, created_at: string, data: array<string, mixed>} `type` is one of
     *     `email.sent`, `email.delivered`, `email.bounced`, `email.complained`, `email.failed`,
     *     `email.suppressed`, `email.opened`, `email.clicked`, `contact.unsubscribed`.
     * @throws WebhookVerificationException When the signature or timestamp is wrong.
     */
    public static function verify(string $payload, array $headers, string $secret, int $tolerance = 300, ?int $now = null): array
    {
        $id = self::header($headers, 'webhook-id');
        $timestamp = self::header($headers, 'webhook-timestamp');
        $signatures = self::header($headers, 'webhook-signature');
        if ($id === null || $id === '' || $timestamp === null || $timestamp === '' || $signatures === null || $signatures === '') {
            throw new WebhookVerificationException('Missing webhook-id, webhook-timestamp or webhook-signature header');
        }
        if (preg_match('/^\d+$/D', $timestamp) !== 1) {
            throw new WebhookVerificationException('Invalid webhook-timestamp header');
        }
        if (abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            throw new WebhookVerificationException('Webhook timestamp is too far from now');
        }

        $key = base64_decode(str_starts_with($secret, self::PREFIX) ? substr($secret, strlen(self::PREFIX)) : $secret, true);
        if ($key === false || $key === '') {
            throw new WebhookVerificationException('Invalid webhook secret');
        }

        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$payload}", $key, true));
        foreach (explode(' ', $signatures) as $entry) {
            [$version, $value] = array_pad(explode(',', $entry, 2), 2, '');
            if ($version === 'v1' && $value !== '' && hash_equals($expected, $value)) {
                $event = json_decode($payload, true);
                if (!is_array($event)) {
                    throw new WebhookVerificationException('The webhook body is not a JSON object');
                }
                return $event;
            }
        }
        throw new WebhookVerificationException('No matching webhook signature');
    }

    /** @param array<string, mixed> $headers */
    private static function header(array $headers, string $name): ?string
    {
        $wanted = [$name, 'http_' . str_replace('-', '_', $name)];
        foreach ($headers as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $wanted, true)) {
                return is_array($value) ? implode(' ', $value) : (string) $value;
            }
        }
        return null;
    }
}
