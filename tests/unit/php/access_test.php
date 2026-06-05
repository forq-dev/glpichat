<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/unit.php';
require_once dirname(__DIR__, 3) . '/src/domain/access.php';

glpichat_unit_assert_same(
    'plugin_glpichat_public',
    glpichat_read_right_name(GLPICHAT_ROOM_PUBLIC),
    'Public room maps to consolidated public right'
);
glpichat_unit_pass('Public room maps to consolidated public right');

glpichat_unit_assert_same(
    'plugin_glpichat_internal',
    glpichat_read_right_name(GLPICHAT_ROOM_INTERNAL),
    'Internal room maps to consolidated internal right'
);
glpichat_unit_pass('Internal room maps to consolidated internal right');

glpichat_unit_assert_true(
    glpichat_can_bootstrap_widget_from_state(true, false),
    'Widget bootstrap is allowed when public read is granted'
);
glpichat_unit_pass('Widget bootstrap is allowed when public read is granted');

glpichat_unit_assert_true(
    !glpichat_can_bootstrap_widget_from_state(false, false),
    'Widget bootstrap is denied without any channel read permission'
);
glpichat_unit_pass('Widget bootstrap is denied without any channel read permission');

glpichat_unit_assert_true(
    glpichat_can_read_ticket_from_state(true, true, false),
    'Ticket chat access is allowed when GLPI read and any channel read are granted'
);
glpichat_unit_pass('Ticket chat access is allowed when GLPI read and any channel read are granted');

glpichat_unit_assert_true(
    !glpichat_can_read_ticket_from_state(true, false, false),
    'Ticket chat access is denied without channel read rights'
);
glpichat_unit_pass('Ticket chat access is denied without channel read rights');

glpichat_unit_assert_true(
    glpichat_can_read_room_from_state(true, GLPICHAT_ROOM_PUBLIC, true, false),
    'Public room is readable with public read right'
);
glpichat_unit_pass('Public room is readable with public read right');

glpichat_unit_assert_true(
    !glpichat_can_read_room_from_state(true, GLPICHAT_ROOM_PUBLIC, false, true),
    'Public room is denied without public read right'
);
glpichat_unit_pass('Public room is denied without public read right');

glpichat_unit_assert_true(
    glpichat_can_read_room_from_state(true, GLPICHAT_ROOM_INTERNAL, false, true),
    'Internal room is readable with internal read right'
);
glpichat_unit_pass('Internal room is readable with internal read right');

glpichat_unit_assert_true(
    !glpichat_can_read_room_from_state(false, GLPICHAT_ROOM_INTERNAL, false, true),
    'Room read is denied without base ticket read access'
);
glpichat_unit_pass('Room read is denied without base ticket read access');

glpichat_unit_assert_true(
    glpichat_can_send_from_state(true, false, false, GLPICHAT_ROOM_PUBLIC, true, false),
    'Public send is allowed with public read and public create right'
);
glpichat_unit_pass('Public send is allowed with public read and public create right');

glpichat_unit_assert_true(
    !glpichat_can_send_from_state(true, false, true, GLPICHAT_ROOM_PUBLIC, true, false),
    'Public send is denied for terminal ticket'
);
glpichat_unit_pass('Public send is denied for terminal ticket');

glpichat_unit_assert_true(
    !glpichat_can_send_from_state(true, false, false, GLPICHAT_ROOM_INTERNAL, true, false),
    'Internal send is denied without internal create right'
);
glpichat_unit_pass('Internal send is denied without internal create right');

glpichat_unit_assert_true(
    glpichat_can_send_from_state(true, true, false, GLPICHAT_ROOM_INTERNAL, true, true),
    'Internal send is allowed with internal read and internal create right'
);
glpichat_unit_pass('Internal send is allowed with internal read and internal create right');

glpichat_unit_assert_true(
    !glpichat_can_send_from_state(true, false, false, 'weird', true, true),
    'Send is denied for invalid room type'
);
glpichat_unit_pass('Send is denied for invalid room type');

glpichat_unit_assert_true(
    glpichat_can_view_participants_from_state(true, true),
    'Participants are visible when public room read and invite read are allowed'
);
glpichat_unit_pass('Participants are visible when public room read and invite read are allowed');

glpichat_unit_assert_true(
    !glpichat_can_view_participants_from_state(true, false),
    'Participants are hidden without invite read right'
);
glpichat_unit_pass('Participants are hidden without invite read right');

glpichat_unit_assert_true(
    glpichat_can_create_invites_from_state(true, true),
    'Invite creation is allowed with public room read and invite create right'
);
glpichat_unit_pass('Invite creation is allowed with public room read and invite create right');

glpichat_unit_assert_true(
    !glpichat_can_create_invites_from_state(false, true),
    'Invite creation is denied without public room read'
);
glpichat_unit_pass('Invite creation is denied without public room read');

glpichat_unit_assert_true(
    glpichat_can_update_invites_from_state(true, true),
    'Invite update is allowed with public room read and invite update right'
);
glpichat_unit_pass('Invite update is allowed with public room read and invite update right');

glpichat_unit_assert_true(
    !glpichat_can_update_invites_from_state(true, false),
    'Invite update is denied without invite update right'
);
glpichat_unit_pass('Invite update is denied without invite update right');

glpichat_unit_assert_true(
    glpichat_can_view_audit_from_state(true, true),
    'Audit is visible when ticket read and audit read are allowed'
);
glpichat_unit_pass('Audit is visible when ticket read and audit read are allowed');

glpichat_unit_assert_true(
    !glpichat_can_view_audit_from_state(true, false),
    'Audit is hidden without audit read right'
);
glpichat_unit_pass('Audit is hidden without audit read right');

fwrite(STDOUT, "[PASS] Access unit tests completed\n");
