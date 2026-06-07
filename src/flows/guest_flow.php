<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\File\UploadedFile;

require_once dirname(__DIR__) . '/domain/domain.php';
require_once __DIR__ . '/message_flow.php';

function glpichat_guest_session_hash(string $session_token): string
{
    return hash('sha256', $session_token);
}

function glpichat_guest_invite_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function glpichat_validate_guest_message_content(string $content): string
{
    $trimmed_content = trim($content);
    if ($trimmed_content === '') {
        throw new RuntimeException('Invalid message content.');
    }

    return $trimmed_content;
}

function glpichat_guest_attachment_message_content(string $original_name): string
{
    return glpichat_attachment_message_content($original_name, 'guest chat');
}

function glpichat_guest_ticket_id_from_room(array $room): int
{
    $tickets_id = (int) ($room['tickets_id'] ?? 0);
    if ($tickets_id <= 0) {
        throw new RuntimeException('Guest room not found.');
    }

    return $tickets_id;
}

/**
 * @param array<string, mixed>|null $invite
 */
function glpichat_assert_valid_accept_invite_result(?array $invite): array
{
    if (!is_array($invite) || $invite === []) {
        throw new RuntimeException('Invite is invalid, expired, accepted, or revoked.');
    }

    return $invite;
}

/**
 * @param array<string, mixed>|null $participant
 */
function glpichat_assert_valid_guest_participant(?array $participant): array
{
    if (!is_array($participant)) {
        throw new RuntimeException('Guest session is invalid.');
    }

    return $participant;
}

function glpichat_guest_join_followup_content(string $guest_name): string
{
    return sprintf('Guest %s joined the chat.', $guest_name);
}

function glpichat_guest_left_followup_content(string $guest_name): string
{
    return sprintf('Guest %s left the chat.', $guest_name);
}

function glpichat_guest_left_timeout_followup_content(string $guest_name): string
{
    return sprintf('Guest %s session expired due to inactivity.', $guest_name);
}

/**
 * @return array{session_token:string,participants_id:int,rooms_id:int,tickets_id:int,guest_name:string}
 */
function glpichat_accept_invite_use_case(
    string $token,
    callable $find_valid_invite,
    callable $generate_session_token,
    callable $create_guest_participant,
    callable $mark_invite_accepted,
    callable $record_event,
    callable $external_followup_user_id,
    callable $add_followup,
    callable $raise_notification
): array {
    $invite = glpichat_assert_valid_accept_invite_result($find_valid_invite(glpichat_guest_invite_token_hash($token)));
    $session_token = (string) $generate_session_token();
    $participants_id = (int) $create_guest_participant(
        (int) $invite['rooms_id'],
        (string) $invite['guest_name'],
        (string) ($invite['guest_email'] ?? ''),
        glpichat_guest_session_hash($session_token)
    );
    $accepted = $mark_invite_accepted((int) $invite['id'], $participants_id);
    if ($accepted === false) {
        throw new RuntimeException('Invite is invalid, expired, accepted, or revoked.');
    }
    $record_event(
        (int) $invite['rooms_id'],
        (int) $invite['tickets_id'],
        'guest_joined',
        GLPICHAT_ACTOR_GUEST,
        0,
        $participants_id,
        [
            'invite_id' => (int) $invite['id'],
            'guest_name' => (string) $invite['guest_name'],
        ]
    );
    $add_followup(
        (int) $invite['tickets_id'],
        (int) $external_followup_user_id(),
        glpichat_guest_join_followup_content((string) $invite['guest_name']),
        false
    );
    $raise_notification((int) $invite['tickets_id'], (string) $invite['guest_name']);

    return [
        'session_token' => $session_token,
        'participants_id' => $participants_id,
        'rooms_id' => (int) $invite['rooms_id'],
        'tickets_id' => (int) $invite['tickets_id'],
        'guest_name' => (string) $invite['guest_name'],
    ];
}

/**
 * @return array{messages:array<mixed>,events:array<mixed>,ticket_status:int,session_remaining_seconds:int,typing_names:array<string>}
 */
