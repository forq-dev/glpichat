<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/unit.php';

/**
 * Minimal Ticket stub for glpichat_ticket_or_fail() isolation.
 *
 * Provides a controllable getFromDB() whose return value is set via the
 * static $next_result property before each test call. This avoids any
 * dependency on the GLPI database layer.
 */
if (!class_exists('Ticket', false)) {
    class Ticket
    {
        /** @var bool Controls what the next getFromDB() call returns. */
        public static bool $next_result = true;

        /** @var array<string, mixed> */
        public array $fields = [];

        public function getFromDB(int $id): bool
        {
            if (self::$next_result) {
                $this->fields = ['id' => $id, 'status' => 1, 'entities_id' => 0];
            }
            return self::$next_result;
        }
    }
}

// Stub every other GLPI class referenced at parse-time inside the loaded files,
// but only those that are not provided by vendor/autoload.php. All of these are
// used only inside function bodies, so they are never exercised by this test.
if (!class_exists('CommonITILObject', false)) {
    class CommonITILObject
    {
        public const SOLVED = 5;
        public const CLOSED = 6;
        public const INCOMING = 1;
        public const ASSIGNED = 2;
        public const PLANNED = 3;
        public const WAITING = 4;
    }
}

if (!class_exists('CommonITILActor', false)) {
    class CommonITILActor
    {
        public const REQUESTER = 1;
        public const ASSIGN = 2;
        public const OBSERVER = 3;
    }
}

if (!defined('READ')) {
    define('READ', 1);
}
if (!defined('CREATE')) {
    define('CREATE', 2);
}
if (!defined('UPDATE')) {
    define('UPDATE', 4);
}

// Load the full functions module. All GLPI class usages inside function bodies
// are never reached during this test, so no further stubs are needed.
require_once dirname(__DIR__, 3) . '/src/functions.php';

// ── Scenario 1 — ticket found: first call returns a Ticket instance ───────────

Ticket::$next_result = true;
$ticket_a = glpichat_ticket_or_fail(100);
glpichat_unit_assert_true(
    $ticket_a instanceof Ticket,
    'glpichat_ticket_or_fail returns a Ticket instance when ticket exists'
);
glpichat_unit_pass('glpichat_ticket_or_fail returns a Ticket instance when ticket exists');

// ── Scenario 2 — cache hit: second call returns the same instance ─────────────
//
// After the first call for tickets_id 100 the static cache holds the instance.
// We flip the stub to return false to prove no DB call is made; the same
// object must be returned.

Ticket::$next_result = false;
$ticket_a2 = glpichat_ticket_or_fail(100);
glpichat_unit_assert_true(
    $ticket_a === $ticket_a2,
    'glpichat_ticket_or_fail returns the cached instance on second call (assertSame)'
);
glpichat_unit_pass('glpichat_ticket_or_fail returns the cached instance on second call (assertSame)');

// ── Scenario 3 — ticket not found: RuntimeException is thrown ─────────────────
//
// Use tickets_id 200 (not in cache yet) with stub returning false.

Ticket::$next_result = false;
$caught = false;
try {
    glpichat_ticket_or_fail(200);
} catch (RuntimeException $error) {
    $caught = true;
    glpichat_unit_assert_same(
        'Chamado não encontrado.',
        $error->getMessage(),
        'glpichat_ticket_or_fail throws RuntimeException with canonical message when ticket is missing'
    );
    glpichat_unit_pass('glpichat_ticket_or_fail throws RuntimeException with canonical message when ticket is missing');
}
glpichat_unit_assert_true($caught, 'glpichat_ticket_or_fail must throw when ticket does not exist');
glpichat_unit_pass('glpichat_ticket_or_fail throws when ticket does not exist');

// ── Scenario 4 — failed lookup is NOT cached: retry with valid stub succeeds ──
//
// tickets_id 200 was NOT cached (the exception prevented caching). A subsequent
// call with the stub returning true must succeed and return a fresh instance.

Ticket::$next_result = true;
$ticket_b = glpichat_ticket_or_fail(200);
glpichat_unit_assert_true(
    $ticket_b instanceof Ticket,
    'glpichat_ticket_or_fail succeeds on retry after a previous not-found for the same id'
);
glpichat_unit_pass('glpichat_ticket_or_fail succeeds on retry after a previous not-found for the same id');

fwrite(STDOUT, "[PASS] ticket_or_fail unit tests completed\n");
