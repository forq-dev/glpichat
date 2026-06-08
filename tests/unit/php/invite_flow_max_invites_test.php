<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Controllable stub for glpichat_config_int.
//
// glpichat_create_invite_use_case reads 'max_invites_per_ticket' via
// glpichat_config_int (defined in functions.php, unavailable in unit context).
// The stub delegates to a global map so each test can configure the return
// value per key without redefining the function.
// ---------------------------------------------------------------------------

/** @var array<string, int> */
$GLOBALS['glpichat_config_int_overrides'] = [];

if (!function_exists('glpichat_config_int')) {
    function glpichat_config_int(string $key, int $default): int
    {
        return (int) ($GLOBALS['glpichat_config_int_overrides'][$key] ?? $default);
    }
}

// ---------------------------------------------------------------------------
// Controllable DB stub.
//
// glpichat_create_invite_use_case does a COUNT query against global $DB to
// check how many active invites exist for the ticket.  This stub returns the
// count set via the public $active_count property.
// ---------------------------------------------------------------------------

final class MaxInvitesTestDbResult
{
    public function __construct(private int $count)
    {
    }

    public function current(): array
    {
        return ['COUNT' => $this->count];
    }
}

final class MaxInvitesTestDb
{
    public int $active_count = 0;

    public function request(array $query): MaxInvitesTestDbResult
    {
        return new MaxInvitesTestDbResult($this->active_count);
    }
}

global $DB;
$DB = new MaxInvitesTestDb();

require dirname(__DIR__, 2) . '/bootstrap/unit.php';
require_once dirname(__DIR__, 3) . '/src/flows/guest_flow.php';
require_once dirname(__DIR__, 3) . '/src/flows/invite_flow.php';

// ---------------------------------------------------------------------------
// Shared no-op callables for the happy-path slots we don't need to inspect.
// ---------------------------------------------------------------------------

$noop_assert   = static function (int $tickets_id): void {};
$noop_room     = static function (int $tickets_id, string $room_type): int { return 1; };
$noop_token    = static function (): string { return 'test-token'; };
$noop_user     = static function (): int { return 1; };
$noop_insert   = static function (
    int $rooms_id,
    int $tickets_id,
    string $token_hash,
    int $created_by,
    string $guest_name,
    string $guest_email,
    string $expires_at
): int { return 99; };
$noop_event    = static function (
    int $rooms_id,
    int $tickets_id,
    string $event_type,
    string $actor_type,
    int $users_id,
    int $participants_id,
    array $payload
): void {};

// ---------------------------------------------------------------------------
// Scenario 1 — count equals max_invites_per_ticket: exception is thrown
//
// Default max is 10; we set active_count=10 → 10 >= 10 → RuntimeException.
// ---------------------------------------------------------------------------

$DB->active_count = 10;
$GLOBALS['glpichat_config_int_overrides'] = [];   // uses default (10) for max_invites_per_ticket

$caught = false;
try {
    glpichat_create_invite_use_case(
        5,
        'Guest User',
        'guest@example.test',
        'https://glpi.example/',
        $noop_assert,
        $noop_room,
        $noop_token,
        $noop_user,
        $noop_insert,
        $noop_event,
        GLPICHAT_INVITE_TTL_SECONDS
    );
} catch (RuntimeException $error) {
    $caught = true;
    glpichat_unit_assert_same(
        'Maximum active invites per ticket reached.',
        $error->getMessage(),
        'glpichat_create_invite_use_case throws when active count equals max_invites_per_ticket'
    );
    glpichat_unit_pass('glpichat_create_invite_use_case throws when active count equals max_invites_per_ticket');
}
glpichat_unit_assert_true($caught, 'RuntimeException must be raised when active_count >= max_invites_per_ticket');
glpichat_unit_pass('RuntimeException is raised when active_count equals max_invites_per_ticket');

// ---------------------------------------------------------------------------
// Scenario 2 — count exceeds max_invites_per_ticket: exception is thrown
//
// Custom max=3; active_count=5 → 5 >= 3 → RuntimeException.
// ---------------------------------------------------------------------------

$DB->active_count = 5;
$GLOBALS['glpichat_config_int_overrides']['max_invites_per_ticket'] = 3;

$caught = false;
try {
    glpichat_create_invite_use_case(
        6,
        'Another Guest',
        '',
        'https://glpi.example/',
        $noop_assert,
        $noop_room,
        $noop_token,
        $noop_user,
        $noop_insert,
        $noop_event,
        GLPICHAT_INVITE_TTL_SECONDS
    );
} catch (RuntimeException $error) {
    $caught = true;
    glpichat_unit_assert_same(
        'Maximum active invites per ticket reached.',
        $error->getMessage(),
        'glpichat_create_invite_use_case throws when active count exceeds custom max_invites_per_ticket'
    );
    glpichat_unit_pass('glpichat_create_invite_use_case throws when active count exceeds custom max_invites_per_ticket');
}
glpichat_unit_assert_true($caught, 'RuntimeException must be raised when active_count > custom max_invites_per_ticket');
glpichat_unit_pass('RuntimeException is raised when active_count exceeds custom max_invites_per_ticket');

