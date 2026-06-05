<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/unit.php';
require_once dirname(__DIR__, 3) . '/src/flows/notification_flow.php';

$public_message = glpichat_notification_message_data(
    'glpichat_public_message',
    41,
    7,
    static function (int $tickets_id, string $room_type): int {
        glpichat_unit_assert_same(41, $tickets_id, 'Notification helper passes ticket id to room resolver');
        glpichat_unit_assert_same(GLPICHAT_ROOM_PUBLIC, $room_type, 'Notification helper resolves public room for public event');
        return 501;
    },
    static function (int $users_id, int $tickets_id, string $room_type): bool {
        return $users_id === 7 && $tickets_id === 41 && $room_type === GLPICHAT_ROOM_PUBLIC;
    },
    static function (int $rooms_id): array {
        glpichat_unit_assert_same(501, $rooms_id, 'Notification helper loads latest message from the resolved room');
        return [
            'sender_name' => 'Tecnico #7',
            'preview' => 'Mensagem publica',
        ];
    },
    static function (int $users_id, int $rooms_id): int {
        glpichat_unit_assert_same(7, $users_id, 'Notification helper passes recipient user to unread counter');
        glpichat_unit_assert_same(501, $rooms_id, 'Notification helper scopes unread count to the resolved room');
        return 3;
    },
    'glpichat_display_unread_count'
);

glpichat_unit_assert_same_array(
    [
        'sender_name' => 'Tecnico #7',
        'preview' => 'Mensagem publica',
        'unread_count' => '3',
        'unread_badge' => '(3)',
    ],
    $public_message,
    'Notification helper keeps preview and unread scoped to the authorized public room'
);
glpichat_unit_pass('Notification helper keeps preview and unread scoped to the authorized public room');

$internal_hidden = glpichat_notification_message_data(
    'glpichat_internal_message',
    41,
    9,
    static function (int $tickets_id, string $room_type): int {
        glpichat_unit_fail('Notification helper must not resolve room when recipient cannot read it');
    },
    static function (int $users_id, int $tickets_id, string $room_type): bool {
        return false;
    },
    static function (int $rooms_id): array {
        glpichat_unit_fail('Notification helper must not load internal preview for unauthorized recipient');
    },
    static function (int $users_id, int $rooms_id): int {
        glpichat_unit_fail('Notification helper must not count internal unread for unauthorized recipient');
    },
    'glpichat_display_unread_count'
);

glpichat_unit_assert_same_array(
    [
        'sender_name' => 'Sistema',
        'preview' => 'Nova mensagem no chat',
        'unread_count' => '0',
        'unread_badge' => '',
    ],
    $internal_hidden,
    'Notification helper returns neutral content for unauthorized internal event recipients'
);
glpichat_unit_pass('Notification helper returns neutral content for unauthorized internal event recipients');

glpichat_unit_assert_same(
    GLPICHAT_ROOM_PUBLIC,
    glpichat_notification_room_type('glpichat_guest_message'),
    'Guest message notifications stay scoped to the public room'
);
glpichat_unit_pass('Guest message notifications stay scoped to the public room');

fwrite(STDOUT, "[PASS] Notification flow unit tests completed\n");
