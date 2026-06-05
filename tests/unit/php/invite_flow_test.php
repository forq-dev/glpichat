<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/unit.php';
require_once dirname(__DIR__, 3) . '/src/flows/guest_flow.php';
require_once dirname(__DIR__, 3) . '/src/flows/invite_flow.php';

$validated_invite = glpichat_validate_invite_input(10, ' Alice ', ' alice@example.test ');
glpichat_unit_assert_same_array(
    [
        'tickets_id' => 10,
        'guest_name' => 'Alice',
        'guest_email' => 'alice@example.test',
    ],
    $validated_invite,
    'Invite input trims guest fields and preserves valid payload'
);
glpichat_unit_pass('Invite input trims guest fields and preserves valid payload');

try {
    glpichat_validate_invite_input(0, 'Alice', '');
    glpichat_unit_fail('Invite input must reject invalid ticket id');
} catch (RuntimeException $error) {
    glpichat_unit_assert_true(
        str_contains($error->getMessage(), 'Invalid invite parameters.'),
        'Invite input rejects invalid ticket id with canonical message'
    );
    glpichat_unit_pass('Invite input rejects invalid ticket id with canonical message');
}

glpichat_unit_assert_same(
    'https://glpi.example/plugins/glpichat/Accept/token123',
    glpichat_invite_url('https://glpi.example/', 'token123'),
    'Invite URL stays canonical'
);
glpichat_unit_pass('Invite URL stays canonical');

$invite_call_log = [];
$invite_result = glpichat_create_invite_use_case(
    21,
    '  Alice  ',
    ' alice@example.test ',
    'https://glpi.example/',
    static function (int $tickets_id) use (&$invite_call_log): void {
        $invite_call_log[] = ['assert_can_create_invites', $tickets_id];
    },
    static function (int $tickets_id, string $room_type) use (&$invite_call_log): int {
        $invite_call_log[] = ['room_id', $tickets_id, $room_type];
        return 22;
    },
    static function () use (&$invite_call_log): string {
        $invite_call_log[] = ['generate_token'];
        return 'invite-token';
    },
    static function () use (&$invite_call_log): int {
        $invite_call_log[] = ['current_user_id'];
        return 23;
    },
    static function (
        int $rooms_id,
        int $tickets_id,
        string $token_hash,
        int $created_by,
        string $guest_name,
        string $guest_email,
        string $expires_at
    ) use (&$invite_call_log): int {
        $invite_call_log[] = ['insert_invite', $rooms_id, $tickets_id, $token_hash, $created_by, $guest_name, $guest_email, $expires_at !== ''];
        return 24;
    },
    static function (
        int $rooms_id,
        int $tickets_id,
        string $event_type,
        string $actor_type,
        int $users_id,
        int $participants_id,
        array $payload
    ) use (&$invite_call_log): void {
        $invite_call_log[] = ['record_event', $rooms_id, $tickets_id, $event_type, $actor_type, $users_id, $participants_id, $payload['invite_id'], $payload['guest_name'], isset($payload['expires_at'])];
    },
    GLPICHAT_INVITE_TTL_SECONDS
);

glpichat_unit_assert_same(24, $invite_result['id'], 'Invite flow returns created invite id');
glpichat_unit_pass('Invite flow returns created invite id');

glpichat_unit_assert_same(
    'https://glpi.example/plugins/glpichat/Accept/invite-token',
    $invite_result['url'],
    'Invite flow returns canonical invite URL'
);
glpichat_unit_pass('Invite flow returns canonical invite URL');

glpichat_unit_assert_true(
    isset($invite_result['expires_at']) && $invite_result['expires_at'] !== '',
    'Invite flow returns expiration timestamp'
);
glpichat_unit_pass('Invite flow returns expiration timestamp');

glpichat_unit_assert_same_array(
    [
        ['assert_can_create_invites', 21],
        ['room_id', 21, 'public'],
        ['generate_token'],
        ['current_user_id'],
        ['insert_invite', 22, 21, hash('sha256', 'invite-token'), 23, 'Alice', 'alice@example.test', true],
        ['record_event', 22, 21, 'guest_invited', 'user', 23, 0, 24, 'Alice', true],
    ],
    $invite_call_log,
    'Invite flow orchestrates canonical admin-side guest invite sequence'
);
glpichat_unit_pass('Invite flow orchestrates canonical admin-side guest invite sequence');

try {
    glpichat_create_invite_use_case(
        21,
        'Alice',
        '',
        'https://glpi.example/',
        static function (int $tickets_id): void {
            throw new RuntimeException('User cannot invite guests.');
        },
        static function (int $tickets_id, string $room_type): int {
            return 22;
        },
        static function (): string {
            return 'invite-token';
        },
        static function (): int {
            return 23;
        },
        static function (
            int $rooms_id,
            int $tickets_id,
            string $token_hash,
            int $created_by,
            string $guest_name,
            string $guest_email,
            string $expires_at
        ): int {
            return 24;
        },
        static function (
            int $rooms_id,
            int $tickets_id,
            string $event_type,
            string $actor_type,
            int $users_id,
            int $participants_id,
            array $payload
        ): void {
        },
        GLPICHAT_INVITE_TTL_SECONDS
    );
    glpichat_unit_fail('Invite flow must reject missing invite creation permission');
} catch (RuntimeException $error) {
    glpichat_unit_assert_true(
        str_contains($error->getMessage(), 'User cannot invite guests.'),
        'Invite flow rejects missing invite creation permission with canonical message'
    );
    glpichat_unit_pass('Invite flow rejects missing invite creation permission with canonical message');
}

fwrite(STDOUT, "[PASS] Invite flow unit tests completed\n");
