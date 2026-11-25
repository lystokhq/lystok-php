<?php

/**
 * A tiny test runner, so the library needs nothing but PHP to be tested: `php tests/run.php`.
 * Each *Test.php file calls test('name', fn () => ...).
 */

declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $no, $file, $line);
});

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Lystok\\')) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 7)) . '.php';
    }
});

/** @var list<array{string, Closure}> $tests */
$tests = [];

function test(string $name, Closure $fn): void
{
    $GLOBALS['tests'][] = [$name, $fn];
}

function skip(string $why): never
{
    throw new SkipTest($why);
}

final class SkipTest extends Exception
{
}

final class AssertionFailed extends Exception
{
}

function assertSame(mixed $expected, mixed $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($what !== '' ? "{$what}: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrue(bool $value, string $what): void
{
    if (!$value) {
        throw new AssertionFailed($what);
    }
}

/**
 * @template T of Throwable
 * @param class-string<T> $class
 * @return T
 */
function assertThrows(string $class, Closure $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new AssertionFailed("expected {$class}, got " . $e::class . ': ' . $e->getMessage());
    }
    throw new AssertionFailed("expected {$class}, nothing was thrown");
}

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

$failed = $skipped = 0;
foreach ($tests as [$name, $fn]) {
    try {
        $fn();
        echo "  ok    {$name}\n";
    } catch (SkipTest $e) {
        $skipped++;
        echo "  skip  {$name} ({$e->getMessage()})\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL  {$name}\n        " . $e::class . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ":{$e->getLine()})\n";
    }
}
$passed = count($tests) - $failed - $skipped;
echo "\n{$passed} passed, {$failed} failed, {$skipped} skipped\n";
exit($failed > 0 ? 1 : 0);
