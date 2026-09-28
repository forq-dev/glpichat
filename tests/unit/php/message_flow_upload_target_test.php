<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\File\UploadedFile;

require dirname(__DIR__, 2) . '/bootstrap/unit.php';
require_once dirname(__DIR__, 3) . '/src/flows/message_flow.php';

/**
 * GHSA-pf43-5mc3-g62m — HIGH-1 regression coverage.
 *
 * Internal-room attachments must be parented to the ticket's private ITILFollowup
 * (not the Ticket itself), so Document::canViewFileFromItilObject() — which bypasses
 * this plugin's own Hooks::ITEM_CAN check — cannot expose them to every user who can
 * read the ticket. Public-room (and guest) attachments keep the Ticket as parent.
 */
final class UploadTargetUploadedFile extends UploadedFile
{
    public function __construct(string $path, string $original_name)
    {
        parent::__construct($path, $original_name, 'text/plain', null, true);
    }
}

$fixture_path = tempnam(sys_get_temp_dir(), 'glpichat_upload_target_');
if ($fixture_path === false) {
    glpichat_unit_fail('Unable to create temporary upload fixture');
}
file_put_contents($fixture_path, 'fixture');
$uploaded_file = new UploadTargetUploadedFile($fixture_path, 'internal-root-cause.pdf');

function glpichat_upload_target_use_case(
    int $tickets_id,
    string $room_type,
    UploadedFile $uploaded_file,
    array &$call_log,
    int $followups_id,
    int $documents_id
): array {
    return glpichat_upload_message_use_case(
        $tickets_id,
        $room_type,
        $uploaded_file,
        'GLPI Chat',
        static function (int $tickets_id, string $room_type) use (&$call_log): void {
            $call_log[] = ['assert_can_send', $tickets_id, $room_type];
        },
        static function () use (&$call_log): int {
            $call_log[] = ['current_user_id'];
            return 91;
        },
        static function (
            int $tickets_id,
            UploadedFile $file,
            bool $suppress_hook,
            string $target_itemtype,
            int $target_items_id
        ) use (&$call_log, $documents_id): int {
            $call_log[] = ['upload_document', $tickets_id, $file->getClientOriginalName(), $suppress_hook, $target_itemtype, $target_items_id];
            return $documents_id;
        },
        static function (int $tickets_id, int $users_id, string $content, bool $is_private) use (&$call_log, $followups_id): int {
            $call_log[] = ['add_followup', $tickets_id, $users_id, $content, $is_private];
            return $followups_id;
        },
        static function (int $tickets_id, string $room_type) use (&$call_log): int {
            $call_log[] = ['room_id', $tickets_id, $room_type];
            return 95;
        },
        static function (int $rooms_id, int $users_id) use (&$call_log): int {
            $call_log[] = ['participant_id_for_user', $rooms_id, $users_id];
            return 96;
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
        ) use (&$call_log): int {
            $call_log[] = ['add_message', $tickets_id, $room_type, $actor_type, $users_id, $participants_id, $content, $documents_id, $itilfollowups_id];
            return 97;
        }
    );
}

// Internal room: the document must be parented to the private ITILFollowup, not the Ticket.
$internal_call_log = [];
$internal_followups_id = 83;
$internal_documents_id = 82;
glpichat_upload_target_use_case(
    19,
    GLPICHAT_ROOM_INTERNAL,
    $uploaded_file,
    $internal_call_log,
    $internal_followups_id,
    $internal_documents_id
);

$internal_upload_call = null;
foreach ($internal_call_log as $entry) {
    if ($entry[0] === 'upload_document') {
        $internal_upload_call = $entry;
    }
}

glpichat_unit_assert_true(
    $internal_upload_call !== null,
    'Internal upload calls upload_document'
);
glpichat_unit_pass('Internal upload calls upload_document');

glpichat_unit_assert_same(
    'ITILFollowup',
    $internal_upload_call[4] ?? null,
    'Internal room attachment targets ITILFollowup, not Ticket'
);
glpichat_unit_pass('Internal room attachment targets ITILFollowup, not Ticket');

glpichat_unit_assert_same(
    $internal_followups_id,
    $internal_upload_call[5] ?? null,
    'Internal room attachment parent id is the private followup id, not the ticket id'
);
glpichat_unit_pass('Internal room attachment parent id is the private followup id, not the ticket id');

$internal_followup_index = null;
$internal_upload_index = null;
foreach ($internal_call_log as $index => $entry) {
    if ($entry[0] === 'add_followup') {
        $internal_followup_index = $index;
    }
    if ($entry[0] === 'upload_document') {
        $internal_upload_index = $index;
    }
}

glpichat_unit_assert_true(
    $internal_followup_index !== null && $internal_upload_index !== null && $internal_followup_index < $internal_upload_index,
    'Internal upload creates the private followup before uploading the document, so the document can be parented to it'
);
glpichat_unit_pass('Internal upload creates the private followup before uploading the document, so the document can be parented to it');

// Public room: attachment must keep targeting the Ticket directly (unchanged, guest-flow-compatible behavior).
$public_call_log = [];
$public_tickets_id = 18;
glpichat_upload_target_use_case(
    $public_tickets_id,
    GLPICHAT_ROOM_PUBLIC,
    $uploaded_file,
    $public_call_log,
    73,
    72
);

$public_upload_call = null;
foreach ($public_call_log as $entry) {
    if ($entry[0] === 'upload_document') {
        $public_upload_call = $entry;
    }
}

glpichat_unit_assert_true(
    $public_upload_call !== null,
    'Public upload calls upload_document'
);
glpichat_unit_pass('Public upload calls upload_document');

glpichat_unit_assert_same(
    'Ticket',
    $public_upload_call[4] ?? null,
    'Public room attachment keeps targeting the Ticket'
);
glpichat_unit_pass('Public room attachment keeps targeting the Ticket');

glpichat_unit_assert_same(
    $public_tickets_id,
    $public_upload_call[5] ?? null,
    'Public room attachment parent id is the ticket id'
);
glpichat_unit_pass('Public room attachment parent id is the ticket id');

@unlink($fixture_path);

fwrite(STDOUT, "[PASS] Message flow upload target unit tests completed\n");
