<?php

declare(strict_types=1);

namespace Lystok;

/**
 * @phpstan-type SendEmailParams array{
 *     from: string,
 *     to: string,
 *     subject?: string,
 *     html?: string,
 *     text?: string,
 *     template_id?: string,
 *     data?: array<string, mixed>|object,
 *     locale?: 'uk'|'en'|'pl'|'de'|'es'|'fr'|'ru',
 *     reply_to?: string,
 *     headers?: array<string, string>,
 *     tags?: array<string, string>,
 *     tracking?: array{opens?: bool, clicks?: bool},
 *     attachments?: list<array{filename: string, content: string, content_type?: string}>,
 * }
 * @phpstan-type Email array{
 *     id: string, from: string, to: string, subject: string, template_id: ?string,
 *     state: 'queued'|'sent'|'delivered'|'bounced'|'complained'|'failed'|'suppressed',
 *     error: ?string, tags: array<string, string>, created_at: string, sent_at: ?string,
 *     delivered_at: ?string, bounced_at: ?string, complained_at: ?string, opened_at: ?string,
 *     clicked_at: ?string,
 * }
 */
final class Emails
{
    /** @internal */
    public function __construct(private readonly Lystok $client)
    {
    }

    /**
     * Sends one email. It is queued and usually sent within a second.
     *
     * @param SendEmailParams $email
     * @param string|null $idempotencyKey The same key within 24 hours gets the first answer back
     *     instead of a second email: use your order id, for example. Made up for you when not
     *     given, which protects this call's own retries.
     * @return array{id: string}
     */
    public function send(array $email, ?string $idempotencyKey = null, ?float $timeout = null): array
    {
        return $this->client->request('POST', '/emails', $email, $idempotencyKey ?? Lystok::uuid(), $timeout);
    }

    /**
     * Sends up to 100 emails in one request; all are accepted or none. Ids come in the same order.
     *
     * @param list<SendEmailParams> $emails
     * @return array{data: list<array{id: string}>}
     */
    public function sendBatch(array $emails, ?string $idempotencyKey = null, ?float $timeout = null): array
    {
        return $this->client->request('POST', '/emails/batch', $emails, $idempotencyKey ?? Lystok::uuid(), $timeout);
    }

    /**
     * What happened to an email.
     *
     * @return Email
     */
    public function get(string $id, ?float $timeout = null): array
    {
        return $this->client->request('GET', '/emails/' . rawurlencode($id), timeout: $timeout);
    }
}
