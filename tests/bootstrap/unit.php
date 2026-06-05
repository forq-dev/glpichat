<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/src/domain/domain.php';

/**
 * @return never
 */
function glpichat_unit_fail(string $message): void
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function glpichat_unit_pass(string $message): void
{
    fwrite(STDOUT, "[PASS] {$message}\n");
}

function glpichat_unit_assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        glpichat_unit_fail($message);
    }
}

/**
 * @param array<mixed> $expected
 * @param array<mixed> $actual
 */
function glpichat_unit_assert_same_array(array $expected, array $actual, string $message): void
{
    if ($expected !== $actual) {
        glpichat_unit_fail($message . ' - expected: ' . json_encode($expected, JSON_THROW_ON_ERROR) . ' actual: ' . json_encode($actual, JSON_THROW_ON_ERROR));
    }
}

/**
 * @param scalar|null $expected
 * @param scalar|null $actual
 */
function glpichat_unit_assert_same(string|int|bool|null $expected, string|int|bool|null $actual, string $message): void
{
    if ($expected !== $actual) {
        glpichat_unit_fail($message . ' - expected: ' . var_export($expected, true) . ' actual: ' . var_export($actual, true));
    }
}
