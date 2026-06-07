<?php

declare(strict_types=1);

function glpichat_widget_readable_room_or_fail(
    int $rooms_id,
    callable $room_by_id,
    callable $assert_can_read_room
): array {
    try {
        $room = $room_by_id($rooms_id);
        $assert_can_read_room((int) ($room['tickets_id'] ?? 0), (string) ($room['room_type'] ?? ''));

        return $room;
    } catch (RuntimeException $error) {
        throw new RuntimeException('Access denied.', 403, $error);
    }
}
