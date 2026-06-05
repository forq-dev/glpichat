<?php

declare(strict_types=1);

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function glpichat_message_output_row(array $row, string $sender_name): array
{
    return [
        'id' => (int) ($row['id'] ?? 0),
        'actor_type' => (string) ($row['participant_type'] ?? ''),
        'users_id' => (int) ($row['users_id'] ?? 0),
        'participants_id' => (int) ($row['participants_id'] ?? 0),
        'sender_name' => $sender_name,
        'message_type' => (string) ($row['message_type'] ?? ''),
        'content' => (string) ($row['content'] ?? ''),
        'documents_id' => (int) ($row['documents_id'] ?? 0),
        'itilfollowups_id' => (int) ($row['itilfollowups_id'] ?? 0),
        'date_creation' => (string) ($row['date_creation'] ?? ''),
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function glpichat_event_output_row(array $row): array
{
    return [
        'id' => (int) ($row['id'] ?? 0),
        'event_type' => (string) ($row['event_type'] ?? ''),
        'actor_type' => (string) ($row['actor_type'] ?? ''),
        'users_id' => (int) ($row['users_id'] ?? 0),
        'participants_id' => (int) ($row['participants_id'] ?? 0),
        'payload' => json_decode((string) ($row['payload_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR),
        'date_creation' => (string) ($row['date_creation'] ?? ''),
    ];
}

function glpichat_message_type_from_document_id(int $documents_id): string
{
    return $documents_id > 0 ? 'attachment' : 'message';
}

function glpichat_message_preview(string $content, string $fallback, int $max_length): string
{
    $normalized = trim($content);
    if ($normalized === '') {
        return $fallback;
    }

    if (strlen($normalized) <= $max_length) {
        return $normalized;
    }

    return substr($normalized, 0, $max_length - 3) . '...';
}

function glpichat_sender_name_for_user(int $users_id, string $user_name, string $fallback_prefix): string
{
    if ($users_id <= 0) {
        return '';
    }

    return $user_name !== '' ? $user_name : ($fallback_prefix . $users_id);
}

function glpichat_sender_name_for_guest(
    int $participants_id,
    ?string $guest_name,
    string $fallback_prefix,
    string $guest_suffix
): string {
    if ($participants_id <= 0) {
        return '';
    }

    $normalized_name = trim((string) $guest_name);
    if ($normalized_name === '') {
        return $fallback_prefix . $participants_id;
    }

    if ($guest_suffix === '') {
        return $normalized_name;
    }

    return $normalized_name . $guest_suffix;
}

function glpichat_sender_name_for_actor(
    string $actor_type,
    int $users_id,
    int $participants_id,
    string $user_name,
    ?string $guest_name,
    string $user_fallback_prefix,
    string $guest_fallback_prefix,
    string $guest_suffix,
    string $system_name
): string {
    if ($actor_type === GLPICHAT_ACTOR_USER) {
        return glpichat_sender_name_for_user($users_id, $user_name, $user_fallback_prefix);
    }

    if ($actor_type === GLPICHAT_ACTOR_GUEST) {
        return glpichat_sender_name_for_guest($participants_id, $guest_name, $guest_fallback_prefix, $guest_suffix);
    }

    return $system_name;
}
