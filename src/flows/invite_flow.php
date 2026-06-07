<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/domain/domain.php';

function glpichat_validate_invite_input(int $tickets_id, string $guest_name, string $guest_email): array
{
    $trimmed_name = trim($guest_name);
    $trimmed_email = trim($guest_email);
    if ($tickets_id <= 0 || $trimmed_name === '') {
        throw new RuntimeException('Invalid invite parameters.');
    }

    return [
        'tickets_id' => $tickets_id,
        'guest_name' => $trimmed_name,
        'guest_email' => $trimmed_email,
    ];
}

function glpichat_invite_url(string $url_base, string $token): string
{
    return rtrim($url_base, '/') . '/plugins/glpichat/Accept/' . $token;
}

function glpichat_invite_status(array $invite, int $now_timestamp): string
{
    if (!empty($invite['accepted_at'])) {
        return 'accepted';
    }

    if (!empty($invite['revoked_at'])) {
        return 'revoked';
    }

    $expires_at = strtotime((string) ($invite['expires_at'] ?? ''));
    if ($expires_at !== false && $expires_at <= $now_timestamp) {
        return 'expired';
    }

    return 'active';
}

function glpichat_guest_session_ttl_seconds(): int
{
    global $DB;

    $ttl = 3600;
    if (isset($DB) && $DB !== null) {
        $ttl = (int) Config::getConfigurationValue('plugin:glpichat', 'guest_session_ttl');
    }
    if ($ttl <= 0) {
        return 3600;
    }

    return $ttl;
}

function glpichat_is_participant_session_stale(array $invite, int $now_timestamp, int $guest_session_ttl_seconds): bool
{
    $participants_id = (int) ($invite['participants_id'] ?? 0);
    if ($participants_id <= 0) {
        return false;
    }

    $participant_left_at = trim((string) ($invite['participant_left_at'] ?? ''));
    if ($participant_left_at !== '') {
        return false;
    }

    $last_time = (string) ($invite['participant_last_activity'] ?? $invite['participant_joined_at'] ?? '');
    $last_timestamp = strtotime($last_time);
    if ($last_timestamp === false) {
        return false;
    }

    return ($now_timestamp - $last_timestamp) > $guest_session_ttl_seconds;
}

function glpichat_invite_session_status(array $invite, int $now_timestamp, int $guest_session_ttl_seconds): string
{
    if (empty($invite['accepted_at'])) {
        return 'not_joined';
    }

    $participants_id = (int) ($invite['participants_id'] ?? 0);
    $participant_left_at = trim((string) ($invite['participant_left_at'] ?? ''));
    if ($participants_id > 0 && $participant_left_at === '' && !glpichat_is_participant_session_stale($invite, $now_timestamp, $guest_session_ttl_seconds)) {
        return 'active';
    }

    if (!empty($invite['revoked_at'])) {
        return 'revoked';
    }

    return 'expired';
}

function glpichat_invite_management_status(string $invite_status, string $session_status): string
{
    if ($invite_status !== 'accepted') {
        return $invite_status;
    }

    if ($session_status === 'active') {
        return 'in_session';
    }

    if ($session_status === 'revoked') {
        return 'session_ended';
    }

    if ($session_status === 'expired') {
        return 'session_expired';
    }

    return 'accepted';
}

/**
 * @param array<string, mixed>|null $invite
 */
function glpichat_assert_invite_found(?array $invite): array
{
    if (!is_array($invite)) {
        throw new RuntimeException('Invite not found.');
    }

    return $invite;
}

/**
 * @return array<string, mixed>
 */
function glpichat_format_invite_row(array $invite, string $created_by_name, int $now_timestamp): array
{
    $guest_session_ttl_seconds = glpichat_guest_session_ttl_seconds();
    $invite_status = glpichat_invite_status($invite, $now_timestamp);
    $session_status = glpichat_invite_session_status($invite, $now_timestamp, $guest_session_ttl_seconds);
    $management_status = glpichat_invite_management_status($invite_status, $session_status);

    return [
        'id' => (int) $invite['id'],
        'guest_name' => (string) $invite['guest_name'],
        'guest_email' => (string) ($invite['guest_email'] ?? ''),
        'created_by' => (int) $invite['created_by'],
        'created_by_name' => $created_by_name,
        'date_creation' => (string) $invite['date_creation'],
        'expires_at' => (string) $invite['expires_at'],
        'accepted_at' => (string) ($invite['accepted_at'] ?? ''),
        'revoked_at' => (string) ($invite['revoked_at'] ?? ''),
        'was_accessed' => !empty($invite['accepted_at']),
        'participants_id' => (int) ($invite['participants_id'] ?? 0),
        'session_joined_at' => (string) ($invite['participant_joined_at'] ?? ''),
        'session_left_at' => (string) ($invite['participant_left_at'] ?? ''),
        'session_last_activity' => (string) ($invite['participant_last_activity'] ?? ''),
        'session_reason' => $session_status === 'revoked' ? 'revoked' : ($session_status === 'expired' ? 'timeout' : ''),
        'status' => $invite_status,
        'invite_status' => $invite_status,
        'session_status' => $session_status,
        'management_status' => $management_status,
        'is_session_active' => $session_status === 'active',
    ];
}

