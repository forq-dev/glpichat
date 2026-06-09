<?php

declare(strict_types=1);

// Stub glpichat_config_int before loading domain.php via bootstrap.
// domain.php calls glpichat_config_int (defined in functions.php) at runtime
// inside glpichat_display_unread_count and glpichat_invite_ttl_seconds.
// In the unit context the stub returns the provided default, keeping the
// function pure and independent of the GLPI Config DB.
if (!function_exists('glpichat_config_int')) {
    function glpichat_config_int(string $key, int $default): int
    {
        return $default;
    }
}

require dirname(__DIR__, 2) . '/bootstrap/unit.php';

glpichat_unit_assert_same_array(
    [GLPICHAT_ROOM_PUBLIC, GLPICHAT_ROOM_INTERNAL],
    glpichat_room_types(),
    'Room types stay canonical'
);
glpichat_unit_pass('Room types stay canonical');

glpichat_unit_assert_same_array(
    [GLPICHAT_ACTOR_USER, GLPICHAT_ACTOR_GUEST],
    glpichat_actor_types(),
    'Actor types stay canonical'
);
glpichat_unit_pass('Actor types stay canonical');

glpichat_unit_assert_true(
    glpichat_is_valid_room_type(GLPICHAT_ROOM_PUBLIC),
    'Public room type is valid'
);
glpichat_unit_pass('Public room type is valid');

glpichat_unit_assert_true(
    !glpichat_is_valid_room_type('invalid'),
    'Invalid room type is rejected'
);
glpichat_unit_pass('Invalid room type is rejected');

glpichat_unit_assert_true(
    glpichat_is_valid_actor_type(GLPICHAT_ACTOR_GUEST),
    'Guest actor type is valid'
);
glpichat_unit_pass('Guest actor type is valid');

glpichat_unit_assert_true(
    !glpichat_is_valid_actor_type('robot'),
    'Invalid actor type is rejected'
);
glpichat_unit_pass('Invalid actor type is rejected');

glpichat_unit_assert_same(
    'glpichat_public_message',
    glpichat_message_notification_event_name(GLPICHAT_ROOM_PUBLIC, GLPICHAT_ACTOR_USER),
    'Public user message maps to public notification event'
);
glpichat_unit_pass('Public user message maps to public notification event');

glpichat_unit_assert_same(
    'glpichat_internal_message',
    glpichat_message_notification_event_name(GLPICHAT_ROOM_INTERNAL, GLPICHAT_ACTOR_USER),
    'Internal user message maps to internal notification event'
);
glpichat_unit_pass('Internal user message maps to internal notification event');

glpichat_unit_assert_same(
    'glpichat_guest_message',
    glpichat_message_notification_event_name(GLPICHAT_ROOM_PUBLIC, GLPICHAT_ACTOR_GUEST),
    'Guest message maps to guest notification event'
);
glpichat_unit_pass('Guest message maps to guest notification event');

glpichat_unit_assert_true(
    glpichat_is_ticket_terminal_status(CommonITILObject::SOLVED),
    'Solved status is terminal'
);
glpichat_unit_pass('Solved status is terminal');

glpichat_unit_assert_true(
    glpichat_is_ticket_terminal_status(CommonITILObject::CLOSED),
    'Closed status is terminal'
);
glpichat_unit_pass('Closed status is terminal');

glpichat_unit_assert_true(
    !glpichat_is_ticket_terminal_status(CommonITILObject::INCOMING),
    'Incoming status is not terminal'
);
glpichat_unit_pass('Incoming status is not terminal');

glpichat_unit_assert_same(
    0,
    glpichat_display_unread_count(0),
    'Zero unread remains hidden'
);
glpichat_unit_pass('Zero unread remains hidden');

glpichat_unit_assert_same(
    0,
    glpichat_display_unread_count(1),
    'Single unread remains hidden'
);
glpichat_unit_pass('Single unread remains hidden');

glpichat_unit_assert_same(
    2,
    glpichat_display_unread_count(2),
    'Unread count above one remains visible'
);
glpichat_unit_pass('Unread count above one remains visible');

glpichat_unit_assert_same_array(
    ['glpichat_public_message', 'glpichat_internal_message', 'glpichat_guest_message', 'glpichat_guest_joined', 'glpichat_guest_left', 'glpichat_guest_left_timeout', 'glpichat_user_joined', 'glpichat_user_left'],
    glpichat_default_notification_events(),
    'Default notification events stay ordered'
);
glpichat_unit_pass('Default notification events stay ordered');

glpichat_unit_assert_same(
    'GLPI CHAT - Notificacao interna',
    glpichat_default_notification_definition_name('glpichat_internal_message'),
    'Internal notification keeps expected label'
);
glpichat_unit_pass('Internal notification keeps expected label');

glpichat_unit_assert_same(
    'GLPI CHAT - Notificacao convidado saiu',
    glpichat_default_notification_definition_name('glpichat_guest_left'),
    'Guest left notification keeps expected label'
);
glpichat_unit_pass('Guest left notification keeps expected label');

glpichat_unit_assert_same(
    'GLPI CHAT - Notificacao sessao convidado expirada',
    glpichat_default_notification_definition_name('glpichat_guest_left_timeout'),
    'Guest timeout notification keeps expected label'
);
glpichat_unit_pass('Guest timeout notification keeps expected label');

glpichat_unit_assert_same(
    '##ticket.id## - ##ticket.title##',
    glpichat_default_template_subject(),
    'Default subject uses native GLPI ticket tags'
);
glpichat_unit_pass('Default subject uses native GLPI ticket tags');

glpichat_unit_assert_same(
    '##glpichat.sender_name##: ##glpichat.message_preview##',
    glpichat_default_template_text(),
    'Default text template uses sender_name and message_preview tags'
);
glpichat_unit_pass('Default text template uses sender_name and message_preview tags');

glpichat_unit_assert_same(
    '<p><strong>##glpichat.sender_name##</strong>: ##glpichat.message_preview##</p>',
    glpichat_default_template_html(),
    'Default HTML template uses sender_name and message_preview tags'
);
glpichat_unit_pass('Default HTML template uses sender_name and message_preview tags');

glpichat_unit_assert_same(
    'GLPI CHAT - Notificacao usuario entrou',
    glpichat_default_notification_definition_name('glpichat_user_joined'),
    'User joined notification keeps expected label'
);
glpichat_unit_pass('User joined notification keeps expected label');

glpichat_unit_assert_same(
    'GLPI CHAT - Notificacao usuario saiu',
    glpichat_default_notification_definition_name('glpichat_user_left'),
    'User left notification keeps expected label'
);
glpichat_unit_pass('User left notification keeps expected label');

fwrite(STDOUT, "[PASS] Domain unit tests completed\n");
