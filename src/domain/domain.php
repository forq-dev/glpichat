<?php

declare(strict_types=1);

const GLPICHAT_ROOM_PUBLIC = 'public';
const GLPICHAT_ROOM_INTERNAL = 'internal';
const GLPICHAT_ACTOR_USER = 'user';
const GLPICHAT_ACTOR_GUEST = 'guest';
const GLPICHAT_INVITE_TTL_SECONDS = 3600;
const GLPICHAT_GUEST_COOKIE = 'glpichat_guest_session';

function glpichat_room_types(): array
{
    return [
        GLPICHAT_ROOM_PUBLIC,
        GLPICHAT_ROOM_INTERNAL,
    ];
}

function glpichat_actor_types(): array
{
    return [
        GLPICHAT_ACTOR_USER,
        GLPICHAT_ACTOR_GUEST,
    ];
}

function glpichat_table_names(): array
{
    return [
        'glpi_plugin_glpichat_rooms',
        'glpi_plugin_glpichat_participants',
        'glpi_plugin_glpichat_messages',
        'glpi_plugin_glpichat_events',
        'glpi_plugin_glpichat_invites',
        'glpi_plugin_glpichat_readstates',
        'glpi_plugin_glpichat_auditlogs',
    ];
}

function glpichat_right_names(): array
{
    return [
        'plugin_glpichat_public',
        'plugin_glpichat_internal',
        'plugin_glpichat_invite',
        'plugin_glpichat_view_audit',
        'plugin_glpichat_admin',
    ];
}

function glpichat_legacy_right_names(): array
{
    return [
        'plugin_glpichat_read',
        'plugin_glpichat_read_public',
        'plugin_glpichat_read_internal',
        'plugin_glpichat_send_public',
        'plugin_glpichat_send_internal',
    ];
}

function glpichat_is_valid_room_type(string $room_type): bool
{
    return in_array($room_type, glpichat_room_types(), true);
}

function glpichat_is_private_room_type(string $room_type): bool
{
    return $room_type === GLPICHAT_ROOM_INTERNAL;
}

function glpichat_is_valid_actor_type(string $actor_type): bool
{
    return in_array($actor_type, glpichat_actor_types(), true);
}

function glpichat_message_notification_event_name(string $room_type, string $actor_type): string
{
    if ($actor_type === GLPICHAT_ACTOR_GUEST) {
        return 'glpichat_guest_message';
    }

    if ($room_type === GLPICHAT_ROOM_INTERNAL) {
        return 'glpichat_internal_message';
    }

    return 'glpichat_public_message';
}

function glpichat_ticket_terminal_statuses(): array
{
    return [
        CommonITILObject::SOLVED,
        CommonITILObject::CLOSED,
    ];
}

function glpichat_is_ticket_terminal_status(int $status): bool
{
    return in_array($status, glpichat_ticket_terminal_statuses(), true);
}

function glpichat_default_notification_template_name(): string
{
    return 'GLPI CHAT - Layout padrao';
}

function glpichat_default_notification_definition_name(string $event): string
{
    return match ($event) {
        'glpichat_public_message' => 'GLPI CHAT - Notificacao solicitante',
        'glpichat_internal_message' => 'GLPI CHAT - Notificacao interna',
        'glpichat_guest_message' => 'GLPI CHAT - Notificacao convidado',
        'glpichat_guest_joined' => 'GLPI CHAT - Notificacao convidado entrou',
        'glpichat_guest_left' => 'GLPI CHAT - Notificacao convidado saiu',
        'glpichat_guest_left_timeout' => 'GLPI CHAT - Notificacao sessao convidado expirada',
        default => 'GLPI CHAT - Notificacao chat',
    };
}

function glpichat_default_notification_events(): array
{
    return [
        'glpichat_public_message',
        'glpichat_internal_message',
        'glpichat_guest_message',
        'glpichat_guest_joined',
        'glpichat_guest_left',
        'glpichat_guest_left_timeout',
    ];
}

function glpichat_default_template_subject(): string
{
    return '##ticket.id## - ##ticket.title##';
}

function glpichat_default_template_text(): string
{
    return '##glpichat.event_action## no chamado ##ticket.id## por ##glpichat.sender_name##';
}

function glpichat_default_template_html(): string
{
    return '<p>##glpichat.event_action## no chamado ##ticket.id## por ##glpichat.sender_name##</p>';
}

function glpichat_display_unread_count(int $raw_unread): int
{
    if ($raw_unread <= 1) {
        return 0;
    }

    return $raw_unread;
}
