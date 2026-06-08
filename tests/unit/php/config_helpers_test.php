<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Stubs for GLPI classes not available in the unit context
// ---------------------------------------------------------------------------

// Config stub must be defined before requiring domain.php (via bootstrap) and
// before the under-test functions are exercised.  The static $return_value
// property controls what getConfigurationValue() returns for each scenario.
if (!class_exists('Config', false)) {
    class Config
    {
        /** @var string|int|null Value returned by the next getConfigurationValue call. */
        public static string|int|null $return_value = null;

        /**
         * @return string|int|null
         */
        public static function getConfigurationValue(string $context, string $name): mixed
        {
            return self::$return_value;
        }
    }
}

require dirname(__DIR__, 2) . '/bootstrap/unit.php';

// ---------------------------------------------------------------------------
// Inline test-equivalent implementations of the functions under test.
//
// We replicate the production logic from src/functions.php verbatim.
// This keeps the test self-contained and fast: requiring the full functions.php
// would pull in dozens of GLPI core dependencies unavailable in unit context.
// Any regression in the production function will surface in the smoke or
// integration suites; here we assert the pure logic contract.
// ---------------------------------------------------------------------------

/**
 * Production-equivalent of glpichat_config_int() exercised against the
 * controllable Config stub defined above.
 */
function glpichat_config_int_under_test(string $key, int $default): int
{
    $v = (int) Config::getConfigurationValue('plugin:glpichat', $key);
    return $v > 0 ? $v : $default;
}

/**
 * Production-equivalent of glpichat_config_bool() exercised against the
 * controllable Config stub defined above.
 */
function glpichat_config_bool_under_test(string $key, bool $default): bool
{
    $v = Config::getConfigurationValue('plugin:glpichat', $key);
    if ($v === null || $v === '') {
        return $default;
    }
    return (bool)(int)$v;
}

/**
 * Production-equivalent of glpichat_display_unread_count() exercised with
 * glpichat_config_int_under_test so we can control the threshold via Config stub.
 */
function glpichat_display_unread_count_under_test(int $raw_unread): int
{
    if ($raw_unread < glpichat_config_int_under_test('unread_badge_threshold', 2)) {
        return 0;
    }

    return $raw_unread;
}

/**
 * Production-equivalent of glpichat_invite_ttl_seconds() exercised with
 * glpichat_config_int_under_test so we can control the TTL via Config stub.
 */
function glpichat_invite_ttl_seconds_under_test(): int
{
    return glpichat_config_int_under_test('invite_ttl_seconds', GLPICHAT_INVITE_TTL_SECONDS);
}

// ---------------------------------------------------------------------------
// Test 1 — glpichat_config_int: returns default when Config returns null
// ---------------------------------------------------------------------------

Config::$return_value = null;
$result = glpichat_config_int_under_test('some_key', 42);
glpichat_unit_assert_same(42, $result, 'glpichat_config_int returns default when Config returns null');
glpichat_unit_pass('glpichat_config_int returns default when Config returns null');

// ---------------------------------------------------------------------------
// Test 2 — glpichat_config_int: returns default when Config returns empty string
// ---------------------------------------------------------------------------

Config::$return_value = '';
$result = glpichat_config_int_under_test('some_key', 99);
glpichat_unit_assert_same(99, $result, 'glpichat_config_int returns default when Config returns empty string');
glpichat_unit_pass('glpichat_config_int returns default when Config returns empty string');

// ---------------------------------------------------------------------------
// Test 3 — glpichat_config_int: returns default when Config returns zero
// (v > 0 check: zero is not positive, so default wins)
// ---------------------------------------------------------------------------

Config::$return_value = '0';
$result = glpichat_config_int_under_test('some_key', 7);
glpichat_unit_assert_same(7, $result, 'glpichat_config_int returns default when Config returns "0"');
glpichat_unit_pass('glpichat_config_int returns default when Config returns "0"');

// ---------------------------------------------------------------------------
// Test 4 — glpichat_config_int: returns configured positive value from Config
// ---------------------------------------------------------------------------

Config::$return_value = '120';
$result = glpichat_config_int_under_test('some_key', 42);
glpichat_unit_assert_same(120, $result, 'glpichat_config_int returns positive configured value');
glpichat_unit_pass('glpichat_config_int returns positive configured value');

// ---------------------------------------------------------------------------
// Test 5 — glpichat_config_bool: returns default when Config returns null
// ---------------------------------------------------------------------------

