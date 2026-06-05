<?php

declare(strict_types=1);

/**
 * @param array<string, mixed> $participant
 */
function glpichat_has_valid_room_participant_link(array $participant): bool
{
    return (int) ($participant['rooms_id'] ?? 0) > 0
        && (int) ($participant['id'] ?? 0) > 0;
}

/**
 * @param array<int, array<string, mixed>> $participants
 */
function glpichat_sum_unread_for_participants(array $participants, callable $count_unread): int
{
    $total_unread = 0;

    foreach ($participants as $participant) {
        if (!glpichat_has_valid_room_participant_link($participant)) {
            continue;
        }

        $total_unread += (int) $count_unread(
            (int) $participant['rooms_id'],
            (int) $participant['id']
        );
    }

    return $total_unread;
}

function glpichat_channel_key(int $tickets_id, string $room_type): string
{
    return $tickets_id . ':' . $room_type;
}

/**
 * @return array<string, int|string>
 */
function glpichat_channel_output_row(
    int $rooms_id,
    int $tickets_id,
    string $room_type,
    int $participants_id,
    int $unread_count,
    int $last_message_id,
    int $ticket_status
): array {
    return [
        'rooms_id' => $rooms_id,
        'tickets_id' => $tickets_id,
        'room_type' => $room_type,
        'participants_id' => $participants_id,
        'unread_count' => $unread_count,
        'last_message_id' => $last_message_id,
        'ticket_status' => $ticket_status,
    ];
}
