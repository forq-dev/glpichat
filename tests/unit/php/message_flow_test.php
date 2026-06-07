<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\File\UploadedFile;

require dirname(__DIR__, 2) . '/bootstrap/unit.php';
require_once dirname(__DIR__, 3) . '/src/flows/message_flow.php';

final class MessageFlowUploadedFile extends UploadedFile
{
    public function __construct(string $path, string $original_name)
    {
        parent::__construct($path, $original_name, 'text/plain', null, true);
    }
}

glpichat_unit_assert_same(
    'hello',
    glpichat_validate_send_message_input(10, GLPICHAT_ROOM_PUBLIC, ' hello '),
    'Send input trims content and keeps valid payload'
);
glpichat_unit_pass('Send input trims content and keeps valid payload');

try {
    glpichat_validate_send_message_input(0, GLPICHAT_ROOM_PUBLIC, 'x');
    glpichat_unit_fail('Send input must reject invalid ticket id');
} catch (RuntimeException $error) {
    glpichat_unit_assert_true(
        str_contains($error->getMessage(), 'Invalid message parameters.'),
        'Send input rejects invalid ticket id with canonical message'
    );
    glpichat_unit_pass('Send input rejects invalid ticket id with canonical message');
}

// Gap 2 — room_type whitelist: send
foreach (['admin', 'guest', '', 'PUBLIC', 'INTERNAL'] as $bad_room_type) {
    try {
        glpichat_validate_send_message_input(10, $bad_room_type, 'x');
        glpichat_unit_fail("Send input must reject room_type '{$bad_room_type}'");
    } catch (RuntimeException $error) {
        glpichat_unit_assert_true(
            str_contains($error->getMessage(), 'Invalid message parameters.'),
            "Send input rejects room_type '{$bad_room_type}' with canonical message"
        );
        glpichat_unit_pass("Send input rejects room_type '{$bad_room_type}' with canonical message");
    }
}

glpichat_unit_assert_same(
    'hello internal',
    glpichat_validate_send_message_input(10, GLPICHAT_ROOM_INTERNAL, ' hello internal '),
    'Send input accepts room_type internal'
);
glpichat_unit_pass('Send input accepts room_type internal');

glpichat_unit_assert_same(
    'Attachment added from GLPI Chat: note.txt',
    glpichat_attachment_message_content('note.txt', 'GLPI Chat'),
    'Attachment content stays canonical for logged user uploads'
);
glpichat_unit_pass('Attachment content stays canonical for logged user uploads');

$upload_fixture = tempnam(sys_get_temp_dir(), 'glpichat_msg_');
if ($upload_fixture === false) {
    glpichat_unit_fail('Unable to create temporary upload fixture');
}
file_put_contents($upload_fixture, 'fixture');
$uploaded_file = new MessageFlowUploadedFile($upload_fixture, 'fixture.txt');

glpichat_unit_assert_true(
    glpichat_validate_upload_input(8, GLPICHAT_ROOM_PUBLIC, $uploaded_file) instanceof UploadedFile,
    'Upload input returns UploadedFile when payload is valid'
);
glpichat_unit_pass('Upload input returns UploadedFile when payload is valid');

try {
    glpichat_validate_upload_input(8, 'weird', $uploaded_file);
    glpichat_unit_fail('Upload input must reject invalid room type');
} catch (RuntimeException $error) {
    glpichat_unit_assert_true(
        str_contains($error->getMessage(), 'Invalid upload parameters.'),
        'Upload input rejects invalid room type with canonical message'
    );
    glpichat_unit_pass('Upload input rejects invalid room type with canonical message');
}

$send_call_log = [];
$send_message_id = glpichat_send_message_use_case(
    15,
    GLPICHAT_ROOM_INTERNAL,
    ' technical note ',
    static function (int $tickets_id, string $room_type) use (&$send_call_log): void {
        $send_call_log[] = ['assert_can_send', $tickets_id, $room_type];
    },
    static function () use (&$send_call_log): int {
        $send_call_log[] = ['current_user_id'];
        return 21;
    },
    static function (int $tickets_id, string $room_type) use (&$send_call_log): int {
        $send_call_log[] = ['room_id', $tickets_id, $room_type];
        return 33;
    },
    static function (int $rooms_id, int $users_id) use (&$send_call_log): int {
        $send_call_log[] = ['participant_id_for_user', $rooms_id, $users_id];
        return 44;
    },
    static function (int $tickets_id, int $users_id, string $content, bool $is_private) use (&$send_call_log): int {
        $send_call_log[] = ['add_followup', $tickets_id, $users_id, $content, $is_private];
        return 55;
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
    ) use (&$send_call_log): int {
        $send_call_log[] = ['add_message', $tickets_id, $room_type, $actor_type, $users_id, $participants_id, $content, $documents_id, $itilfollowups_id];
        return 66;
    }
);

glpichat_unit_assert_same(66, $send_message_id, 'Send use case returns created message id');
glpichat_unit_pass('Send use case returns created message id');

glpichat_unit_assert_same_array(
    [
        ['assert_can_send', 15, 'internal'],
        ['current_user_id'],
        ['room_id', 15, 'internal'],
        ['participant_id_for_user', 33, 21],
        ['add_followup', 15, 21, 'technical note', true],
        ['add_message', 15, 'internal', 'user', 21, 44, 'technical note', 0, 55],
    ],
    $send_call_log,
    'Send use case orchestrates dependencies in canonical order'
);
glpichat_unit_pass('Send use case orchestrates dependencies in canonical order');

