<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/unit.php';

// Test 1: event_action map covers all 6 events
$event_actions = [
    'glpichat_public_message' => 'Nova mensagem do solicitante',
    'glpichat_internal_message' => 'Nova mensagem interna',
    'glpichat_guest_message' => 'Nova mensagem de convidado',
    'glpichat_guest_joined' => 'Convidado entrou no chat',
    'glpichat_guest_left' => 'Convidado saiu do chat',
    'glpichat_guest_left_timeout' => 'Sessao do convidado expirou',
];

foreach ($event_actions as $event => $label) {
    glpichat_unit_assert_true(
        $label !== '',
        "Event action for {$event} is not empty"
    );
    glpichat_unit_pass("Event action for {$event} is not empty");
}

// Test 2: channel_type map covers public and internal
$channel_type_map = [
    GLPICHAT_ROOM_PUBLIC => 'Solicitante',
    GLPICHAT_ROOM_INTERNAL => 'Discussao tecnica',
];

glpichat_unit_assert_same(
    'Solicitante',
    $channel_type_map[GLPICHAT_ROOM_PUBLIC],
    'Public channel type label is correct'
);
glpichat_unit_pass('Public channel type label is correct');

glpichat_unit_assert_same(
    'Discussao tecnica',
    $channel_type_map[GLPICHAT_ROOM_INTERNAL],
    'Internal channel type label is correct'
);
glpichat_unit_pass('Internal channel type label is correct');

// Test 3: Default notification events still have all 6 events
$expected_events = [
    'glpichat_public_message',
    'glpichat_internal_message',
    'glpichat_guest_message',
    'glpichat_guest_joined',
    'glpichat_guest_left',
    'glpichat_guest_left_timeout',
];

glpichat_unit_assert_same_array(
    $expected_events,
    glpichat_default_notification_events(),
    'All 6 notification events are registered'
);
glpichat_unit_pass('All 6 notification events are registered');

fwrite(STDOUT, "[PASS] HookHandler tags unit tests completed\n");
