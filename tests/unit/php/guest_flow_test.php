<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\File\UploadedFile;

// Stub glpichat_config_int before loading guest_db.php which calls it at runtime.
if (!function_exists('glpichat_config_int')) {
    function glpichat_config_int(string $key, int $default): int
    {
        return $default;
    }
}

require dirname(__DIR__, 2) . '/bootstrap/unit.php';
require_once dirname(__DIR__, 3) . '/src/db/guest_db.php';
require_once dirname(__DIR__, 3) . '/src/flows/guest_flow.php';

final class GuestFlowUploadedFile extends UploadedFile
{
    public function __construct(string $path, string $original_name)
    {
        parent::__construct($path, $original_name, 'text/plain', null, true);
    }
}

/**
 * Minimal DB mock that returns last_send_at=null for throttle checks,
 * allowing send/upload use-case tests to pass without hitting a real DB.
 */
final class GuestFlowMockIterator implements Iterator
{
    private bool $rewound = false;

    public function __construct(private readonly ?array $row)
    {
    }

    public function current(): mixed
    {
        return $this->row;
    }

    public function key(): mixed
    {
        return 0;
    }

    public function next(): void
    {
        $this->rewound = false;
    }

    public function rewind(): void
    {
        $this->rewound = true;
    }

    public function valid(): bool
    {
        return $this->rewound;
    }
}

final class GuestFlowMockDB
{
    public function request(array $params): GuestFlowMockIterator
    {
        // Return last_send_at=null so throttle check always passes
        return new GuestFlowMockIterator(['last_send_at' => null]);
    }

    public function update(string $table, array $data, array $where): void
    {
        // No-op: absorbs glpichat_update_participant_last_send() calls
    }

    public function tableExists(string $table): bool
    {
        return false;
    }
}

glpichat_unit_assert_same(
    'hello guest',
    glpichat_validate_guest_message_content(' hello guest '),
    'Guest message content is trimmed'
);
glpichat_unit_pass('Guest message content is trimmed');

try {
    glpichat_validate_guest_message_content('   ');
    glpichat_unit_fail('Guest message content must reject blank payload');
} catch (RuntimeException $error) {
    glpichat_unit_assert_true(
        str_contains($error->getMessage(), 'Invalid message content.'),
        'Guest message content rejects blank payload with canonical message'
    );
    glpichat_unit_pass('Guest message content rejects blank payload with canonical message');
}

glpichat_unit_assert_same(
    'Attachment added from guest chat: proof.png',
    glpichat_guest_attachment_message_content('proof.png'),
    'Guest attachment message content stays canonical'
);
glpichat_unit_pass('Guest attachment message content stays canonical');

glpichat_unit_assert_same(
    42,
    glpichat_guest_ticket_id_from_room(['tickets_id' => 42]),
    'Guest room payload exposes ticket id'
);
glpichat_unit_pass('Guest room payload exposes ticket id');

try {
    glpichat_guest_ticket_id_from_room([]);
    glpichat_unit_fail('Guest room payload must reject missing ticket id');
} catch (RuntimeException $error) {
    glpichat_unit_assert_true(
        str_contains($error->getMessage(), 'Guest room not found.'),
        'Guest room payload rejects missing ticket id with canonical message'
    );
    glpichat_unit_pass('Guest room payload rejects missing ticket id with canonical message');
}

$now = date('Y-m-d H:i:s');
$guest_sync_calls = [];
$guest_sync = glpichat_guest_sync_use_case(
    'guest-token',
    11,
    'ticket_status:12',
    true, // $is_typing
    static function (string $session_token) use ($now): array {
        return ['id' => 7, 'rooms_id' => 8, 'participant_type' => GLPICHAT_ACTOR_GUEST, 'joined_at' => $now, 'last_activity' => $now];
    },
    static function (int $rooms_id): array {
        return ['id' => $rooms_id, 'tickets_id' => 9, 'room_type' => GLPICHAT_ROOM_PUBLIC];
    },
    static function (int $rooms_id, int $last_message_id) use ($now): array {
        return [['rooms_id' => $rooms_id, 'last_message_id' => $last_message_id, 'date_creation' => $now]];
    },
    static function (int $rooms_id, string $last_event_id) use ($now): array {
        return [['rooms_id' => $rooms_id, 'last_event_id' => $last_event_id, 'date_creation' => $now]];
    },
    static function (int $tickets_id): int {
        return $tickets_id === 9 ? 5 : 0;
    },
    static function (int $participants_id, bool $is_typing) use (&$guest_sync_calls): void {
        $guest_sync_calls[] = ['update_activity', $participants_id, $is_typing];
    },
    static function (int $rooms_id, int $exclude_participant_id) use (&$guest_sync_calls): array {
        $guest_sync_calls[] = ['typing_participants', $rooms_id, $exclude_participant_id];
        return ['Tech User'];
    }
);

