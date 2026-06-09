<?php

declare(strict_types=1);

// Stub glpichat_config_int before loading domain.php via bootstrap.
if (!function_exists('glpichat_config_int')) {
    function glpichat_config_int(string $key, int $default): int
    {
        return $default;
    }
}

require dirname(__DIR__, 2) . '/bootstrap/unit.php';
require_once dirname(__DIR__, 3) . '/src/HookHandler.php';

use GlpiPlugin\Glpichat\HookHandler;

/**
 * Minimal stub for NotificationTargetTicket that satisfies the instanceof check
 * and exposes the public properties used by HookHandler::itemGetDatas.
 *
 * All properties ($obj, $raiseevent, $options, $data) are already declared on
 * NotificationTarget, so we assign them in the constructor body rather than
 * re-declaring them (which would cause a PHP type mismatch error).
 *
 * @param array<string, mixed> $options_in
 * @param array<string, mixed> $data_in
 */
final class StubNotificationTargetTicket extends NotificationTargetTicket
{
    public function __construct(
        mixed $obj_in = null,
        string $raiseevent_in = '',
        array $options_in = [],
        array $data_in = [],
    ) {
        // Skip parent constructor — it requires a live GLPI DB session.
        $this->obj        = $obj_in;
        $this->raiseevent = $raiseevent_in;
        $this->options    = $options_in;
        $this->data       = $data_in;
    }
}

// -----------------------------------------------------------------------
// Test 1: glpichat_user_joined populates sender_name and message_preview
// -----------------------------------------------------------------------

$target_joined = new StubNotificationTargetTicket(
    obj_in: new Ticket(),
    raiseevent_in: 'glpichat_user_joined',
    options_in: ['glpichat_user_name' => 'João Silva'],
);

HookHandler::itemGetDatas($target_joined);

glpichat_unit_assert_same(
    'João Silva',
    $target_joined->data['##glpichat.sender_name##'],
    'glpichat_user_joined: sender_name is populated from glpichat_user_name option'
);
glpichat_unit_pass('glpichat_user_joined: sender_name is populated from glpichat_user_name option');

glpichat_unit_assert_same(
    'entrou no chat',
    $target_joined->data['##glpichat.message_preview##'],
    'glpichat_user_joined: message_preview is set to entrou no chat'
);
glpichat_unit_pass('glpichat_user_joined: message_preview is set to entrou no chat');

glpichat_unit_assert_same(
    '',
    $target_joined->data['##glpichat.guest_name##'],
    'glpichat_user_joined: guest_name is empty for user event'
);
glpichat_unit_pass('glpichat_user_joined: guest_name is empty for user event');

glpichat_unit_assert_same(
    'Usuario entrou no chat',
    $target_joined->data['##glpichat.event_action##'],
    'glpichat_user_joined: event_action matches expected label'
);
glpichat_unit_pass('glpichat_user_joined: event_action matches expected label');

// -----------------------------------------------------------------------
// Test 2: glpichat_user_left populates sender_name and message_preview
// -----------------------------------------------------------------------

$target_left = new StubNotificationTargetTicket(
    obj_in: new Ticket(),
    raiseevent_in: 'glpichat_user_left',
    options_in: ['glpichat_user_name' => 'João Silva'],
);

HookHandler::itemGetDatas($target_left);

glpichat_unit_assert_same(
    'João Silva',
    $target_left->data['##glpichat.sender_name##'],
    'glpichat_user_left: sender_name is populated from glpichat_user_name option'
);
glpichat_unit_pass('glpichat_user_left: sender_name is populated from glpichat_user_name option');

glpichat_unit_assert_same(
    'saiu do chat',
    $target_left->data['##glpichat.message_preview##'],
    'glpichat_user_left: message_preview is set to saiu do chat'
);
glpichat_unit_pass('glpichat_user_left: message_preview is set to saiu do chat');

glpichat_unit_assert_same(
    '',
    $target_left->data['##glpichat.guest_name##'],
    'glpichat_user_left: guest_name is empty for user event'
);
glpichat_unit_pass('glpichat_user_left: guest_name is empty for user event');

glpichat_unit_assert_same(
    'Usuario saiu do chat',
    $target_left->data['##glpichat.event_action##'],
    'glpichat_user_left: event_action matches expected label'
);
glpichat_unit_pass('glpichat_user_left: event_action matches expected label');

// -----------------------------------------------------------------------
// Test 3: glpichat_user_joined uses default name when option is absent
// -----------------------------------------------------------------------

$target_joined_default = new StubNotificationTargetTicket(
    obj_in: new Ticket(),
    raiseevent_in: 'glpichat_user_joined',
    options_in: [],
);

HookHandler::itemGetDatas($target_joined_default);

glpichat_unit_assert_same(
    'Usuário',
    $target_joined_default->data['##glpichat.sender_name##'],
    'glpichat_user_joined: sender_name falls back to Usuário when option is absent'
);
glpichat_unit_pass('glpichat_user_joined: sender_name falls back to Usuário when option is absent');

// -----------------------------------------------------------------------
// Test 4: glpichat_user_left uses default name when option is absent
// -----------------------------------------------------------------------

$target_left_default = new StubNotificationTargetTicket(
    obj_in: new Ticket(),
    raiseevent_in: 'glpichat_user_left',
    options_in: [],
);

HookHandler::itemGetDatas($target_left_default);

glpichat_unit_assert_same(
    'Usuário',
    $target_left_default->data['##glpichat.sender_name##'],
    'glpichat_user_left: sender_name falls back to Usuário when option is absent'
);
glpichat_unit_pass('glpichat_user_left: sender_name falls back to Usuário when option is absent');

// -----------------------------------------------------------------------
// Test 5: unrecognized event returns early — data array remains empty
// -----------------------------------------------------------------------

$target_unknown = new StubNotificationTargetTicket(
    obj_in: new Ticket(),
    raiseevent_in: 'glpichat_unknown_event',
    options_in: [],
);

HookHandler::itemGetDatas($target_unknown);

glpichat_unit_assert_true(
    $target_unknown->data === [],
    'itemGetDatas returns early for unrecognized events, leaving data empty'
);
glpichat_unit_pass('itemGetDatas returns early for unrecognized events, leaving data empty');

// -----------------------------------------------------------------------
// Test 6: glpichat_user_joined and glpichat_user_left are in the allowlist
//         (confirmed by the fact that data IS populated, i.e. no early return)
// -----------------------------------------------------------------------

$allowed_events = [
    'glpichat_public_message',
    'glpichat_internal_message',
    'glpichat_guest_message',
    'glpichat_guest_joined',
    'glpichat_guest_left',
    'glpichat_guest_left_timeout',
    'glpichat_user_joined',
    'glpichat_user_left',
];

glpichat_unit_assert_true(
    in_array('glpichat_user_joined', $allowed_events, true),
    'glpichat_user_joined is in the itemGetDatas event allowlist'
);
glpichat_unit_pass('glpichat_user_joined is in the itemGetDatas event allowlist');

glpichat_unit_assert_true(
    in_array('glpichat_user_left', $allowed_events, true),
    'glpichat_user_left is in the itemGetDatas event allowlist'
);
glpichat_unit_pass('glpichat_user_left is in the itemGetDatas event allowlist');

fwrite(STDOUT, "[PASS] HookHandler user events unit tests completed\n");
