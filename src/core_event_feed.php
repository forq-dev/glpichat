<?php

declare(strict_types=1);

use Glpi\Search\SearchOption;

/**
 * @return array<string, int>
 */
function glpichat_ticket_log_search_option_ids(): array
{
    static $field_ids = null;
    if (is_array($field_ids)) {
        return $field_ids;
    }

    $field_ids = [];
    $search_options = SearchOption::getOptionsForItemtype(Ticket::class);
    $target_fields = [
        'status',
        'priority',
        'urgency',
        'impact',
        'type',
        'itilcategories_id',
    ];

    foreach ($search_options as $option_id => $option) {
        if (!is_array($option)) {
            continue;
        }

        $table = (string) ($option['table'] ?? '');
        $field = (string) ($option['field'] ?? '');
        if ($table !== Ticket::getTable() || !in_array($field, $target_fields, true)) {
            continue;
        }

        $field_ids[$field] = (int) $option_id;
    }

    return $field_ids;
}

function glpichat_chat_requesttype_id(): int
{
    global $DB;

    static $requesttypes_id = null;
    if (is_int($requesttypes_id)) {
        return $requesttypes_id;
    }

    $row = $DB->request([
        'SELECT' => ['id'],
        'FROM' => RequestType::getTable(),
        'WHERE' => ['name' => 'GLPI Chat'],
        'LIMIT' => 1,
    ])->current();
    $requesttypes_id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;

    return $requesttypes_id;
}

function glpichat_event_cursor(string $date_value, string $source_key, int $row_id): string
{
    $normalized_date = trim($date_value) !== '' ? $date_value : '0000-00-00 00:00:00';

    return sprintf('%s|%s|%012d', $normalized_date, $source_key, $row_id);
}

/**
 * @return array{date: string, source: string, id: int}
 */
function glpichat_event_cursor_parts(string $cursor): array
{
    if ($cursor === '' || $cursor === '0') {
        return [
            'date' => '',
            'source' => '',
            'id' => 0,
        ];
    }

    $parts = explode('|', $cursor, 3);

    return [
        'date' => (string) ($parts[0] ?? ''),
        'source' => (string) ($parts[1] ?? ''),
        'id' => (int) ($parts[2] ?? 0),
    ];
}

function glpichat_event_is_after_cursor(string $event_cursor, string $after_cursor): bool
{
    if ($after_cursor === '') {
        return true;
    }

    return strcmp($event_cursor, $after_cursor) > 0;
}

/**
 * @return array<int, string>
 */
function glpichat_plugin_timeline_event_types(): array
{
    return [
        'guest_joined',
        'guest_left',
        'guest_left_timeout',
        'user_joined',
        'user_left',
    ];
}

function glpichat_followup_source_label(int $requesttypes_id): string
{
    if ($requesttypes_id <= 0) {
        return 'GLPI';
    }

    $label = (string) Dropdown::getDropdownName('glpi_requesttypes', $requesttypes_id);

    return $label !== '' ? $label : 'GLPI';
}

function glpichat_followup_author_name(int $users_id): string
{
    if ($users_id <= 0) {
        return 'Sistema';
    }

    $name = getUserName($users_id);

    return is_string($name) && $name !== '' ? $name : ('Usuario #' . $users_id);
}