glpichat_unit_assert_same_array(
    [
        'messages' => [['rooms_id' => 8, 'last_message_id' => 11, 'date_creation' => $now]],
        'events' => [['rooms_id' => 8, 'last_event_id' => 'ticket_status:12', 'date_creation' => $now]],
        'ticket_status' => 5,
        'session_remaining_seconds' => 3600,
        'typing_names' => ['Tech User'],
    ],
    $guest_sync,
    'Guest sync use case assembles room, messages, events, ticket status and typing names'
);
glpichat_unit_pass('Guest sync use case assembles room, messages, events, ticket status and typing names');

glpichat_unit_assert_same_array(
    [
        ['update_activity', 7, true],
        ['typing_participants', 8, 7],
    ],
    $guest_sync_calls,
    'Guest sync use case triggers typing updates'
);
glpichat_unit_pass('Guest sync use case triggers typing updates');

$guest_leave_call_log = [];
glpichat_guest_leave_use_case(
    'guest-token',
    '2026-06-04 18:00:00',
    static function (string $session_token) use (&$guest_leave_call_log): array {
        $guest_leave_call_log[] = ['guest_participant', $session_token];
        return ['id' => 91, 'rooms_id' => 92];
    },
    static function (int $participants_id, string $now) use (&$guest_leave_call_log): void {
        $guest_leave_call_log[] = ['disconnect_participant', $participants_id, $now];
    }
);

glpichat_unit_assert_same_array(
    [
        ['guest_participant', 'guest-token'],
        ['disconnect_participant', 91, '2026-06-04 18:00:00'],
    ],
    $guest_leave_call_log,
    'Guest leave use case resolves the session token and disconnects the participant'
);
glpichat_unit_pass('Guest leave use case resolves the session token and disconnects the participant');

// B-9D: glpichat_assert_guest_not_throttled() requires $DB; provide a mock that always allows
$GLOBALS['DB'] = new GuestFlowMockDB();

$guest_send_call_log = [];
$guest_send_message_id = glpichat_guest_send_message_use_case(
    'guest-token',
    ' guest hello ',
    static function (string $session_token) use (&$guest_send_call_log): array {
        $guest_send_call_log[] = ['guest_participant', $session_token];
        return ['id' => 31, 'rooms_id' => 32];
    },
    static function (int $rooms_id) use (&$guest_send_call_log): array {
        $guest_send_call_log[] = ['room_by_id', $rooms_id];
        return ['id' => $rooms_id, 'tickets_id' => 33, 'room_type' => GLPICHAT_ROOM_PUBLIC];
    },
    static function (int $tickets_id) use (&$guest_send_call_log): void {
        $guest_send_call_log[] = ['assert_ticket_open_for_chat', $tickets_id];
    },
    static function () use (&$guest_send_call_log): int {
        $guest_send_call_log[] = ['external_followup_user_id'];
        return 34;
    },
    static function (int $tickets_id, int $users_id, string $content, bool $is_private) use (&$guest_send_call_log): int {
        $guest_send_call_log[] = ['add_followup', $tickets_id, $users_id, $content, $is_private];
        return 35;
    },
    static function (
        int $tickets_id,
        string $room_type,
        string $actor_type,
        int $users_id,
        int $participants_id,
        string $content,
        int $documents_id,
        int $itilfollowups_id
    ) use (&$guest_send_call_log): int {
        $guest_send_call_log[] = ['add_message', $tickets_id, $room_type, $actor_type, $users_id, $participants_id, $content, $documents_id, $itilfollowups_id];
        return 36;
    }
);

