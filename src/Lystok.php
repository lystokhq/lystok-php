<?php

declare(strict_types=1);

namespace Lystok;

/**
 * The Lystok API client.
 *
 * ```php
 * $lystok = new Lystok\Lystok(); // reads LYSTOK_API_KEY
 * $email = $lystok->emails->send([
 *     'from' => 'Shop <orders@shop.example>',
 *     'to' => 'olena@example.com',
 *     'subject' => 'Your order #1042',
 *     'html' => '<p>Thank you!</p>',
 * ]);
 * ```
 *
 * JSON objects come back as associative arrays, with keys as the API sends them (`created_at`).
 */
final class Lystok
{
    public const VERSION = '0.1.0';
    private const DEFAULT_BASE_URL = 'https://api.lystok.com';
    private const RETRY_STATUSES = [408, 429, 500, 502, 503, 504];

    public readonly Emails $emails;
    public readonly Contacts $contacts;
    public readonly Webhooks $webhooks;

    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly float $timeout;
    private readonly int $maxRetries;
    /** @var callable(string, string, array<string, string>, ?string, float): array{0: int, 1: array<string, string>, 2: string} */
    private $transport;

    /**
     * @param string|null $apiKey An API key from Settings → For developers; `LYSTOK_API_KEY` when not given.
     * @param array{base_url?: string, timeout?: float, max_retries?: int, transport?: callable} $options
     *     `base_url` (default `https://api.lystok.com`); `timeout`: how long one attempt may wait,
     *     in seconds (30); `max_retries`: how many times to try again after a network error, a
     *     timeout, 429 or 5xx (2) — safe for emails too, every send carries an `Idempotency-Key`;
     *     `transport`: your own HTTP sender, see {@see Lystok::curl()}.
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        $key = $apiKey ?? (getenv('LYSTOK_API_KEY') ?: null);
        if ($key === null || $key === '') {
            throw new \InvalidArgumentException("Missing API key: pass it to new Lystok('lk_live_...') or set LYSTOK_API_KEY");
        }
        $this->apiKey = $key;
        $this->baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->timeout = (float) ($options['timeout'] ?? 30.0);
        $this->maxRetries = max(0, (int) ($options['max_retries'] ?? 2));
        $this->transport = $options['transport'] ?? self::curl(...);
        $this->emails = new Emails($this);
        $this->contacts = new Contacts($this);
        $this->webhooks = new Webhooks();
    }

    /**
     * @internal
     * @return mixed The decoded answer, or null for an empty one.
     */
    public function request(string $method, string $path, mixed $body = null, ?string $idempotencyKey = null, ?float $timeout = null): mixed
    {
        $url = "{$this->baseUrl}/v1{$path}";
        $headers = [
            'Authorization' => "Bearer {$this->apiKey}",
            'Accept' => 'application/json',
            'User-Agent' => 'lystok-php/' . self::VERSION,
        ];
        $data = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $data = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        $wait = $timeout ?? $this->timeout;

        for ($attempt = 0; ; $attempt++) {
            try {
                [$status, $resHeaders, $raw] = ($this->transport)($method, $url, $headers, $data, $wait);
            } catch (LystokException $error) {
                if ($error->status !== 0 || $attempt >= $this->maxRetries) {
                    throw $error;
                }
                $this->pause($attempt, null);
                continue;
            }
            if ($status < 300) {
                return $raw === '' ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            }
            // A DELETE whose first try went through before the network failed finds nothing.
            if ($status === 404 && $method === 'DELETE' && $attempt > 0) {
                return null;
            }
            $error = self::errorFrom($status, $resHeaders, $raw);
            $retry = in_array($status, self::RETRY_STATUSES, true) || $error->errorCode === 'idempotency_in_progress';
            if (!$retry || $attempt >= $this->maxRetries) {
                throw $error;
            }
            $this->pause($attempt, $error->retryAfter);
        }
    }

    private function pause(int $attempt, ?float $retryAfter): void
    {
        $seconds = $retryAfter !== null
            ? min($retryAfter, 60.0)
            : min(0.5 * 2 ** $attempt, 8.0) * (0.75 + mt_rand() / mt_getrandmax() * 0.5);
        usleep((int) ($seconds * 1_000_000));
    }

    /** @param array<string, string> $headers Lowercase names. */
    private static function errorFrom(int $status, array $headers, string $raw): LystokException
    {
        $parsed = json_decode($raw, true);
        $e = is_array($parsed) && is_array($parsed['error'] ?? null) ? $parsed['error'] : [];
        $retryAfter = isset($headers['retry-after']) && is_numeric($headers['retry-after']) && (float) $headers['retry-after'] > 0
            ? (float) $headers['retry-after']
            : null;
        return new LystokException(
            status: $status,
            errorCode: is_string($e['code'] ?? null) ? $e['code'] : ($status >= 500 ? 'server_error' : 'http_error'),
            message: is_string($e['message'] ?? null) ? $e['message'] : "The Lystok API answered {$status}",
            params: is_array($e['params'] ?? null) ? $e['params'] : null,
            issues: is_array($e['issues'] ?? null) ? $e['issues'] : null,
            requestId: is_string($e['requestId'] ?? null) ? $e['requestId'] : ($headers['x-request-id'] ?? null),
            retryAfter: $retryAfter,
        );
    }

    /**
     * The default HTTP sender. A transport of your own takes the same arguments, returns
     * `[status, headers with lowercase names, body]`, and throws a {@see LystokException} with
     * status 0 and code `timeout` or `connection_error` when no answer came.
     *
     * @param array<string, string> $headers
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    public static function curl(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $resHeaders = [];
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($headers), $headers),
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) (min($timeout, 10.0) * 1000),
            CURLOPT_HEADERFUNCTION => function ($_, string $line) use (&$resHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $resHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($handle);
        if ($raw === false) {
            $timedOut = curl_errno($handle) === CURLE_OPERATION_TIMEDOUT;
            throw new LystokException(
                status: 0,
                errorCode: $timedOut ? 'timeout' : 'connection_error',
                message: $timedOut ? "No answer from the Lystok API within {$timeout} s" : 'Could not reach the Lystok API: ' . curl_error($handle),
            );
        }
        return [(int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $resHeaders, (string) $raw];
    }

    /** @internal A random UUID v4 for Idempotency-Key. */
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
