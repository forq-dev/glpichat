<?php

declare(strict_types=1);

// Stub glpichat_config_int before loading invite_flow.php.
// glpichat_create_invite_use_case calls glpichat_config_int('max_invites_per_ticket', 10)
// which is defined in functions.php (not available in unit context).
// The stub returns the default, keeping the use-case logic testable in isolation.
if (!function_exists('glpichat_config_int')) {
    function glpichat_config_int(string $key, int $default): int
    {
        return $default;
    }
}

// Stub global $DB for glpichat_create_invite_use_case's active-invite count query.
// With the default max_invites_per_ticket=10 and count=0 the flow proceeds normally.
final class InviteFlowTestDbResult implements IteratorAggregate
{
    public function __construct(private array $rows)
    {
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rows);
    }

    public function current(): mixed
    {
        return $this->rows[0] ?? false;
    }
}

final class InviteFlowTestDb
{
    /** @var int Simulated COUNT of active invites returned by the stub. */
    public int $active_count = 0;

    public function request(array $query): InviteFlowTestDbResult
    {
        return new InviteFlowTestDbResult([['COUNT' => $this->active_count]]);
    }
}

global $DB;
$DB = new InviteFlowTestDb();

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

// Gap 3 — InviteController validation: guest_name empty
try {
    glpichat_validate_invite_input(10, '', 'alice@example.test');
    glpichat_unit_fail('Invite input must reject empty guest_name');
} catch (RuntimeException $error) {
    glpichat_unit_assert_true(
        str_contains($error->getMessage(), 'Invalid invite parameters.'),
        'Invite input rejects empty guest_name with canonical message'
    );
    glpichat_unit_pass('Invite input rejects empty guest_name with canonical message');
}

// Gap 3 — InviteController validation: guest_name whitespace-only
try {
    glpichat_validate_invite_input(10, '   ', 'alice@example.test');
    glpichat_unit_fail('Invite input must reject whitespace-only guest_name');
} catch (RuntimeException $error) {
    glpichat_unit_assert_true(
        str_contains($error->getMessage(), 'Invalid invite parameters.'),
        'Invite input rejects whitespace-only guest_name with canonical message'
    );
    glpichat_unit_pass('Invite input rejects whitespace-only guest_name with canonical message');
}

// Gap 3 — InviteController validation: guest_name longer than 255 chars is truncated
$long_name = str_repeat('A', 300);
// mb_substr(trim($long_name), 0, 255) truncates to 255 — validate_invite_input only trims
// The truncation lives in the controller; the flow function just trims. Verify trimming:
$result_long = glpichat_validate_invite_input(10, $long_name, '');
glpichat_unit_assert_same(300, mb_strlen($result_long['guest_name']), 'Invite flow preserves full name length for layer above to truncate');
glpichat_unit_pass('Invite flow preserves full name length for layer above to truncate');

// Gap 3 — InviteController validation: empty email is accepted (optional field)
$validated_no_email = glpichat_validate_invite_input(10, 'Bob', '');
glpichat_unit_assert_same('', $validated_no_email['guest_email'], 'Invite input accepts empty guest_email');
glpichat_unit_pass('Invite input accepts empty guest_email');

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