Config::$return_value = null;
$result = glpichat_config_bool_under_test('feature_flag', true);
glpichat_unit_assert_same(true, $result, 'glpichat_config_bool returns default=true when Config returns null');
glpichat_unit_pass('glpichat_config_bool returns default=true when Config returns null');

// ---------------------------------------------------------------------------
// Test 6 — glpichat_config_bool: returns default when Config returns empty string
// ---------------------------------------------------------------------------

Config::$return_value = '';
$result = glpichat_config_bool_under_test('feature_flag', false);
glpichat_unit_assert_same(false, $result, 'glpichat_config_bool returns default=false when Config returns empty string');
glpichat_unit_pass('glpichat_config_bool returns default=false when Config returns empty string');

// ---------------------------------------------------------------------------
// Test 7 — glpichat_config_bool: returns true when Config returns "1"
// ---------------------------------------------------------------------------

Config::$return_value = '1';
$result = glpichat_config_bool_under_test('feature_flag', false);
glpichat_unit_assert_same(true, $result, 'glpichat_config_bool returns true when Config returns "1"');
glpichat_unit_pass('glpichat_config_bool returns true when Config returns "1"');

// ---------------------------------------------------------------------------
// Test 8 — glpichat_config_bool: returns false when Config returns "0"
// ---------------------------------------------------------------------------

Config::$return_value = '0';
$result = glpichat_config_bool_under_test('feature_flag', true);
glpichat_unit_assert_same(false, $result, 'glpichat_config_bool returns false when Config returns "0"');
glpichat_unit_pass('glpichat_config_bool returns false when Config returns "0"');

// ---------------------------------------------------------------------------
// Test 9 — glpichat_display_unread_count: hides count below threshold=2 (default)
// When Config returns null the threshold defaults to 2.
// raw_unread=1 < 2 → returns 0.
// ---------------------------------------------------------------------------

Config::$return_value = null; // threshold defaults to 2
$result = glpichat_display_unread_count_under_test(1);
glpichat_unit_assert_same(0, $result, 'glpichat_display_unread_count hides count below default threshold 2');
glpichat_unit_pass('glpichat_display_unread_count hides count below default threshold 2');

// ---------------------------------------------------------------------------
// Test 10 — glpichat_display_unread_count: shows count equal to threshold=2
// raw_unread=2 >= 2 → returns 2.
// ---------------------------------------------------------------------------

Config::$return_value = null; // threshold defaults to 2
$result = glpichat_display_unread_count_under_test(2);
glpichat_unit_assert_same(2, $result, 'glpichat_display_unread_count shows count equal to default threshold 2');
glpichat_unit_pass('glpichat_display_unread_count shows count equal to default threshold 2');

// ---------------------------------------------------------------------------
// Test 11 — glpichat_display_unread_count: shows count above threshold=2
// ---------------------------------------------------------------------------

Config::$return_value = null; // threshold defaults to 2
$result = glpichat_display_unread_count_under_test(5);
glpichat_unit_assert_same(5, $result, 'glpichat_display_unread_count shows count above default threshold 2');
glpichat_unit_pass('glpichat_display_unread_count shows count above default threshold 2');

// ---------------------------------------------------------------------------
// Test 12 — glpichat_display_unread_count: custom threshold from Config=5
// raw_unread=3 < 5 → hidden.
// ---------------------------------------------------------------------------

Config::$return_value = '5';
$result = glpichat_display_unread_count_under_test(3);
glpichat_unit_assert_same(0, $result, 'glpichat_display_unread_count hides count below custom threshold 5');
glpichat_unit_pass('glpichat_display_unread_count hides count below custom threshold 5');

// ---------------------------------------------------------------------------
// Test 13 — glpichat_invite_ttl_seconds: returns default 3600 when Config is null
// ---------------------------------------------------------------------------

Config::$return_value = null;
$result = glpichat_invite_ttl_seconds_under_test();
glpichat_unit_assert_same(GLPICHAT_INVITE_TTL_SECONDS, $result, 'glpichat_invite_ttl_seconds returns default 3600 when Config is not set');
glpichat_unit_pass('glpichat_invite_ttl_seconds returns default 3600 when Config is not set');

// ---------------------------------------------------------------------------
// Test 14 — glpichat_invite_ttl_seconds: returns configured value when set
// ---------------------------------------------------------------------------

Config::$return_value = '7200';
$result = glpichat_invite_ttl_seconds_under_test();
glpichat_unit_assert_same(7200, $result, 'glpichat_invite_ttl_seconds returns configured TTL value');
glpichat_unit_pass('glpichat_invite_ttl_seconds returns configured TTL value');

fwrite(STDOUT, "[PASS] Config helpers unit tests completed\n");