$upload_call_log = [];
$upload_result = glpichat_upload_message_use_case(
    18,
    GLPICHAT_ROOM_PUBLIC,
    $uploaded_file,
    'GLPI Chat',
    static function (int $tickets_id, string $room_type) use (&$upload_call_log): void {
        $upload_call_log[] = ['assert_can_send', $tickets_id, $room_type];
    },
    static function () use (&$upload_call_log): int {
        $upload_call_log[] = ['current_user_id'];
        return 71;
    },
    static function (int $tickets_id, UploadedFile $file, bool $suppress_hook) use (&$upload_call_log): int {
        $upload_call_log[] = ['upload_document', $tickets_id, $file->getClientOriginalName(), $suppress_hook];
        return 72;
    },
    static function (int $tickets_id, int $users_id, string $content, bool $is_private) use (&$upload_call_log): int {
        $upload_call_log[] = ['add_followup', $tickets_id, $users_id, $content, $is_private];
        return 73;
    },
    static function (int $tickets_id, string $room_type) use (&$upload_call_log): int {
        $upload_call_log[] = ['room_id', $tickets_id, $room_type];
        return 74;
    },
    static function (int $rooms_id, int $users_id) use (&$upload_call_log): int {
        $upload_call_log[] = ['participant_id_for_user', $rooms_id, $users_id];
        return 75;
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
    ) use (&$upload_call_log): int {
        $upload_call_log[] = ['add_message', $tickets_id, $room_type, $actor_type, $users_id, $participants_id, $content, $documents_id, $itilfollowups_id];
        return 76;
    }
);

glpichat_unit_assert_same_array(
    ['message_id' => 76, 'documents_id' => 72],
    $upload_result,
    'Upload use case returns created message and document ids'
);
glpichat_unit_pass('Upload use case returns created message and document ids');

glpichat_unit_assert_same_array(
    [
        ['assert_can_send', 18, 'public'],
        ['current_user_id'],
        ['upload_document', 18, 'fixture.txt', true],
        ['add_followup', 18, 71, 'Attachment added from GLPI Chat: fixture.txt', false],
        ['room_id', 18, 'public'],
        ['participant_id_for_user', 74, 71],
        ['add_message', 18, 'public', 'user', 71, 75, 'Attachment added from GLPI Chat: fixture.txt', 72, 73],
    ],
    $upload_call_log,
    'Upload use case orchestrates dependencies in canonical order'
);
glpichat_unit_pass('Upload use case orchestrates dependencies in canonical order');

$internal_upload_call_log = [];
$internal_upload_result = glpichat_upload_message_use_case(
    19,
    GLPICHAT_ROOM_INTERNAL,
    $uploaded_file,
    'GLPI Chat',
    static function (int $tickets_id, string $room_type) use (&$internal_upload_call_log): void {
        $internal_upload_call_log[] = ['assert_can_send', $tickets_id, $room_type];
    },
    static function () use (&$internal_upload_call_log): int {
        $internal_upload_call_log[] = ['current_user_id'];
        return 81;
    },
    static function (int $tickets_id, UploadedFile $file, bool $suppress_hook) use (&$internal_upload_call_log): int {
        $internal_upload_call_log[] = ['upload_document', $tickets_id, $file->getClientOriginalName(), $suppress_hook];
        return 82;
    },
    static function (int $tickets_id, int $users_id, string $content, bool $is_private) use (&$internal_upload_call_log): int {
        $internal_upload_call_log[] = ['add_followup', $tickets_id, $users_id, $content, $is_private];
        return 83;
    },
    static function (int $tickets_id, string $room_type) use (&$internal_upload_call_log): int {
        $internal_upload_call_log[] = ['room_id', $tickets_id, $room_type];
        return 84;
    },
    static function (int $rooms_id, int $users_id) use (&$internal_upload_call_log): int {
        $internal_upload_call_log[] = ['participant_id_for_user', $rooms_id, $users_id];
        return 85;
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
    ) use (&$internal_upload_call_log): int {
        $internal_upload_call_log[] = ['add_message', $tickets_id, $room_type, $actor_type, $users_id, $participants_id, $content, $documents_id, $itilfollowups_id];
        return 86;
    }
);

glpichat_unit_assert_same_array(
    ['message_id' => 86, 'documents_id' => 82],
    $internal_upload_result,
    'Internal upload use case returns created message and document ids'
);
glpichat_unit_pass('Internal upload use case returns created message and document ids');

glpichat_unit_assert_same_array(
    [
        ['assert_can_send', 19, 'internal'],
        ['current_user_id'],
        ['upload_document', 19, 'fixture.txt', true],
        ['add_followup', 19, 81, 'Attachment added from GLPI Chat: fixture.txt', true],
        ['room_id', 19, 'internal'],
        ['participant_id_for_user', 84, 81],
        ['add_message', 19, 'internal', 'user', 81, 85, 'Attachment added from GLPI Chat: fixture.txt', 82, 83],
    ],
    $internal_upload_call_log,
    'Internal upload use case keeps followup privacy aligned with room type'
);
glpichat_unit_pass('Internal upload use case keeps followup privacy aligned with room type');

@unlink($upload_fixture);

fwrite(STDOUT, "[PASS] Message flow unit tests completed\n");
