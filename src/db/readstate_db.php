<?php

declare(strict_types=1);

use Glpi\DBAL\QueryFunction;

function glpichat_readstate_last_message_id(int $rooms_id, int $participants_id): int
{
    global $DB;

    $readstate = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_readstates',
        'WHERE' => [
            'rooms_id' => $rooms_id,
            'participants_id' => $participants_id,
        ],
        'LIMIT' => 1,
    ])->current();

    return is_array($readstate) ? (int) $readstate['last_read_messages_id'] : 0;
}

function glpichat_participant_for_readstate(int $rooms_id, int $participants_id): array
{
    global $DB;

    $participant = $DB->request([
        'SELECT' => ['id', 'rooms_id', 'participant_type', 'users_id'],
        'FROM' => 'glpi_plugin_glpichat_participants',
        'WHERE' => [
            'id' => $participants_id,
            'rooms_id' => $rooms_id,
            'left_at' => null,
        ],
        'LIMIT' => 1,
    ])->current();

    return glpichat_assert_valid_readstate_participant(is_array($participant) ? $participant : null);
}

function glpichat_unread_count_for_participant(int $rooms_id, int $participants_id): int
{
    global $DB;

    if ($participants_id <= 0) {
        return 0;
    }

    $participant = glpichat_participant_for_readstate($rooms_id, $participants_id);
    $last_read_messages_id = glpichat_readstate_last_message_id($rooms_id, $participants_id);

    $row = $DB->request([
        'COUNT' => 'cpt',
        'FROM' => 'glpi_plugin_glpichat_messages',
        'WHERE' => glpichat_unread_where_for_participant($participant, $last_read_messages_id),
    ])->current();

    return (int) ($row['cpt'] ?? 0);
}

function glpichat_assert_message_belongs_to_room(int $rooms_id, int $messages_id): void
{
    global $DB;

    if ($messages_id === 0) {
        return;
    }

    $message = $DB->request([
        'SELECT' => ['id'],
        'FROM' => 'glpi_plugin_glpichat_messages',
        'WHERE' => [
            'id' => $messages_id,
            'rooms_id' => $rooms_id,
        ],
        'LIMIT' => 1,
    ])->current();

    if (!is_array($message)) {
        throw new RuntimeException('Read marker message does not belong to this chat room.');
    }
}

function glpichat_mark_read(int $rooms_id, int $participants_id, int $last_read_messages_id): int
{
    global $DB;

    if ($participants_id <= 0) {
        throw new RuntimeException('Invalid chat participant.');
    }

    glpichat_participant_for_readstate($rooms_id, $participants_id);
    glpichat_assert_message_belongs_to_room($rooms_id, $last_read_messages_id);

    $existing = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_readstates',
        'WHERE' => [
            'rooms_id' => $rooms_id,
            'participants_id' => $participants_id,
        ],
        'LIMIT' => 1,
    ])->current();

    if (is_array($existing)) {
        $current = (int) $existing['last_read_messages_id'];
        $payload = glpichat_readstate_update_payload(
            $current,
            $last_read_messages_id,
            $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')
        );
        $updated = $DB->update('glpi_plugin_glpichat_readstates', [
            'last_read_messages_id' => $payload['last_read_messages_id'],
            'date_mod' => $payload['date_mod'],
        ], [
            'id' => (int) $existing['id'],
        ]);
        if (!$updated) {
            throw new RuntimeException('Unable to update chat read state.');
        }

        return (int) $payload['last_read_messages_id'];
    }

    $payload = glpichat_readstate_insert_payload(
        $rooms_id,
        $participants_id,
        $last_read_messages_id,
        $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')
    );
    $inserted = $DB->insert('glpi_plugin_glpichat_readstates', $payload);
    if (!$inserted) {
        throw new RuntimeException('Unable to create chat read state.');
    }

    return (int) $payload['last_read_messages_id'];
}

function glpichat_unread_count(int $rooms_id, int $participants_id): int
{
    return glpichat_unread_count_for_participant($rooms_id, $participants_id);
}

