<?php

declare(strict_types=1);

function glpichat_insert_invite(
    int $rooms_id,
    int $tickets_id,
    string $token_hash,
    int $created_by,
    string $guest_name,
    string $guest_email,
    string $expires_at
): int {
    global $DB;

    $DB->insert('glpi_plugin_glpichat_invites', [
        'rooms_id' => $rooms_id,
        'tickets_id' => $tickets_id,
        'token_hash' => $token_hash,
        'created_by' => $created_by,
        'guest_name' => $guest_name,
        'guest_email' => $guest_email,
        'expires_at' => $expires_at,
        'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
    ]);

    return (int) $DB->insertId();
}

function glpichat_find_invite_by_id(int $invite_id): array
{
    global $DB;

    $invite = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_invites',
        'WHERE' => ['id' => $invite_id],
        'LIMIT' => 1,
    ])->current();

    return is_array($invite) ? $invite : [];
}

function glpichat_fetch_invites_for_ticket(int $tickets_id): array
{
    global $DB;

    $rows = [];
    $iterator = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_invites',
        'WHERE' => ['tickets_id' => $tickets_id],
        'ORDER' => ['id DESC'],
        'LIMIT' => 100,
    ]);

    foreach ($iterator as $row) {
        $rows[] = $row;
    }

    $participant_ids = [];
    foreach ($rows as $row) {
        $participants_id = (int) ($row['participants_id'] ?? 0);
        if ($participants_id > 0) {
            $participant_ids[$participants_id] = $participants_id;
        }
    }

    if ($participant_ids === []) {
        return $rows;
    }

    $participant_rows = [];
    $participant_iterator = $DB->request([
        'SELECT' => [
            'id',
            'joined_at',
            'left_at',
            'last_activity',
        ],
        'FROM' => 'glpi_plugin_glpichat_participants',
        'WHERE' => [
            'id' => array_values($participant_ids),
        ],
    ]);

    foreach ($participant_iterator as $participant_row) {
        $participant_rows[(int) ($participant_row['id'] ?? 0)] = $participant_row;
    }

    $enriched_rows = [];
    foreach ($rows as $row) {
        $participants_id = (int) ($row['participants_id'] ?? 0);
        $participant_row = $participant_rows[$participants_id] ?? null;
        $row['participant_joined_at'] = is_array($participant_row) ? (string) ($participant_row['joined_at'] ?? '') : '';
        $row['participant_left_at'] = is_array($participant_row) ? (string) ($participant_row['left_at'] ?? '') : '';
        $row['participant_last_activity'] = is_array($participant_row) ? (string) ($participant_row['last_activity'] ?? '') : '';
        $enriched_rows[] = $row;
    }

    return $enriched_rows;
}

function glpichat_user_display_name(int $users_id): string
{
    return getUserName($users_id) ?: ('User #' . $users_id);
}

function glpichat_mark_invite_revoked(int $invite_id, string $revoked_at): void
{
    global $DB;

    $DB->update('glpi_plugin_glpichat_invites', [
        'revoked_at' => $revoked_at,
    ], [
        'id' => $invite_id,
    ]);
}

function glpichat_update_invite_reissue(int $invite_id, string $token_hash, string $expires_at): void
{
    global $DB;

    $DB->update('glpi_plugin_glpichat_invites', [
        'token_hash' => $token_hash,
        'revoked_at' => null,
        'expires_at' => $expires_at,
    ], [
        'id' => $invite_id,
    ]);
}
