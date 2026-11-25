<?php

declare(strict_types=1);

use Lystok\Lystok;
use Lystok\LystokException;

/** A transport that answers from a script and records what it was asked. */
final class FakeApi
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, json: mixed}> */
    public array $calls = [];

    /** @param list<array{0: int, 1?: mixed, 2?: array<string, string>}|LystokException> $answers */
    public function __construct(private array $answers)
    {
    }

    public function client(array $options = []): Lystok
    {
        return new Lystok('lk_live_test', ['base_url' => 'https://api.test', 'transport' => $this, ...$options]);
    }

    public function __invoke(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'json' => $body === null ? null : json_decode($body, true)];
        $answer = array_shift($this->answers) ?? [200, []];
        if ($answer instanceof LystokException) {
            throw $answer;
        }
        $body = $answer[1] ?? null;
        return [$answer[0], $answer[2] ?? [], $body === null ? '' : (is_string($body) ? $body : json_encode($body))];
    }
}

function apiError(string $code, string $message = 'x'): array
{
    return ['error' => ['code' => $code, 'message' => $message, 'requestId' => 'req_1']];
}

test('client: needs an API key, or LYSTOK_API_KEY', function () {
    $before = getenv('LYSTOK_API_KEY');
    putenv('LYSTOK_API_KEY');
    try {
        assertThrows(InvalidArgumentException::class, fn () => new Lystok());
        putenv('LYSTOK_API_KEY=lk_live_from_env');
        $api = new FakeApi([[200, ['id' => 'e1']]]);
        (new Lystok(null, ['transport' => $api]))->emails->get('e1');
        assertSame('Bearer lk_live_from_env', $api->calls[0]['headers']['Authorization']);
        assertSame('https://api.lystok.com/v1/emails/e1', $api->calls[0]['url']);
    } finally {
        putenv($before === false ? 'LYSTOK_API_KEY' : "LYSTOK_API_KEY={$before}");
    }
});

test('client: send', function () {
    $api = new FakeApi([[200, ['id' => '019ab3f2']]]);
    $email = ['from' => 'Пекарня <orders@shop.example>', 'to' => 'olena@example.com', 'subject' => 'Замовлення', 'html' => '<p>Hi</p>'];
    assertSame(['id' => '019ab3f2'], $api->client()->emails->send($email));
    $call = $api->calls[0];
    assertSame(['POST', 'https://api.test/v1/emails'], [$call['method'], $call['url']]);
    assertSame($email, $call['json']);
    assertSame('Bearer lk_live_test', $call['headers']['Authorization']);
    assertSame('application/json', $call['headers']['Content-Type']);
    assertSame('lystok-php/' . Lystok::VERSION, $call['headers']['User-Agent']);
    assertTrue((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $call['headers']['Idempotency-Key']), 'a UUID v4 Idempotency-Key');
});

test('client: batch with its own idempotency key', function () {
    $api = new FakeApi([[200, ['data' => [['id' => 'a'], ['id' => 'b']]]]]);
    $res = $api->client()->emails->sendBatch([['from' => 'a@x.example', 'to' => 'b@x.example', 'template_id' => 't']], idempotencyKey: 'order-1042');
    assertSame(['a', 'b'], array_column($res['data'], 'id'));
    assertSame('https://api.test/v1/emails/batch', $api->calls[0]['url']);
    assertSame('order-1042', $api->calls[0]['headers']['Idempotency-Key']);
});

test('client: contacts', function () {
    $contact = ['id' => 'c1', 'email' => 'olena@example.com'];
    $api = new FakeApi([[201, $contact], [200, $contact], [200, $contact], [204]]);
    $lystok = $api->client();
    assertSame($contact, $lystok->contacts->upsert(['email' => 'olena@example.com', 'consent_source' => 'Checkout']));
    $lystok->contacts->get('olena+shop@example.com');
    $lystok->contacts->update('c1', ['remove_tags' => ['old'], 'status' => 'unsubscribed']);
    $lystok->contacts->delete('c1');
    assertSame([
        'POST https://api.test/v1/contacts',
        'GET https://api.test/v1/contacts/olena%2Bshop%40example.com',
        'PATCH https://api.test/v1/contacts/c1',
        'DELETE https://api.test/v1/contacts/c1',
    ], array_map(fn ($c) => "{$c['method']} {$c['url']}", $api->calls));
    assertTrue(!isset($api->calls[0]['headers']['Idempotency-Key']), 'no Idempotency-Key on contacts');
    assertSame(['remove_tags' => ['old'], 'status' => 'unsubscribed'], $api->calls[2]['json']);
});