function glpichat_create_invite_use_case(
    int $tickets_id,
    string $guest_name,
    string $guest_email,
    string $url_base,
    callable $assert_can_create_invites,
    callable $room_id,
    callable $generate_token,
    callable $current_user_id,
    callable $insert_invite,
    callable $record_event,
    int $invite_ttl_seconds
): array {
    $validated = glpichat_validate_invite_input($tickets_id, $guest_name, $guest_email);
    $assert_can_create_invites((int) $validated['tickets_id']);

    $rooms_id = (int) $room_id((int) $validated['tickets_id'], GLPICHAT_ROOM_PUBLIC);
    $token = (string) $generate_token();
    $token_hash = glpichat_guest_invite_token_hash($token);
    $expires_at = date('Y-m-d H:i:s', time() + $invite_ttl_seconds);
    $created_by = (int) $current_user_id();
    $invite_id = (int) $insert_invite(
        $rooms_id,
        (int) $validated['tickets_id'],
        $token_hash,
        $created_by,
        (string) $validated['guest_name'],
        (string) $validated['guest_email'],
        $expires_at
    );
    $record_event($rooms_id, (int) $validated['tickets_id'], 'guest_invited', GLPICHAT_ACTOR_USER, $created_by, 0, [
        'invite_id' => $invite_id,
        'guest_name' => (string) $validated['guest_name'],
        'expires_at' => $expires_at,
    ]);

    return [
        'id' => $invite_id,
        'expires_at' => $expires_at,
        'url' => glpichat_invite_url($url_base, $token),
    ];
}

/**
 * @param array<int, array<string, mixed>> $invites
 * @return array<int, array<string, mixed>>
 */
function glpichat_list_invites_use_case(
    int $tickets_id,
    callable $assert_can_read_ticket,
    callable $assert_can_view_participants,
    callable $fetch_invites,
    callable $resolve_user_name,
    int $now_timestamp
): array {
    if ($tickets_id <= 0) {
        throw new RuntimeException('Invalid invite list parameters.');
    }

    $assert_can_read_ticket($tickets_id);
    $assert_can_view_participants($tickets_id);

    $rows = [];
    foreach ($fetch_invites($tickets_id) as $invite) {
        $created_by = (int) ($invite['created_by'] ?? 0);
        $created_by_name = $resolve_user_name($created_by);
        $rows[] = glpichat_format_invite_row(
            $invite,
            $created_by_name !== '' ? $created_by_name : ('User #' . $created_by),
            $now_timestamp
        );
    }

    return $rows;
}

function glpichat_revoke_invite_use_case(
    int $invite_id,
    callable $find_invite,
    callable $assert_can_update_invites,
    callable $current_user_id,
    callable $mark_invite_revoked,
    callable $record_event,
    callable $disconnect_participant,
    string $now
): void {
    $invite = glpichat_assert_invite_found($find_invite($invite_id));
    $tickets_id = (int) $invite['tickets_id'];
    $assert_can_update_invites($tickets_id);
    if (!empty($invite['revoked_at'])) {
        throw new RuntimeException('Invite cannot be revoked.');
    }

    $mark_invite_revoked($invite_id, $now);
    
    $participants_id = (int) ($invite['participants_id'] ?? 0);
    if ($participants_id > 0) {
        $disconnect_participant($participants_id, $now);
    } else {
        $record_event((int) $invite['rooms_id'], $tickets_id, 'invite_revoked', GLPICHAT_ACTOR_USER, (int) $current_user_id(), 0, [
            'invite_id' => $invite_id,
        ]);
    }
}

function glpichat_reissue_invite_use_case(
    int $invite_id,
    string $url_base,
    callable $find_invite,
    callable $assert_can_update_invites,
    callable $generate_token,
    callable $current_user_id,
    callable $update_invite_reissue,
    callable $record_event,
    int $invite_ttl_seconds
): array {
    $invite = glpichat_assert_invite_found($find_invite($invite_id));
    $tickets_id = (int) $invite['tickets_id'];
    $assert_can_update_invites($tickets_id);
    if (!empty($invite['accepted_at'])) {
        throw new RuntimeException('Accepted invite cannot be reissued.');
    }
    if (!empty($invite['revoked_at'])) {
        throw new RuntimeException('Revoked invite cannot be reissued.');
    }

    $token = (string) $generate_token();
    $expires_at = date('Y-m-d H:i:s', time() + $invite_ttl_seconds);
    $update_invite_reissue($invite_id, glpichat_guest_invite_token_hash($token), $expires_at);
    $record_event((int) $invite['rooms_id'], $tickets_id, 'invite_reissued', GLPICHAT_ACTOR_USER, (int) $current_user_id(), 0, [
        'invite_id' => $invite_id,
        'expires_at' => $expires_at,
    ]);

    return [
        'id' => $invite_id,
        'expires_at' => $expires_at,
        'url' => glpichat_invite_url($url_base, $token),
    ];
}
