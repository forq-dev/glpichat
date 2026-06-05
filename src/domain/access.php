<?php

declare(strict_types=1);

require_once __DIR__ . '/domain.php';

function glpichat_read_right_name(string $room_type): string
{
    if ($room_type === GLPICHAT_ROOM_INTERNAL) {
        return 'plugin_glpichat_internal';
    }

    return 'plugin_glpichat_public';
}

function glpichat_can_bootstrap_widget_from_state(bool $can_read_public_room, bool $can_read_internal_room): bool
{
    return $can_read_public_room || $can_read_internal_room;
}

function glpichat_can_read_ticket_from_state(bool $ticket_can_read, bool $can_read_public_room, bool $can_read_internal_room): bool
{
    return $ticket_can_read && glpichat_can_bootstrap_widget_from_state($can_read_public_room, $can_read_internal_room);
}

function glpichat_can_read_room_from_state(
    bool $ticket_can_read,
    string $room_type,
    bool $can_read_public_room,
    bool $can_read_internal_room
): bool {
    if (!$ticket_can_read || !glpichat_is_valid_room_type($room_type)) {
        return false;
    }

    if ($room_type === GLPICHAT_ROOM_INTERNAL) {
        return $can_read_internal_room;
    }

    return $can_read_public_room;
}

function glpichat_can_send_from_state(
    bool $can_read_public_room,
    bool $can_read_internal_room,
    bool $is_terminal_ticket,
    string $room_type,
    bool $can_send_public,
    bool $can_send_internal
): bool {
    if ($is_terminal_ticket || !glpichat_is_valid_room_type($room_type)) {
        return false;
    }

    if ($room_type === GLPICHAT_ROOM_INTERNAL) {
        return $can_read_internal_room && $can_send_internal;
    }

    return $can_read_public_room && $can_send_public;
}

function glpichat_can_view_participants_from_state(bool $can_read_ticket, bool $has_invite_read_right): bool
{
    return $can_read_ticket && $has_invite_read_right;
}

function glpichat_can_create_invites_from_state(bool $can_read_public_room, bool $has_invite_create_right): bool
{
    return $can_read_public_room && $has_invite_create_right;
}

function glpichat_can_update_invites_from_state(bool $can_read_public_room, bool $has_invite_update_right): bool
{
    return $can_read_public_room && $has_invite_update_right;
}

function glpichat_can_view_audit_from_state(bool $can_read_ticket, bool $has_audit_read_right): bool
{
    return $can_read_ticket && $has_audit_read_right;
}