test('client: an error answer', function () {
    $api = new FakeApi([[400, ['error' => [
        'code' => 'validation',
        'message' => 'The request is not valid. See `issues` for the fields.',
        'issues' => [['path' => 'to', 'message' => 'Invalid email']],
        'requestId' => 'req_1',
    ]]]]);
    $err = assertThrows(LystokException::class, fn () => $api->client()->emails->send(['from' => 'a@x.example', 'to' => 'nope']));
    assertSame([400, 400, 'validation', 'req_1'], [$err->status, $err->getCode(), $err->errorCode, $err->requestId]);
    assertSame([['path' => 'to', 'message' => 'Invalid email']], $err->issues);
    assertTrue(str_contains($err->getMessage(), 'not valid'), 'the message');
    assertSame(1, count($api->calls), 'a refused request is not tried again');
});

test('client: an answer that is not JSON', function () {
    $api = new FakeApi([[502, '<html>Bad gateway</html>', ['x-request-id' => 'req_9']]]);
    $err = assertThrows(LystokException::class, fn () => $api->client(['max_retries' => 0])->emails->get('e1'));
    assertSame([502, 'server_error', 'req_9'], [$err->status, $err->errorCode, $err->requestId]);
});

test('retries: 503, then ok, with the same idempotency key', function () {
    $api = new FakeApi([[503, apiError('unavailable')], [200, ['id' => 'e1']]]);
    assertSame(['id' => 'e1'], $api->client()->emails->send(['from' => 'a@x.example', 'to' => 'b@x.example', 'text' => 't']));
    assertSame(2, count($api->calls));
    assertSame($api->calls[0]['headers']['Idempotency-Key'], $api->calls[1]['headers']['Idempotency-Key']);
});

test('retries: 429 waits as long as Retry-After says', function () {
    $api = new FakeApi([[429, apiError('rate_limited'), ['retry-after' => '1']], [200, ['id' => 'e1']]]);
    $started = microtime(true);
    $api->client()->emails->get('e1');
    assertTrue(microtime(true) - $started >= 0.95, 'waited a second');
});

test('retries: idempotency_in_progress', function () {
    $api = new FakeApi([[409, apiError('idempotency_in_progress')], [200, ['id' => 'e1']]]);
    assertSame(['id' => 'e1'], $api->client()->emails->send(['from' => 'a@x.example', 'to' => 'b@x.example', 'text' => 't']));
});

test('retries: not after idempotency_mismatch', function () {
    $api = new FakeApi([[409, apiError('idempotency_mismatch')]]);
    $err = assertThrows(LystokException::class, fn () => $api->client()->emails->send(['from' => 'a@x.example', 'to' => 'b@x.example', 'text' => 't']));
    assertSame('idempotency_mismatch', $err->errorCode);
});

test('retries: no answer, then gives up', function () {
    $timeout = new LystokException(0, 'timeout', 'No answer');
    $api = new FakeApi([$timeout, $timeout]);
    $err = assertThrows(LystokException::class, fn () => $api->client(['max_retries' => 1])->emails->get('e1'));
    assertSame([0, 'timeout', 2], [$err->status, $err->errorCode, count($api->calls)]);
});

test('retries: a DELETE that went through before a failure', function () {
    $api = new FakeApi([[503, apiError('unavailable')], [404, apiError('not_found')]]);
    $api->client()->contacts->delete('c1');
    assertSame(2, count($api->calls));
});

test('curl: a connection error', function () {
    $lystok = new Lystok('lk_live_test', ['base_url' => 'http://127.0.0.1:9', 'max_retries' => 0]);
    $err = assertThrows(LystokException::class, fn () => $lystok->emails->get('e1'));
    assertSame([0, 'connection_error'], [$err->status, $err->errorCode]);
});

test('curl: a timeout', function () {
    // A listening socket that never answers.
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    $lystok = new Lystok('lk_live_test', ['base_url' => "http://127.0.0.1:{$port}", 'timeout' => 0.3, 'max_retries' => 0]);
    $err = assertThrows(LystokException::class, fn () => $lystok->emails->get('e1'));
    assertSame([0, 'timeout'], [$err->status, $err->errorCode]);
    fclose($server);
});