function glpichat_guest_sync_use_case(
    string $session_token,
    int $last_message_id,
    string $last_event_id,
    bool $is_typing,
    callable $guest_participant,
    callable $room_by_id,
    callable $messages,
    callable $events,
    callable $ticket_status,
    callable $update_activity,
    callable $typing_participants
): array {
    $participant = $guest_participant($session_token);
    $rooms_id = (int) ($participant['rooms_id'] ?? 0);
    $room = $room_by_id($rooms_id);
    $tickets_id = glpichat_guest_ticket_id_from_room($room);

    $update_activity((int) $participant['id'], $is_typing);

    global $DB;
    $ttl = 3600;
    if (isset($DB) && $DB !== null) {
        $ttl = (int) Config::getConfigurationValue('plugin:glpichat', 'guest_session_ttl');
    }
    if ($ttl <= 0) {
        $ttl = 3600;
    }
    $last_time = $participant['last_activity'] ?? $participant['joined_at'] ?? date('Y-m-d H:i:s');
    $elapsed = time() - strtotime($last_time);
    $remaining = max(0, $ttl - $elapsed);

    $joined_at = $participant['joined_at'] ?? '';

    $all_messages = $messages($rooms_id, $last_message_id);
    $filtered_messages = [];
    if ($joined_at !== '') {
        foreach ($all_messages as $msg) {
            if (($msg['date_creation'] ?? '') >= $joined_at) {
                $filtered_messages[] = $msg;
            }
        }
    } else {
        $filtered_messages = $all_messages;
    }

    $all_events = $events($rooms_id, $last_event_id);
    $filtered_events = [];
    if ($joined_at !== '') {
        foreach ($all_events as $evt) {
            if (($evt['date_creation'] ?? '') >= $joined_at) {
                $filtered_events[] = $evt;
            }
        }
    } else {
        $filtered_events = $all_events;
    }

    return [
        'messages' => $filtered_messages,
        'events' => $filtered_events,
        'ticket_status' => (int) $ticket_status($tickets_id),
        'session_remaining_seconds' => $remaining,
        'typing_names' => $typing_participants($rooms_id, (int) $participant['id']),
    ];
}

function glpichat_guest_leave_use_case(
    string $session_token,
    string $now,
    callable $guest_participant,
    callable $disconnect_participant
): void {
    $participant = $guest_participant($session_token);
    $disconnect_participant((int) ($participant['id'] ?? 0), $now);
}

function glpichat_guest_send_message_use_case(
    string $session_token,
    string $content,
    callable $guest_participant,
    callable $room_by_id,
    callable $assert_ticket_open_for_chat,
    callable $external_followup_user_id,
    callable $add_followup,
    callable $add_message
): int {
    $participant = $guest_participant($session_token);
    glpichat_assert_guest_not_throttled((int) ($participant['id'] ?? 0));
    $rooms_id = (int) ($participant['rooms_id'] ?? 0);
    $room = $room_by_id($rooms_id);
    $tickets_id = glpichat_guest_ticket_id_from_room($room);
    $validated_content = glpichat_validate_guest_message_content($content);
    $assert_ticket_open_for_chat($tickets_id);
    $followup_user_id = (int) $external_followup_user_id();
    $followups_id = (int) $add_followup($tickets_id, $followup_user_id, $validated_content, false);

    return (int) $add_message(
        $tickets_id,
        GLPICHAT_ROOM_PUBLIC,
        GLPICHAT_ACTOR_GUEST,
        0,
        (int) ($participant['id'] ?? 0),
        $validated_content,
        0,
        $followups_id
    );
}

/**
 * @return array{message_id:int,documents_id:int}
 */
function glpichat_guest_upload_message_use_case(
    string $session_token,
    mixed $file,
    callable $guest_participant,
    callable $room_by_id,
    callable $assert_ticket_open_for_chat,
    callable $upload_document,
    callable $external_followup_user_id,
    callable $add_followup,
    callable $add_message
): array {
    $validated_file = glpichat_validate_upload_input(1, GLPICHAT_ROOM_PUBLIC, $file);
    $participant = $guest_participant($session_token);
    glpichat_assert_guest_not_throttled((int) ($participant['id'] ?? 0));
    $rooms_id = (int) ($participant['rooms_id'] ?? 0);
    $room = $room_by_id($rooms_id);
    $tickets_id = glpichat_guest_ticket_id_from_room($room);
    $assert_ticket_open_for_chat($tickets_id);
    $documents_id = (int) $upload_document($tickets_id, $validated_file, true);
    $content = glpichat_guest_attachment_message_content($validated_file->getClientOriginalName());
    $followup_user_id = (int) $external_followup_user_id();
    $followups_id = (int) $add_followup($tickets_id, $followup_user_id, $content, false);
    $message_id = (int) $add_message(
        $tickets_id,
        GLPICHAT_ROOM_PUBLIC,
        GLPICHAT_ACTOR_GUEST,
        0,
        (int) ($participant['id'] ?? 0),
        $content,
        $documents_id,
        $followups_id
    );

    return [
        'message_id' => $message_id,
        'documents_id' => $documents_id,
    ];
}
