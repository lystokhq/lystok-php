<?php

declare(strict_types=1);

namespace Lystok;

/**
 * Every failure of a Lystok API call.
 *
 * `$status` is the HTTP status, or 0 when no answer came (`$errorCode` is then `timeout` or
 * `connection_error`). `getCode()` returns the same status.
 */
final class LystokException extends \RuntimeException
{
    /**
     * @param array<string, mixed>|null $params Values behind the message, such as a limit.
     * @param list<array{path: string, message: string}>|null $issues Field problems of a validation error.
     */
    public function __construct(
        public readonly int $status,
        /** `validation`, `not_found`, `plan_limit`, `access_ended`, `rate_limited`, `timeout`, `connection_error`, … */
        public readonly string $errorCode,
        string $message,
        public readonly ?array $params = null,
        public readonly ?array $issues = null,
        /** Quote it when you write to support. */
        public readonly ?string $requestId = null,
        /** Seconds the API asked to wait before trying again. */
        public readonly ?float $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }
}
