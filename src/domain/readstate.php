<?php

declare(strict_types=1);

/**
 * @param array<string, mixed>|null $participant
 * @return array<string, mixed>
 */
function glpichat_assert_valid_readstate_participant(?array $participant): array
{
    if (!is_array($participant)) {
        throw new RuntimeException('Chat participant not found.');
    }

    return $participant;
}

/**
 * @param array<string, mixed> $participant
 * @return array<int|string, mixed>
 */
function glpichat_unread_where_for_participant(array $participant, int $last_read_messages_id): array
{
    $rooms_id = (int) ($participant['rooms_id'] ?? 0);
    $participants_id = (int) ($participant['id'] ?? 0);
    $participant_type = (string) ($participant['participant_type'] ?? '');
    $users_id = (int) ($participant['users_id'] ?? 0);

    $where = [
        'rooms_id' => $rooms_id,
        ['id' => ['>', $last_read_messages_id]],
    ];

    if ($participant_type === GLPICHAT_ACTOR_USER && $users_id > 0) {
        $where[] = [
            'NOT' => [
                'participant_type' => GLPICHAT_ACTOR_USER,
                'users_id' => $users_id,
            ],
        ];
    }

    if ($participant_type === GLPICHAT_ACTOR_GUEST && $participants_id > 0) {
        $where[] = [
            'NOT' => [
                'participants_id' => $participants_id,
            ],
        ];
    }

    return $where;
}

function glpichat_next_readstate_message_id(int $current_last_read_messages_id, int $requested_last_read_messages_id): int
{
    return max(0, $current_last_read_messages_id, $requested_last_read_messages_id);
}

/**
 * @return array<string, int|string>
 */
function glpichat_readstate_update_payload(int $current_last_read_messages_id, int $requested_last_read_messages_id, string $date_mod): array
{
    return [
        'last_read_messages_id' => glpichat_next_readstate_message_id($current_last_read_messages_id, $requested_last_read_messages_id),
        'date_mod' => $date_mod,
    ];
}

/**
 * @return array<string, int|string>
 */
function glpichat_readstate_insert_payload(int $rooms_id, int $participants_id, int $requested_last_read_messages_id, string $date_mod): array
{
    return [
        'rooms_id' => $rooms_id,
        'participants_id' => $participants_id,
        'last_read_messages_id' => glpichat_next_readstate_message_id(0, $requested_last_read_messages_id),
        'date_mod' => $date_mod,
    ];
}
