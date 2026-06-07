<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/domain/domain.php';

/**
 * @return array{authorized:bool,sender_name:string,preview:string,unread_count:string,unread_badge:string}
 */
function glpichat_notification_message_data(
    string $event,
    int $tickets_id,
    int $users_id,
    callable $room_id,
    callable $can_user_read_notification_room,
    callable $latest_message_for_room,
    callable $unread_count_for_user_room,
    callable $display_unread_count
): array {
    $unauthorized_fallback = [
        'authorized' => false,
        'sender_name' => '',
        'preview' => '',
        'unread_count' => '0',
        'unread_badge' => '',
    ];

    $room_type = glpichat_notification_room_type($event);
    if ($room_type === null) {
        return $unauthorized_fallback;
    }

    if (!$can_user_read_notification_room($users_id, $tickets_id, $room_type)) {
        return $unauthorized_fallback;
    }

    $rooms_id = (int) $room_id($tickets_id, $room_type);
    $message = $latest_message_for_room($rooms_id);
    $raw_unread = $users_id > 0 ? (int) $unread_count_for_user_room($users_id, $rooms_id) : 0;
    $display_unread = (int) $display_unread_count($raw_unread);

    return [
        'authorized' => true,
        'sender_name' => (string) ($message['sender_name'] ?? 'Sistema'),
        'preview' => (string) ($message['preview'] ?? 'Nova mensagem no chat'),
        'unread_count' => (string) $display_unread,
        'unread_badge' => $display_unread > 0 ? sprintf('(%d)', $display_unread) : '',
    ];
}

function glpichat_notification_room_type(string $event): ?string
{
    return match ($event) {
        'glpichat_public_message', 'glpichat_guest_message' => GLPICHAT_ROOM_PUBLIC,
        'glpichat_internal_message' => GLPICHAT_ROOM_INTERNAL,
        default => null,
    };
}
