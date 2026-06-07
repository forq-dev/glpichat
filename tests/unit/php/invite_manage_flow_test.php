<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/unit.php';
require_once dirname(__DIR__, 3) . '/src/flows/guest_flow.php';
require_once dirname(__DIR__, 3) . '/src/flows/invite_flow.php';

glpichat_unit_assert_same(
    'accepted',
    glpichat_invite_status(['accepted_at' => '2026-06-03 10:00:00'], time()),
    'Accepted invite status wins'
);
glpichat_unit_pass('Accepted invite status wins');

glpichat_unit_assert_same(
    'revoked',
    glpichat_invite_status(['accepted_at' => '', 'revoked_at' => '2026-06-03 10:00:00'], time()),
    'Revoked invite status wins when not accepted'
);
glpichat_unit_pass('Revoked invite status wins when not accepted');

glpichat_unit_assert_same(
    'expired',
    glpichat_invite_status(['accepted_at' => '', 'revoked_at' => '', 'expires_at' => '2020-01-01 00:00:00'], time()),
    'Expired invite is detected from expires_at'
);
glpichat_unit_pass('Expired invite is detected from expires_at');

glpichat_unit_assert_same(
    'active',
    glpichat_invite_status(['accepted_at' => '', 'revoked_at' => '', 'expires_at' => '2999-01-01 00:00:00'], time()),
    'Active invite remains active before expiration'
);
glpichat_unit_pass('Active invite remains active before expiration');

glpichat_unit_assert_same(
    'not_joined',
    glpichat_invite_session_status(['accepted_at' => '', 'participants_id' => 0, 'participant_left_at' => '', 'revoked_at' => ''], time(), 3600),
    'Invite session is not joined before acceptance'
);
glpichat_unit_pass('Invite session is not joined before acceptance');

glpichat_unit_assert_same(
    'active',
    glpichat_invite_session_status(['accepted_at' => '2026-06-03 10:00:00', 'participants_id' => 44, 'participant_left_at' => '', 'revoked_at' => ''], time(), 3600),
    'Accepted invite with open participant session is active'
);
glpichat_unit_pass('Accepted invite with open participant session is active');

glpichat_unit_assert_same(
    'revoked',
    glpichat_invite_session_status(['accepted_at' => '2026-06-03 10:00:00', 'participants_id' => 44, 'participant_left_at' => '2026-06-03 11:00:00', 'revoked_at' => '2026-06-03 11:00:00'], time(), 3600),
    'Accepted invite disconnected by manual revoke exposes revoked session status'
);
glpichat_unit_pass('Accepted invite disconnected by manual revoke exposes revoked session status');

glpichat_unit_assert_same(
    'expired',
    glpichat_invite_session_status(['accepted_at' => '2026-06-03 10:00:00', 'participants_id' => 44, 'participant_left_at' => '2026-06-03 11:00:00', 'revoked_at' => ''], time(), 3600),
    'Accepted invite with ended participant session and no revoke is treated as expired session'
);
glpichat_unit_pass('Accepted invite with ended participant session and no revoke is treated as expired session');

glpichat_unit_assert_same(
    'expired',
    glpichat_invite_session_status(
        [
            'accepted_at' => '2026-06-03 10:00:00',
            'participants_id' => 44,
            'participant_left_at' => '',
            'participant_last_activity' => '2026-06-03 09:00:00',
            'participant_joined_at' => '2026-06-03 08:00:00',
            'revoked_at' => '',
        ],
        strtotime('2026-06-03 11:30:01'),
        3600
    ),
    'Accepted invite with stale activity is treated as expired even before lazy cleanup writes left_at'
);
glpichat_unit_pass('Accepted invite with stale activity is treated as expired even before lazy cleanup writes left_at');

try {
    glpichat_assert_invite_found(null);
    glpichat_unit_fail('Invite guard must reject missing invite');
} catch (RuntimeException $error) {
    glpichat_unit_assert_true(
        str_contains($error->getMessage(), 'Invite not found.'),
        'Invite guard rejects missing invite with canonical message'
    );
    glpichat_unit_pass('Invite guard rejects missing invite with canonical message');
}

// B-9E — empty array must be rejected with the same canonical message (lookup returned [])
try {
    glpichat_assert_invite_found([]);
    glpichat_unit_fail('Invite guard must reject empty array invite');
} catch (RuntimeException $error) {
    glpichat_unit_assert_true(
        str_contains($error->getMessage(), 'Invite not found.'),
        'Invite guard rejects empty array invite with canonical message'
    );
    glpichat_unit_pass('Invite guard rejects empty array invite with canonical message');
}

