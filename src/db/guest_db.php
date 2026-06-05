<?php

declare(strict_types=1);

use Glpi\DBAL\QueryExpression;

require_once dirname(__DIR__) . '/flows/guest_flow.php';

function glpichat_accept_invite_lookup(string $token_hash): array
{
    global $DB;

    $invite = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_invites',
        'WHERE' => [
            'token_hash' => $token_hash,
            'accepted_at' => null,
            'revoked_at' => null,
            ['expires_at' => ['>', new QueryExpression('NOW()')]],
        ],
        'LIMIT' => 1,
    ])->current();

    return is_array($invite) ? $invite : [];
}

function glpichat_create_guest_participant(int $rooms_id, string $guest_name, string $guest_email, string $session_hash): int
{
    global $DB;

    $now = date('Y-m-d H:i:s');
    $DB->insert('glpi_plugin_glpichat_participants', [
        'rooms_id' => $rooms_id,
        'users_id' => 0,
        'guest_name' => $guest_name,
        'guest_email' => $guest_email,
        'participant_type' => GLPICHAT_ACTOR_GUEST,
        'session_token_hash' => $session_hash,
        'joined_at' => $now,
        'last_activity' => $now,
    ]);

    return (int) $DB->insertId();
}

function glpichat_guest_session_ttl_config_seconds(): int
{
    global $DB;

    $ttl = 3600;
    if (isset($DB) && $DB !== null) {
        $ttl = (int) Config::getConfigurationValue('plugin:glpichat', 'guest_session_ttl');
    }

    if ($ttl <= 0) {
        $ttl = 3600;
    }

    return $ttl;
}

function glpichat_mark_invite_accepted(int $invite_id, int $participants_id): void
{
    global $DB;

    $DB->update('glpi_plugin_glpichat_invites', [
        'accepted_at' => date('Y-m-d H:i:s'),
        'participants_id' => $participants_id,
    ], [
        'id' => $invite_id,
    ]);
}

function glpichat_guest_participant(string $session_token): array
{
    global $DB;

    $participant = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_participants',
        'WHERE' => [
            'session_token_hash' => glpichat_guest_session_hash($session_token),
            'participant_type' => GLPICHAT_ACTOR_GUEST,
            'left_at' => null,
        ],
        'LIMIT' => 1,
    ])->current();

    if (!is_array($participant)) {
        throw new RuntimeException('Guest session is invalid.');
    }

    $ttl = glpichat_guest_session_ttl_config_seconds();

    $last_time = $participant['last_activity'] ?? $participant['joined_at'] ?? date('Y-m-d H:i:s');
    $elapsed = time() - strtotime($last_time);

    if ($elapsed > $ttl) {
        $now = date('Y-m-d H:i:s');
        $DB->update('glpi_plugin_glpichat_participants', [
            'left_at' => $now,
            'session_token_hash' => null,
        ], [
            'id' => $participant['id'],
        ]);

        $rooms_id = (int) $participant['rooms_id'];
        $room = $DB->request([
            'FROM' => 'glpi_plugin_glpichat_rooms',
            'WHERE' => ['id' => $rooms_id],
            'LIMIT' => 1,
        ])->current();

        if (is_array($room)) {
            $tickets_id = (int) $room['tickets_id'];
            glpichat_record_event(
                $rooms_id,
                $tickets_id,
                'guest_left_timeout',
                GLPICHAT_ACTOR_GUEST,
                0,
                (int) $participant['id'],
                [
                    'guest_name' => (string) $participant['guest_name'],
                ]
            );

            try {
                $followup_user_id = glpichat_external_followup_user_id();
                glpichat_add_followup(
                    $tickets_id,
                    $followup_user_id,
                    glpichat_guest_left_timeout_followup_content((string) $participant['guest_name']),
                    false
                );
                glpichat_raise_guest_left_timeout_notification_event($tickets_id, (string) $participant['guest_name']);
            } catch (\Exception $e) {
                // Silently ignore if configuration for followup user is missing
            }
        }

        throw new RuntimeException('Guest session has expired due to inactivity.');
    }

    return $participant;
}

function glpichat_disconnect_participant(int $participants_id, string $now): void
{
    global $DB;

    $participant = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_participants',
        'WHERE' => [
            'id' => $participants_id,
            'left_at' => null,
        ],
        'LIMIT' => 1,
    ])->current();

    if (!is_array($participant)) {
        return;
    }

    $DB->update('glpi_plugin_glpichat_participants', [
        'left_at' => $now,
        'session_token_hash' => null,
    ], [
        'id' => $participants_id,
    ]);

    $rooms_id = (int) $participant['rooms_id'];
    $room = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_rooms',
        'WHERE' => ['id' => $rooms_id],
        'LIMIT' => 1,
    ])->current();

    if (is_array($room)) {
        $tickets_id = (int) $room['tickets_id'];
        glpichat_record_event(
            $rooms_id,
            $tickets_id,
            'guest_left',
            GLPICHAT_ACTOR_GUEST,
            0,
            $participants_id,
            [
                'guest_name' => (string) $participant['guest_name'],
            ]
        );

        try {
            $followup_user_id = glpichat_external_followup_user_id();
            glpichat_add_followup(
                $tickets_id,
                $followup_user_id,
                glpichat_guest_left_followup_content((string) $participant['guest_name']),
                false
            );
            glpichat_raise_guest_left_notification_event($tickets_id, (string) $participant['guest_name']);
        } catch (\Exception $e) {
            // Silently ignore if configuration for followup user is missing
        }
    }
}

function glpichat_update_participant_activity(int $participants_id, bool $is_typing): void
{
    global $DB;

    $now = date('Y-m-d H:i:s');
    $is_typing_until = $is_typing ? date('Y-m-d H:i:s', time() + 4) : null;

    $update_data = [
        'last_activity'   => $now,
        'last_seen'       => $now,
        'is_typing_until' => $is_typing_until,
    ];

    $DB->update('glpi_plugin_glpichat_participants', $update_data, [
        'id' => $participants_id,
    ]);
}

function glpichat_get_typing_participants(int $rooms_id, int $exclude_participant_id): array
{
    global $DB;

    $now = date('Y-m-d H:i:s');
    $iterator = $DB->request([
        'SELECT' => ['users_id', 'guest_name', 'participant_type'],
        'FROM'   => 'glpi_plugin_glpichat_participants',
        'WHERE'  => [
            'rooms_id' => $rooms_id,
            'left_at'  => null,
            ['id' => ['!=', $exclude_participant_id]],
            ['is_typing_until' => ['>', $now]],
        ],
    ]);

    $names = [];
    foreach ($iterator as $row) {
        if ($row['participant_type'] === GLPICHAT_ACTOR_GUEST) {
            $names[] = $row['guest_name'] ?: 'Convidado';
        } else {
            $users_id = (int) $row['users_id'];
            $names[] = getUserName($users_id) ?: ('Usuário #' . $users_id);
        }
    }

    return $names;
}
