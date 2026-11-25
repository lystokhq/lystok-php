<?php

declare(strict_types=1);

namespace Lystok;

final class Webhooks
{
    /**
     * See {@see Webhook::verify()}.
     *
     * @param array<string, string|list<string>> $headers
     * @return array{type: string, created_at: string, data: array<string, mixed>}
     * @throws WebhookVerificationException
     */
    public function verify(string $payload, array $headers, string $secret, int $tolerance = 300, ?int $now = null): array
    {
        return Webhook::verify($payload, $headers, $secret, $tolerance, $now);
    }
}