// B-9E — non-empty array must be returned as-is (happy path)
$valid_manage_invite = ['id' => 99, 'rooms_id' => 10, 'tickets_id' => 20, 'guest_name' => 'Test'];
$found_result = glpichat_assert_invite_found($valid_manage_invite);
glpichat_unit_assert_same_array(
    $valid_manage_invite,
    $found_result,
    'Invite guard returns valid invite array unchanged'
);
glpichat_unit_pass('Invite guard returns valid invite array unchanged');

$list_calls = [];
$listed = glpichat_list_invites_use_case(
    40,
    static function (int $tickets_id) use (&$list_calls): void {
        $list_calls[] = ['assert_can_read_ticket', $tickets_id];
    },
    static function (int $tickets_id) use (&$list_calls): void {
        $list_calls[] = ['assert_can_view_participants', $tickets_id];
    },
    static function (int $tickets_id) use (&$list_calls): array {
        $list_calls[] = ['fetch_invites', $tickets_id];
        return [[
            'id' => 41,
            'guest_name' => 'Alice',
            'guest_email' => 'alice@example.test',
            'created_by' => 42,
            'date_creation' => '2026-06-03 10:00:00',
            'expires_at' => '2999-01-01 00:00:00',
            'accepted_at' => '',
            'revoked_at' => '',
            'participants_id' => 0,
            'participant_joined_at' => '',
            'participant_left_at' => '',
            'participant_last_activity' => '',
        ]];
    },
    static function (int $users_id) use (&$list_calls): string {
        $list_calls[] = ['resolve_user_name', $users_id];
        return 'Admin User';
    },
    time()
);

glpichat_unit_assert_same_array(
    [[
        'id' => 41,
        'guest_name' => 'Alice',
        'guest_email' => 'alice@example.test',
        'created_by' => 42,
        'created_by_name' => 'Admin User',
        'date_creation' => '2026-06-03 10:00:00',
        'expires_at' => '2999-01-01 00:00:00',
        'accepted_at' => '',
        'revoked_at' => '',
        'was_accessed' => false,
        'participants_id' => 0,
        'session_joined_at' => '',
        'session_left_at' => '',
        'session_last_activity' => '',
        'session_reason' => '',
        'status' => 'active',
        'invite_status' => 'active',
        'session_status' => 'not_joined',
        'management_status' => 'active',
        'is_session_active' => false,
    ]],
    $listed,
    'Invite list flow formats rows into canonical payload'
);
glpichat_unit_pass('Invite list flow formats rows into canonical payload');

glpichat_unit_assert_same_array(
    [
        ['assert_can_read_ticket', 40],
        ['assert_can_view_participants', 40],
        ['fetch_invites', 40],
        ['resolve_user_name', 42],
    ],
    $list_calls,
    'Invite list flow enforces permissions before formatting rows'
);
glpichat_unit_pass('Invite list flow enforces permissions before formatting rows');

$revoke_calls = [];
glpichat_revoke_invite_use_case(
    50,
    static function (int $invite_id) use (&$revoke_calls): array {
        $revoke_calls[] = ['find_invite', $invite_id];
        return ['id' => 50, 'rooms_id' => 51, 'tickets_id' => 52, 'accepted_at' => '', 'revoked_at' => ''];
    },
    static function (int $tickets_id) use (&$revoke_calls): void {
        $revoke_calls[] = ['assert_can_update_invites', $tickets_id];
    },
    static function () use (&$revoke_calls): int {
        $revoke_calls[] = ['current_user_id'];
        return 53;
    },
    static function (int $invite_id, string $now) use (&$revoke_calls): void {
        $revoke_calls[] = ['mark_invite_revoked', $invite_id, $now];
    },
    static function (
        int $rooms_id,
        int $tickets_id,
        string $event_type,
        string $actor_type,
        int $users_id,
        int $participants_id,
        array $payload
    ) use (&$revoke_calls): void {
        $revoke_calls[] = ['record_event', $rooms_id, $tickets_id, $event_type, $actor_type, $users_id, $participants_id, $payload['invite_id']];
    },
    static function (int $participants_id, string $now) use (&$revoke_calls): void {
        $revoke_calls[] = ['disconnect_participant', $participants_id, $now];
    },
    '2026-06-03 10:10:00'
);

