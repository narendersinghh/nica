<?php

declare(strict_types=1);

/**
 * Tiny test runner (no PHPUnit needed): php tests/run.php [filter]
 * Each tests/*Test.php file calls test('name', fn () => ...).
 */

$app = require dirname(__DIR__) . '/app/bootstrap.php';

$tests = [];
function test(string $name, callable $fn): void
{
    $GLOBALS['tests'][] = [$name, $fn];
}

final class AssertionFailed extends \Exception
{
}

function assert_true(mixed $value, string $message = 'Expected true'): void
{
    if ($value !== true) {
        throw new AssertionFailed($message . ' (got ' . var_export($value, true) . ')');
    }
}

function assert_false(mixed $value, string $message = 'Expected false'): void
{
    if ($value !== false) {
        throw new AssertionFailed($message . ' (got ' . var_export($value, true) . ')');
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($message ? $message . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionFailed(($message ? $message . ': ' : '') . "'{$needle}' not found");
    }
}

function assert_throws(string $class, callable $fn, string $message = ''): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new AssertionFailed("Expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
    }
    throw new AssertionFailed($message ?: "Expected {$class} to be thrown");
}

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

$filter = $argv[1] ?? '';
$passed = $failed = 0;
foreach ($tests as [$name, $fn]) {
    if ($filter !== '' && stripos($name, $filter) === false) {
        continue;
    }
    try {
        $fn();
        $passed++;
        echo "\033[32m  ✓\033[0m {$name}\n";
    } catch (\Throwable $e) {
        $failed++;
        echo "\033[31m  ✕ {$name}\033[0m\n      " . get_class($e) . ': ' . $e->getMessage() . "\n";
        if (!$e instanceof AssertionFailed) {
            echo '      at ' . $e->getFile() . ':' . $e->getLine() . "\n";
        }
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