glpichat_unit_assert_same(36, $guest_send_message_id, 'Guest send use case returns created message id');
glpichat_unit_pass('Guest send use case returns created message id');

glpichat_unit_assert_same_array(
    [
        ['guest_participant', 'guest-token'],
        ['room_by_id', 32],
        ['assert_ticket_open_for_chat', 33],
        ['external_followup_user_id'],
        ['add_followup', 33, 34, 'guest hello', false],
        ['add_message', 33, 'public', 'guest', 0, 31, 'guest hello', 0, 35],
    ],
    $guest_send_call_log,
    'Guest send use case orchestrates dependencies in canonical order'
);
glpichat_unit_pass('Guest send use case orchestrates dependencies in canonical order');

$upload_fixture = tempnam(sys_get_temp_dir(), 'glpichat_guest_');
if ($upload_fixture === false) {
    glpichat_unit_fail('Unable to create guest upload fixture');
}
file_put_contents($upload_fixture, 'fixture');
$uploaded_file = new GuestFlowUploadedFile($upload_fixture, 'guest.txt');

$guest_upload_call_log = [];
$guest_upload_result = glpichat_guest_upload_message_use_case(
    'guest-token',
    $uploaded_file,
    static function (string $session_token) use (&$guest_upload_call_log): array {
        $guest_upload_call_log[] = ['guest_participant', $session_token];
        return ['id' => 41, 'rooms_id' => 42];
    },
    static function (int $rooms_id) use (&$guest_upload_call_log): array {
        $guest_upload_call_log[] = ['room_by_id', $rooms_id];
        return ['id' => $rooms_id, 'tickets_id' => 43, 'room_type' => GLPICHAT_ROOM_PUBLIC];
    },
    static function (int $tickets_id) use (&$guest_upload_call_log): void {
        $guest_upload_call_log[] = ['assert_ticket_open_for_chat', $tickets_id];
    },
    static function (int $tickets_id, UploadedFile $file, bool $suppress_hook) use (&$guest_upload_call_log): int {
        $guest_upload_call_log[] = ['upload_document', $tickets_id, $file->getClientOriginalName(), $suppress_hook];
        return 44;
    },
    static function () use (&$guest_upload_call_log): int {
        $guest_upload_call_log[] = ['external_followup_user_id'];
        return 45;
    },
    static function (int $tickets_id, int $users_id, string $content, bool $is_private) use (&$guest_upload_call_log): int {
        $guest_upload_call_log[] = ['add_followup', $tickets_id, $users_id, $content, $is_private];
        return 46;
    },
    static function (
        int $tickets_id,
        string $room_type,
        string $actor_type,
        int $users_id,
        int $participants_id,
        string $content,
        int $documents_id,
        int $itilfollowups_id
    ) use (&$guest_upload_call_log): int {
        $guest_upload_call_log[] = ['add_message', $tickets_id, $room_type, $actor_type, $users_id, $participants_id, $content, $documents_id, $itilfollowups_id];
        return 47;
    }
);

glpichat_unit_assert_same_array(
    ['message_id' => 47, 'documents_id' => 44],
    $guest_upload_result,
    'Guest upload use case returns message and document ids'
);
glpichat_unit_pass('Guest upload use case returns message and document ids');

glpichat_unit_assert_same_array(
    [
        ['guest_participant', 'guest-token'],
        ['room_by_id', 42],
        ['assert_ticket_open_for_chat', 43],
        ['upload_document', 43, 'guest.txt', true],
        ['external_followup_user_id'],
        ['add_followup', 43, 45, 'Attachment added from guest chat: guest.txt', false],
        ['add_message', 43, 'public', 'guest', 0, 41, 'Attachment added from guest chat: guest.txt', 44, 46],
    ],
    $guest_upload_call_log,
    'Guest upload use case orchestrates dependencies in canonical order'
);
glpichat_unit_pass('Guest upload use case orchestrates dependencies in canonical order');

@unlink($upload_fixture);

fwrite(STDOUT, "[PASS] Guest flow unit tests completed\n");