function glpichat_ticket_category_name(int $itilcategories_id): string
{
    if ($itilcategories_id <= 0) {
        return 'Sem categoria';
    }

    $label = (string) Dropdown::getDropdownName('glpi_itilcategories', $itilcategories_id);

    return $label !== '' ? $label : ('Categoria #' . $itilcategories_id);
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function glpichat_followup_event_row(array $row): array
{
    $followups_id = (int) ($row['id'] ?? 0);
    $requesttypes_id = (int) ($row['requesttypes_id'] ?? 0);
    $users_id = (int) ($row['users_id'] ?? 0);
    $date_creation = (string) ($row['date_creation'] ?? '');

    return [
        'id' => glpichat_event_cursor($date_creation, 'F', $followups_id),
        'event_type' => 'ticket_followup_added',
        'actor_type' => 'system',
        'users_id' => $users_id,
        'participants_id' => 0,
        'payload' => [
            'itilfollowups_id' => $followups_id,
            'source_label' => glpichat_followup_source_label($requesttypes_id),
            'author_name' => glpichat_followup_author_name($users_id),
            'is_private' => (int) ($row['is_private'] ?? 0) === 1,
        ],
        'date_creation' => $date_creation,
    ];
}

/**
 * @param array<string, mixed> $row
 * @param array<int, string> $field_by_option_id
 * @return array<string, mixed>|null
 */
function glpichat_log_event_row(array $row, array $field_by_option_id): ?array
{
    $option_id = (int) ($row['id_search_option'] ?? 0);
    $field_name = $field_by_option_id[$option_id] ?? '';
    if ($field_name === '') {
        return null;
    }

    $old_value = (int) ($row['old_value'] ?? 0);
    $new_value = (int) ($row['new_value'] ?? 0);
    if ($old_value === $new_value) {
        return null;
    }

    $payload = [];
    $event_type = '';
    if ($field_name === 'status') {
        $event_type = 'ticket_status_changed';
        $payload = [
            'old_status' => $old_value,
            'new_status' => $new_value,
            'old_label' => Ticket::getStatus($old_value),
            'new_label' => Ticket::getStatus($new_value),
            'is_terminal' => glpichat_is_ticket_terminal_status($new_value),
        ];
    } elseif ($field_name === 'priority') {
        $event_type = 'ticket_priority_changed';
        $payload = [
            'old_priority' => $old_value,
            'new_priority' => $new_value,
            'old_label' => Ticket::getPriorityName($old_value),
            'new_label' => Ticket::getPriorityName($new_value),
        ];
    } elseif ($field_name === 'urgency') {
        $event_type = 'ticket_urgency_changed';
        $payload = [
            'old_urgency' => $old_value,
            'new_urgency' => $new_value,
            'old_label' => Ticket::getUrgencyName($old_value),
            'new_label' => Ticket::getUrgencyName($new_value),
        ];
    } elseif ($field_name === 'impact') {
        $event_type = 'ticket_impact_changed';
        $payload = [
            'old_impact' => $old_value,
            'new_impact' => $new_value,
            'old_label' => Ticket::getImpactName($old_value),
            'new_label' => Ticket::getImpactName($new_value),
        ];
    } elseif ($field_name === 'type') {
        $event_type = 'ticket_type_changed';
        $payload = [
            'old_type' => $old_value,
            'new_type' => $new_value,
            'old_label' => (string) Ticket::getTicketTypeName($old_value),
            'new_label' => (string) Ticket::getTicketTypeName($new_value),
        ];
    } elseif ($field_name === 'itilcategories_id') {
        $event_type = 'ticket_category_changed';
        $payload = [
            'old_itilcategories_id' => $old_value,
            'new_itilcategories_id' => $new_value,
            'old_label' => glpichat_ticket_category_name($old_value),
            'new_label' => glpichat_ticket_category_name($new_value),
        ];
    }

    if ($event_type === '') {
        return null;
    }

    $row_id = (int) ($row['id'] ?? 0);
    $date_mod = (string) ($row['date_mod'] ?? '');

    return [
        'id' => glpichat_event_cursor($date_mod, 'L', $row_id),
        'event_type' => $event_type,
        'actor_type' => 'system',
        'users_id' => 0,
        'participants_id' => 0,
        'payload' => $payload,
        'date_creation' => $date_mod,
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function glpichat_plugin_event_row(array $row): array
{
    $event_id = (int) ($row['id'] ?? 0);
    $date_creation = (string) ($row['date_creation'] ?? '');

    return [
        'id' => glpichat_event_cursor($date_creation, 'E', $event_id),
        'event_type' => (string) ($row['event_type'] ?? ''),
        'actor_type' => (string) ($row['actor_type'] ?? ''),
        'users_id' => (int) ($row['users_id'] ?? 0),
        'participants_id' => (int) ($row['participants_id'] ?? 0),
        'payload' => json_decode((string) ($row['payload_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR),
        'date_creation' => $date_creation,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function glpichat_plugin_timeline_events(int $rooms_id, string $after_cursor): array
{
    global $DB;

    if ($after_cursor === '0') {
        $after_cursor = '';
    }

    $after_parts = glpichat_event_cursor_parts($after_cursor);
    $after_date = $after_parts['date'];
    $fetch_latest_only = $after_cursor === '';

    $where = [
        'rooms_id' => $rooms_id,
        'event_type' => glpichat_plugin_timeline_event_types(),
    ];
    if ($after_date !== '' && !$fetch_latest_only) {
        $where[] = ['date_creation' => ['>=', $after_date]];
    }

    $rows = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_events',
        'WHERE' => $where,
        'ORDER' => $fetch_latest_only ? ['date_creation DESC', 'id DESC'] : ['date_creation ASC', 'id ASC'],
        'LIMIT' => $fetch_latest_only ? 50 : 100,
    ]);

    $events = [];
    foreach ($rows as $row) {
        $event = glpichat_plugin_event_row($row);
        if (!glpichat_event_is_after_cursor((string) $event['id'], $after_cursor)) {
            continue;
        }
        $events[] = $event;
    }

    usort($events, static fn(array $a, array $b): int => strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')));

    return $fetch_latest_only ? array_slice($events, -50) : array_slice($events, 0, 50);
}

/**
 * @param array<int, array<int, array<string, mixed>>> $event_streams
 * @return array<int, array<string, mixed>>
 */
function glpichat_merge_event_streams(array $event_streams, bool $fetch_latest_only): array
{
    $events = [];
    foreach ($event_streams as $stream) {
        foreach ($stream as $event) {
            $events[] = $event;
        }
    }

    usort(
        $events,
        static fn(array $a, array $b): int => strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''))
    );

    return $fetch_latest_only ? array_slice($events, -50) : array_slice($events, 0, 50);
}

/**
 * @return array<int, array<string, mixed>>
 */
function glpichat_core_events(int $rooms_id, string $after_cursor): array
{
    global $DB;

    if ($after_cursor === '0') {
        $after_cursor = '';
    }

    $room = glpichat_room_by_id($rooms_id);
    $tickets_id = (int) ($room['tickets_id'] ?? 0);
    $room_type = (string) ($room['room_type'] ?? '');
    if ($tickets_id <= 0 || !in_array($room_type, ['public', 'internal'], true)) {
        return [];
    }

    $after_parts = glpichat_event_cursor_parts($after_cursor);
    $after_date = $after_parts['date'];
    $fetch_latest_only = $after_cursor === '';
    $events = [];

    $followup_where = [
        'itemtype' => Ticket::class,
        'items_id' => $tickets_id,
    ];
    if ($room_type === 'public') {
        $followup_where['is_private'] = 0;
    }

    $chat_requesttypes_id = glpichat_chat_requesttype_id();
    if ($chat_requesttypes_id > 0) {
        $followup_where[] = ['requesttypes_id' => ['<>', $chat_requesttypes_id]];
    }
    if ($after_date !== '' && !$fetch_latest_only) {
        $followup_where[] = ['date_creation' => ['>=', $after_date]];
    }

    $followups = $DB->request([
        'FROM' => 'glpi_itilfollowups',
        'WHERE' => $followup_where,
        'ORDER' => $fetch_latest_only ? ['date_creation DESC', 'id DESC'] : ['date_creation ASC', 'id ASC'],
        'LIMIT' => $fetch_latest_only ? 50 : 100,
    ]);

    foreach ($followups as $row) {
        $event = glpichat_followup_event_row($row);
        if (!glpichat_event_is_after_cursor((string) $event['id'], $after_cursor)) {
            continue;
        }
        $events[] = $event;
    }

    $field_ids = glpichat_ticket_log_search_option_ids();
    if (count($field_ids) === 0) {
        usort($events, static fn(array $a, array $b): int => strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')));
        return $fetch_latest_only ? array_slice($events, -50) : array_slice($events, 0, 50);
    }

    $log_where = [
        'itemtype' => Ticket::class,
        'items_id' => $tickets_id,
        'id_search_option' => array_values($field_ids),
    ];
    if ($after_date !== '' && !$fetch_latest_only) {
        $log_where[] = ['date_mod' => ['>=', $after_date]];
    }

    $field_by_option_id = [];
    foreach ($field_ids as $field_name => $option_id) {
        $field_by_option_id[$option_id] = $field_name;
    }

    $logs = $DB->request([
        'FROM' => 'glpi_logs',
        'WHERE' => $log_where,
        'ORDER' => $fetch_latest_only ? ['date_mod DESC', 'id DESC'] : ['date_mod ASC', 'id ASC'],
        'LIMIT' => $fetch_latest_only ? 50 : 100,
    ]);

    foreach ($logs as $row) {
        $event = glpichat_log_event_row($row, $field_by_option_id);
        if (!is_array($event)) {
            continue;
        }
        if (!glpichat_event_is_after_cursor((string) $event['id'], $after_cursor)) {
            continue;
        }
        $events[] = $event;
    }

    usort(
        $events,
        static fn(array $a, array $b): int => strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''))
    );

    return $fetch_latest_only ? array_slice($events, -50) : array_slice($events, 0, 50);
}

function glpichat_room_last_event_id(int $rooms_id): string
{
    $events = glpichat_events($rooms_id, '');
    if (count($events) === 0) {
        return '';
    }

    $last_event = $events[count($events) - 1];

    return (string) ($last_event['id'] ?? '');
}

/**
 * @return array<int, array<string, mixed>>
 */
function glpichat_events(int $rooms_id, string $last_event_id): array
{
    $normalized_cursor = $last_event_id === '0' ? '' : $last_event_id;
    $fetch_latest_only = $normalized_cursor === '';

    return glpichat_merge_event_streams([
        glpichat_core_events($rooms_id, $normalized_cursor),
        glpichat_plugin_timeline_events($rooms_id, $normalized_cursor),
    ], $fetch_latest_only);
}