glpichat_unit_assert_same_array(
    [
        ['find_invite', 50],
        ['assert_can_update_invites', 52],
        ['mark_invite_revoked', 50, '2026-06-03 10:10:00'],
        ['current_user_id'],
        ['record_event', 51, 52, 'invite_revoked', 'user', 53, 0, 50],
    ],
    $revoke_calls,
    'Invite revoke flow enforces canonical revoke sequence'
);
glpichat_unit_pass('Invite revoke flow enforces canonical revoke sequence');

// G5 — Reissue of a revoked invite must be rejected
try {
    glpichat_reissue_invite_use_case(
        60,
        'https://glpi.example/',
        static function (int $invite_id): array {
            return ['id' => 60, 'rooms_id' => 61, 'tickets_id' => 62, 'accepted_at' => '', 'revoked_at' => '2026-06-06 00:00:00'];
        },
        static function (int $tickets_id): void {
        },
        static function (): string {
            return 'should-not-reach';
        },
        static function (): int {
            return 63;
        },
        static function (int $invite_id, string $token_hash, string $expires_at): void {
        },
        static function (int $rooms_id, int $tickets_id, string $event_type, string $actor_type, int $users_id, int $participants_id, array $payload): void {
        },
        GLPICHAT_INVITE_TTL_SECONDS
    );
    glpichat_unit_fail('G5: Reissue of revoked invite must throw RuntimeException');
} catch (RuntimeException $error) {
    glpichat_unit_assert_true(
        str_contains($error->getMessage(), 'Revoked invite cannot be reissued.'),
        'G5: Reissue of revoked invite is rejected with canonical message'
    );
    glpichat_unit_pass('G5: Reissue of revoked invite is rejected with canonical message');
}

$reissue_calls = [];
$reissued = glpichat_reissue_invite_use_case(
    60,
    'https://glpi.example/',
    static function (int $invite_id) use (&$reissue_calls): array {
        $reissue_calls[] = ['find_invite', $invite_id];
        return ['id' => 60, 'rooms_id' => 61, 'tickets_id' => 62, 'accepted_at' => '', 'revoked_at' => ''];
    },
    static function (int $tickets_id) use (&$reissue_calls): void {
        $reissue_calls[] = ['assert_can_update_invites', $tickets_id];
    },
    static function () use (&$reissue_calls): string {
        $reissue_calls[] = ['generate_token'];
        return 'reissued-token';
    },
    static function () use (&$reissue_calls): int {
        $reissue_calls[] = ['current_user_id'];
        return 63;
    },
    static function (int $invite_id, string $token_hash, string $expires_at) use (&$reissue_calls): void {
        $reissue_calls[] = ['update_invite_reissue', $invite_id, $token_hash, $expires_at !== ''];
    },
    static function (
        int $rooms_id,
        int $tickets_id,
        string $event_type,
        string $actor_type,
        int $users_id,
        int $participants_id,
        array $payload
    ) use (&$reissue_calls): void {
        $reissue_calls[] = ['record_event', $rooms_id, $tickets_id, $event_type, $actor_type, $users_id, $participants_id, $payload['invite_id'], isset($payload['expires_at'])];
    },
    GLPICHAT_INVITE_TTL_SECONDS
);

glpichat_unit_assert_same(60, $reissued['id'], 'Invite reissue flow returns invite id');
glpichat_unit_pass('Invite reissue flow returns invite id');

glpichat_unit_assert_same(
    'https://glpi.example/plugins/glpichat/Accept/reissued-token',
    $reissued['url'],
    'Invite reissue flow returns canonical URL'
);
glpichat_unit_pass('Invite reissue flow returns canonical URL');

glpichat_unit_assert_same_array(
    [
        ['find_invite', 60],
        ['assert_can_update_invites', 62],
        ['generate_token'],
        ['update_invite_reissue', 60, hash('sha256', 'reissued-token'), true],
        ['current_user_id'],
        ['record_event', 61, 62, 'invite_reissued', 'user', 63, 0, 60, true],
    ],
    $reissue_calls,
    'Invite reissue flow enforces canonical reissue sequence'
);
glpichat_unit_pass('Invite reissue flow enforces canonical reissue sequence');

fwrite(STDOUT, "[PASS] Invite manage flow unit tests completed\n");
