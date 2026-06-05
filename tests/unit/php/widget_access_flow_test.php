<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/unit.php';
require_once dirname(__DIR__, 3) . '/src/flows/widget_access_flow.php';

$room = glpichat_widget_readable_room_or_fail(
    5,
    static function (int $rooms_id): array {
        return [
            'id' => $rooms_id,
            'tickets_id' => 12,
            'room_type' => GLPICHAT_ROOM_PUBLIC,
        ];
    },
    static function (int $tickets_id, string $room_type): void {
        if ($tickets_id !== 12 || $room_type !== GLPICHAT_ROOM_PUBLIC) {
            throw new RuntimeException('Unexpected room payload.');
        }
    }
);

glpichat_unit_assert_same_array(
    [
        'id' => 5,
        'tickets_id' => 12,
        'room_type' => GLPICHAT_ROOM_PUBLIC,
    ],
    $room,
    'Widget access helper returns readable room untouched'
);
glpichat_unit_pass('Widget access helper returns readable room untouched');

foreach (['Chat room not found.', 'User cannot read this room.'] as $raw_message) {
    try {
        glpichat_widget_readable_room_or_fail(
            9,
            static function (int $rooms_id) use ($raw_message): array {
                throw new RuntimeException($raw_message);
            },
            static function (int $tickets_id, string $room_type): void {
            }
        );
        glpichat_unit_fail('Widget access helper must collapse room lookup and ACL failures.');
    } catch (RuntimeException $error) {
        glpichat_unit_assert_same(
            'Room unavailable.',
            $error->getMessage(),
            'Widget access helper returns canonical unavailable message'
        );
    }
}
glpichat_unit_pass('Widget access helper collapses lookup and ACL failures');

fwrite(STDOUT, "[PASS] Widget access flow unit tests completed\n");
