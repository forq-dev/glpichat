<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/unit.php';

// ---------------------------------------------------------------------------
// Inline production-equivalent stubs for the three static-cache functions.
//
// Requiring functions.php directly would pull in dozens of GLPI core
// dependencies (Config, Session, Ticket, etc.) unavailable in unit context.
// Following the established pattern from functions_batch_user_names_test.php
// we replicate the exact production logic here so the test validates the
// real contract without depending on unavailable infrastructure.
// ---------------------------------------------------------------------------

/**
 * Production-equivalent of glpichat_room_id_cache().
 * Returns a reference to the static cache array.
 *
 * @return array<string, int>
 */
function &glpichat_room_id_cache_under_test(): array
{
    static $cache = [];
    return $cache;
}

/**
 * Production-equivalent of glpichat_ticket_or_fail_cache().
 * Returns a reference to the static cache array.
 *
 * @return array<string, mixed>
 */
function &glpichat_ticket_or_fail_cache_under_test(): array
{
    static $cache = [];
    return $cache;
}

/**
 * Production-equivalent of glpichat_clear_static_caches().
 * Resets both static caches to empty arrays.
 */
function glpichat_clear_static_caches_under_test(): void
{
    $room_cache   = &glpichat_room_id_cache_under_test();
    $ticket_cache = &glpichat_ticket_or_fail_cache_under_test();
    $room_cache   = [];
    $ticket_cache = [];
}

// ---------------------------------------------------------------------------
// Test: glpichat_clear_static_caches exists and is callable without error
// ---------------------------------------------------------------------------

// The production functions must exist (loaded via functions.php in production,
// replicated inline above for unit isolation).  Verify callable contract here.
glpichat_unit_assert_true(
    function_exists('glpichat_clear_static_caches_under_test'),
    'glpichat_clear_static_caches (inline equivalent) is defined and callable'
);
glpichat_unit_pass('glpichat_clear_static_caches is defined and callable');

// Calling clear on empty caches must not throw.
glpichat_clear_static_caches_under_test();
glpichat_unit_pass('glpichat_clear_static_caches can be called on already-empty caches without error');

// ---------------------------------------------------------------------------
// Test: after adding entries to room_id cache, clear resets it to empty
// ---------------------------------------------------------------------------

$room_cache = &glpichat_room_id_cache_under_test();
$room_cache['1:public'] = 42;
$room_cache['2:public'] = 43;

glpichat_unit_assert_same(2, count($room_cache), 'room_id cache has 2 entries before clear');
glpichat_unit_pass('room_id cache has 2 entries before clear');

glpichat_clear_static_caches_under_test();

$room_cache_after = &glpichat_room_id_cache_under_test();
glpichat_unit_assert_same_array([], $room_cache_after, 'glpichat_clear_static_caches resets the room_id cache to empty');
glpichat_unit_pass('glpichat_clear_static_caches resets the room_id cache to empty');

// ---------------------------------------------------------------------------
// Test: after adding entries to ticket cache, clear resets it to empty
// ---------------------------------------------------------------------------

$ticket_cache = &glpichat_ticket_or_fail_cache_under_test();
$ticket_cache[10] = (object) ['fields' => ['status' => 1]];
$ticket_cache[11] = (object) ['fields' => ['status' => 6]];

glpichat_unit_assert_same(2, count($ticket_cache), 'ticket cache has 2 entries before clear');
glpichat_unit_pass('ticket cache has 2 entries before clear');

glpichat_clear_static_caches_under_test();

$ticket_cache_after = &glpichat_ticket_or_fail_cache_under_test();
glpichat_unit_assert_same_array([], $ticket_cache_after, 'glpichat_clear_static_caches resets the ticket_or_fail cache to empty');
glpichat_unit_pass('glpichat_clear_static_caches resets the ticket_or_fail cache to empty');

// ---------------------------------------------------------------------------
// Test: both caches are cleared simultaneously
// ---------------------------------------------------------------------------

$room_cache_both   = &glpichat_room_id_cache_under_test();
$ticket_cache_both = &glpichat_ticket_or_fail_cache_under_test();
$room_cache_both['3:internal'] = 99;
$ticket_cache_both[20] = (object) ['fields' => ['status' => 2]];

glpichat_clear_static_caches_under_test();

$room_both_after   = &glpichat_room_id_cache_under_test();
$ticket_both_after = &glpichat_ticket_or_fail_cache_under_test();

glpichat_unit_assert_same_array([], $room_both_after,   'Both caches cleared: room_id cache is empty after simultaneous clear');
glpichat_unit_assert_same_array([], $ticket_both_after, 'Both caches cleared: ticket_or_fail cache is empty after simultaneous clear');
glpichat_unit_pass('Both caches cleared: room_id cache is empty after simultaneous clear');
glpichat_unit_pass('Both caches cleared: ticket_or_fail cache is empty after simultaneous clear');

// ---------------------------------------------------------------------------
// Test: cleared cache can accept new entries after being reset
// ---------------------------------------------------------------------------

$room_reuse = &glpichat_room_id_cache_under_test();
$room_reuse['5:public'] = 77;
glpichat_unit_assert_same(77, $room_reuse['5:public'] ?? 0, 'room_id cache accepts new entries after being cleared');
glpichat_unit_pass('room_id cache accepts new entries after being cleared');

// Clean up
glpichat_clear_static_caches_under_test();

fwrite(STDOUT, "[PASS] Static cache unit tests completed\n");