// ---------------------------------------------------------------------------
// Scenario 3 — count is below max_invites_per_ticket: flow proceeds normally
//
// Default max=10; active_count=9 → 9 < 10 → no exception, invite is created.
// ---------------------------------------------------------------------------

$DB->active_count = 9;
$GLOBALS['glpichat_config_int_overrides'] = [];   // uses default (10) for max_invites_per_ticket

$insert_called = false;
$invite_result = glpichat_create_invite_use_case(
    7,
    'Third Guest',
    'third@example.test',
    'https://glpi.example/',
    $noop_assert,
    $noop_room,
    static function (): string { return 'flow-token'; },
    $noop_user,
    static function (
        int $rooms_id,
        int $tickets_id,
        string $token_hash,
        int $created_by,
        string $guest_name,
        string $guest_email,
        string $expires_at
    ) use (&$insert_called): int {
        $insert_called = true;
        return 88;
    },
    $noop_event,
    GLPICHAT_INVITE_TTL_SECONDS
);

glpichat_unit_assert_true($insert_called, 'glpichat_create_invite_use_case proceeds and calls insert_invite when count is below limit');
glpichat_unit_pass('glpichat_create_invite_use_case proceeds and calls insert_invite when count is below limit');

glpichat_unit_assert_same(88, $invite_result['id'], 'glpichat_create_invite_use_case returns invite id from insert callable when count is below limit');
glpichat_unit_pass('glpichat_create_invite_use_case returns invite id from insert callable when count is below limit');

glpichat_unit_assert_same(
    'https://glpi.example/plugins/glpichat/Accept/flow-token',
    $invite_result['url'],
    'glpichat_create_invite_use_case returns canonical invite URL when count is below limit'
);
glpichat_unit_pass('glpichat_create_invite_use_case returns canonical invite URL when count is below limit');

// ---------------------------------------------------------------------------
// Scenario 4 — count is exactly one below max: edge case passes
//
// Custom max=1; active_count=0 → 0 < 1 → flow succeeds.
// ---------------------------------------------------------------------------

$DB->active_count = 0;
$GLOBALS['glpichat_config_int_overrides']['max_invites_per_ticket'] = 1;

$edge_result = glpichat_create_invite_use_case(
    8,
    'Edge Guest',
    '',
    'https://glpi.example/',
    $noop_assert,
    $noop_room,
    static function (): string { return 'edge-token'; },
    $noop_user,
    static function (
        int $rooms_id,
        int $tickets_id,
        string $token_hash,
        int $created_by,
        string $guest_name,
        string $guest_email,
        string $expires_at
    ): int { return 55; },
    $noop_event,
    GLPICHAT_INVITE_TTL_SECONDS
);

glpichat_unit_assert_same(55, $edge_result['id'], 'glpichat_create_invite_use_case proceeds when count=0 and max=1 (edge: count < max)');
glpichat_unit_pass('glpichat_create_invite_use_case proceeds when count=0 and max=1 (edge: count < max)');

// ---------------------------------------------------------------------------
// Scenario 5 — count equals 1 with max=1: limit reached at boundary
//
// Custom max=1; active_count=1 → 1 >= 1 → RuntimeException.
// ---------------------------------------------------------------------------

$DB->active_count = 1;
$GLOBALS['glpichat_config_int_overrides']['max_invites_per_ticket'] = 1;

$caught = false;
try {
    glpichat_create_invite_use_case(
        9,
        'Boundary Guest',
        '',
        'https://glpi.example/',
        $noop_assert,
        $noop_room,
        $noop_token,
        $noop_user,
        $noop_insert,
        $noop_event,
        GLPICHAT_INVITE_TTL_SECONDS
    );
} catch (RuntimeException $error) {
    $caught = true;
    glpichat_unit_assert_same(
        'Maximum active invites per ticket reached.',
        $error->getMessage(),
        'glpichat_create_invite_use_case throws at boundary when count=max=1'
    );
    glpichat_unit_pass('glpichat_create_invite_use_case throws at boundary when count=max=1');
}
glpichat_unit_assert_true($caught, 'RuntimeException must be raised when count=max=1 (boundary)');
glpichat_unit_pass('RuntimeException is raised at boundary when count equals max_invites_per_ticket=1');

fwrite(STDOUT, "[PASS] Invite flow max invites unit tests completed\n");
