<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/unit.php';
require_once dirname(__DIR__, 3) . '/src/flows/guest_flow.php';

glpichat_unit_assert_same(
    hash('sha256', 'guest-token'),
    glpichat_guest_session_hash('guest-token'),
    'Guest session hash stays canonical'
);
glpichat_unit_pass('Guest session hash stays canonical');

glpichat_unit_assert_same(
    hash('sha256', 'invite-token'),
    glpichat_guest_invite_token_hash('invite-token'),
    'Guest invite token hash stays canonical'
);
glpichat_unit_pass('Guest invite token hash stays canonical');

glpichat_unit_assert_same(
    'Guest Alice joined the chat.',
    glpichat_guest_join_followup_content('Alice'),
    'Guest join followup content stays canonical'
);
glpichat_unit_pass('Guest join followup content stays canonical');

try {
    glpichat_assert_valid_accept_invite_result(null);
    glpichat_unit_fail('Accept invite guard must reject invalid invite');
} catch (RuntimeException $error) {
    glpichat_unit_assert_true(
        str_contains($error->getMessage(), 'Invite is invalid, expired, accepted, or revoked.'),
        'Accept invite guard rejects invalid invite with canonical message'
    );
    glpichat_unit_pass('Accept invite guard rejects invalid invite with canonical message');
}

try {
    glpichat_assert_valid_guest_participant(null);
    glpichat_unit_fail('Guest participant guard must reject invalid participant');
} catch (RuntimeException $error) {
    glpichat_unit_assert_true(
        str_contains($error->getMessage(), 'Guest session is invalid.'),
        'Guest participant guard rejects invalid participant with canonical message'
    );
    glpichat_unit_pass('Guest participant guard rejects invalid participant with canonical message');
}

$accept_call_log = [];
$accepted = glpichat_accept_invite_use_case(
    'invite-token',
    static function (string $token_hash) use (&$accept_call_log): array {
        $accept_call_log[] = ['find_valid_invite', $token_hash];
        return [
            'id' => 11,
            'rooms_id' => 12,
            'tickets_id' => 13,
            'guest_name' => 'Alice',
            'guest_email' => 'alice@example.test',
        ];
    },
    static function () use (&$accept_call_log): string {
        $accept_call_log[] = ['generate_session_token'];
        return 'generated-session-token';
    },
    static function (int $rooms_id, string $guest_name, string $guest_email, string $session_hash) use (&$accept_call_log): int {
        $accept_call_log[] = ['create_guest_participant', $rooms_id, $guest_name, $guest_email, $session_hash];
        return 14;
    },
    static function (int $invite_id, int $participants_id) use (&$accept_call_log): void {
        $accept_call_log[] = ['mark_invite_accepted', $invite_id, $participants_id];
    },
    static function (
        int $rooms_id,
        int $tickets_id,
        string $event_type,
        string $actor_type,
        int $users_id,
        int $participants_id,
        array $payload
    ) use (&$accept_call_log): void {
        $accept_call_log[] = ['record_event', $rooms_id, $tickets_id, $event_type, $actor_type, $users_id, $participants_id, $payload];
    },
    static function () use (&$accept_call_log): int {
        $accept_call_log[] = ['external_followup_user_id'];
        return 15;
    },
    static function (int $tickets_id, int $users_id, string $content, bool $is_private) use (&$accept_call_log): void {
        $accept_call_log[] = ['add_followup', $tickets_id, $users_id, $content, $is_private];
    },
    static function (int $tickets_id, string $guest_name) use (&$accept_call_log): void {
        $accept_call_log[] = ['raise_notification', $tickets_id, $guest_name];
    }
);

glpichat_unit_assert_same_array(
    [
        'session_token' => 'generated-session-token',
        'participants_id' => 14,
        'rooms_id' => 12,
        'tickets_id' => 13,
        'guest_name' => 'Alice',
    ],
    $accepted,
    'Accept invite use case returns canonical payload'
);
glpichat_unit_pass('Accept invite use case returns canonical payload');

glpichat_unit_assert_same_array(
    [
        ['find_valid_invite', hash('sha256', 'invite-token')],
        ['generate_session_token'],
        ['create_guest_participant', 12, 'Alice', 'alice@example.test', hash('sha256', 'generated-session-token')],
        ['mark_invite_accepted', 11, 14],
        ['record_event', 12, 13, 'guest_joined', 'guest', 0, 14, ['invite_id' => 11, 'guest_name' => 'Alice']],
        ['external_followup_user_id'],
        ['add_followup', 13, 15, 'Guest Alice joined the chat.', false],
        ['raise_notification', 13, 'Alice'],
    ],
    $accept_call_log,
    'Accept invite use case orchestrates canonical security-sensitive flow'
);
glpichat_unit_pass('Accept invite use case orchestrates canonical security-sensitive flow');

fwrite(STDOUT, "[PASS] Guest accept unit tests completed\n");