function glpichat_list_user_channels(int $users_id, int $focus_tickets_id): array
{
    global $DB;

    $result = [];
    $seen = [];
    $ticket_status_by_id = [];
    $linked_tickets = [];

    if ($focus_tickets_id > 0 && glpichat_can_read_ticket($focus_tickets_id)) {
        $linked_tickets[$focus_tickets_id] = true;
    }

    $user_links = $DB->request([
        'SELECT' => ['tickets_id'],
        'FROM' => 'glpi_tickets_users',
        'WHERE' => [
            'users_id' => $users_id,
            'type' => [CommonITILActor::REQUESTER, CommonITILActor::ASSIGN, CommonITILActor::OBSERVER],
        ],
    ]);
    foreach ($user_links as $link) {
        $linked_tickets[(int) $link['tickets_id']] = true;
    }

    $groups_ids = array_map(static fn ($id): int => (int) $id, $_SESSION['glpigroups'] ?? []);
    if (count($groups_ids) > 0) {
        $group_links = $DB->request([
            'SELECT' => ['tickets_id'],
            'FROM' => 'glpi_groups_tickets',
            'WHERE' => [
                'groups_id' => $groups_ids,
                'type' => [CommonITILActor::REQUESTER, CommonITILActor::ASSIGN, CommonITILActor::OBSERVER],
            ],
        ]);
        foreach ($group_links as $link) {
            $linked_tickets[(int) $link['tickets_id']] = true;
        }
    }

    if (count($linked_tickets) === 0) {
        return [];
    }

    $ticket_ids = array_keys($linked_tickets);

    $ticket_rows = $DB->request([
        'SELECT' => ['id', 'status'],
        'FROM' => 'glpi_tickets',
        'WHERE' => ['id' => $ticket_ids],
    ]);
    foreach ($ticket_rows as $row) {
        $ticket_status_by_id[(int) $row['id']] = (int) $row['status'];
    }

    // Batch-fetch all rooms for linked tickets (replaces N queries in the loop).
    $rooms_by_key = [];
    $all_rooms_ids = [];
    $room_rows = $DB->request([
        'SELECT' => ['id', 'tickets_id', 'room_type'],
        'FROM' => 'glpi_plugin_glpichat_rooms',
        'WHERE' => ['tickets_id' => $ticket_ids],
    ]);
    foreach ($room_rows as $row) {
        $key = glpichat_channel_key((int) $row['tickets_id'], (string) $row['room_type']);
        $rooms_id = (int) $row['id'];
        $rooms_by_key[$key] = $rooms_id;
        $all_rooms_ids[] = $rooms_id;
    }

    // Batch-fetch last message ID per room (one GROUP BY query instead of N queries).
    $last_message_by_room = [];
    if (count($all_rooms_ids) > 0) {
        $msg_agg = $DB->request([
            'SELECT' => [
                'rooms_id',
                QueryFunction::max('id', 'last_id'),
            ],
            'FROM' => 'glpi_plugin_glpichat_messages',
            'WHERE' => ['rooms_id' => $all_rooms_ids],
            'GROUPBY' => 'rooms_id',
        ]);
        foreach ($msg_agg as $row) {
            $last_message_by_room[(int) $row['rooms_id']] = (int) $row['last_id'];
        }
    }

    // Batch-fetch active participants for this user across all rooms.
    $participant_by_room = [];
    if (count($all_rooms_ids) > 0) {
        $p_rows = $DB->request([
            'SELECT' => ['id', 'rooms_id', 'participant_type', 'users_id'],
            'FROM' => 'glpi_plugin_glpichat_participants',
            'WHERE' => [
                'rooms_id' => $all_rooms_ids,
                'users_id' => $users_id,
                'participant_type' => GLPICHAT_ACTOR_USER,
                'left_at' => null,
            ],
        ]);
        foreach ($p_rows as $row) {
            $participant_by_room[(int) $row['rooms_id']] = $row;
        }
    }

    // Batch-fetch readstates for found participants.
    $readstate_by_participant = [];
    $participant_ids_list = array_map(static fn($p) => (int) $p['id'], $participant_by_room);
    if (count($participant_ids_list) > 0) {
        $rs_rows = $DB->request([
            'SELECT' => ['participants_id', 'last_read_messages_id'],
            'FROM' => 'glpi_plugin_glpichat_readstates',
            'WHERE' => ['participants_id' => $participant_ids_list],
        ]);
        foreach ($rs_rows as $row) {
            $readstate_by_participant[(int) $row['participants_id']] = (int) $row['last_read_messages_id'];
        }
    }

    krsort($linked_tickets);
    foreach (array_keys($linked_tickets) as $tickets_id) {
        if (!glpichat_can_read_ticket((int) $tickets_id)) {
            continue;
        }

        foreach ([GLPICHAT_ROOM_PUBLIC, GLPICHAT_ROOM_INTERNAL] as $room_type) {
            if (!glpichat_can_read_room((int) $tickets_id, $room_type)) {
                continue;
            }

            $key = glpichat_channel_key((int) $tickets_id, $room_type);
            if (isset($seen[$key])) {
                continue;
            }

            // Use pre-fetched room_id (falls back to upsert only for newly allowed rooms).
            $rooms_id = $rooms_by_key[$key] ?? glpichat_room_id((int) $tickets_id, $room_type);
            $participant = $participant_by_room[$rooms_id] ?? null;
            $participants_id = $participant !== null ? (int) $participant['id'] : 0;

            // Compute unread using pre-fetched participant + readstate (1 COUNT query max per room).
            $unread_count = 0;
            if ($participants_id > 0 && $participant !== null) {
                $last_read = $readstate_by_participant[$participants_id] ?? 0;
                $where = glpichat_unread_where_for_participant($participant, $last_read);
                $count_row = $DB->request([
                    'COUNT' => 'cpt',
                    'FROM' => 'glpi_plugin_glpichat_messages',
                    'WHERE' => $where,
                ])->current();
                $unread_count = (int) ($count_row['cpt'] ?? 0);
            }

            $last_message_id = $last_message_by_room[$rooms_id] ?? 0;

            $seen[$key] = true;
            $result[] = glpichat_channel_output_row(
                $rooms_id,
                (int) $tickets_id,
                $room_type,
                $participants_id,
                $unread_count,
                $last_message_id,
                $ticket_status_by_id[(int) $tickets_id] ?? 0
            );
        }

        if (count($result) >= 80) {
            break;
        }
    }

    return $result;
}
