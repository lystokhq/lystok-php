<?php

declare(strict_types=1);

namespace Lystok;

/**
 * Needs a key with full access.
 *
 * @phpstan-type FieldValue string|int|float|bool
 * @phpstan-type Contact array{
 *     id: string, email: string, first_name: ?string, last_name: ?string, phone: ?string,
 *     locale: ?string, status: 'subscribed'|'unsubscribed'|'bounced'|'complained',
 *     fields: array<string, FieldValue>, lists: list<string>, tags: list<string>, source: string,
 *     consent_source: ?string, consent_at: ?string, created_at: string, updated_at: string,
 * }
 */
final class Contacts
{
    /** @internal */
    public function __construct(private readonly Lystok $client)
    {
    }

    /**
     * Adds a person, or updates them when the address is already there. Safe to repeat.
     * `consent_source` is required for a new contact.
     *
     * @param array{email: string, first_name?: string, last_name?: string, phone?: string,
     *     locale?: ?string, fields?: array<string, FieldValue|null>, lists?: list<string>,
     *     tags?: list<string>, consent_source?: string} $contact Your own `fields` by key; null
     *     removes a value.
     * @return Contact
     */
    public function upsert(array $contact, ?float $timeout = null): array
    {
        return $this->client->request('POST', '/contacts', $contact, timeout: $timeout);
    }

    /**
     * By id or by email address.
     *
     * @return Contact
     */
    public function get(string $idOrEmail, ?float $timeout = null): array
    {
        return $this->client->request('GET', '/contacts/' . rawurlencode($idOrEmail), timeout: $timeout);
    }

    /**
     * Changes a person, found by id or by email address.
     *
     * @param array{email?: string, first_name?: string, last_name?: string, phone?: string,
     *     locale?: ?string, fields?: array<string, FieldValue|null>, lists?: list<string>,
     *     tags?: list<string>, consent_source?: string, remove_lists?: list<string>,
     *     remove_tags?: list<string>, status?: 'unsubscribed'} $changes `status: unsubscribed`
     *     unsubscribes the person from your newsletters.
     * @return Contact
     */
    public function update(string $idOrEmail, array $changes, ?float $timeout = null): array
    {
        return $this->client->request('PATCH', '/contacts/' . rawurlencode($idOrEmail), $changes, timeout: $timeout);
    }

    /** Deletes a person, found by id or by email address. */
    public function delete(string $idOrEmail, ?float $timeout = null): void
    {
        $this->client->request('DELETE', '/contacts/' . rawurlencode($idOrEmail), timeout: $timeout);
    }
}
