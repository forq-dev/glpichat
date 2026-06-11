<?php

declare(strict_types=1);

use Glpi\DBAL\QueryExpression;
use Symfony\Component\HttpFoundation\File\UploadedFile;

require_once __DIR__ . '/domain/domain.php';
require_once __DIR__ . '/domain/access.php';
require_once __DIR__ . '/views/message_view.php';
require_once __DIR__ . '/domain/readstate.php';
require_once __DIR__ . '/views/channel_view.php';

// Split DB and setup helper modules
require_once __DIR__ . '/db/setup_helpers.php';
require_once __DIR__ . '/db/invite_db.php';
require_once __DIR__ . '/db/guest_db.php';
require_once __DIR__ . '/db/readstate_db.php';
require_once __DIR__ . '/core_event_feed.php';

function glpichat_config_int(string $key, int $default): int
{
    $v = (int) \Config::getConfigurationValue('plugin:glpichat', $key);
    return $v > 0 ? $v : $default;
}

function glpichat_config_bool(string $key, bool $default): bool
{
    $v = \Config::getConfigurationValue('plugin:glpichat', $key);
    if ($v === null || $v === '') {
        return $default;
    }
    return (bool)(int)$v;
}

function glpichat_require_logged_csrf(array $input): void
{
    Session::checkLoginUser();
}

function glpichat_current_user_id(): int
{
    $users_id = Session::getLoginUserID();
    if (!is_int($users_id) || $users_id <= 0) {
        throw new RuntimeException('Logged user is required.');
    }

    return $users_id;
}

function glpichat_external_followup_user_id(): int
{
    $value = Config::getConfigurationValue('plugin:glpichat', 'external_followup_user_id');
    if (!is_scalar($value)) {
        throw new RuntimeException('Invalid plugin configuration for external followup user.');
    }

    $users_id = (int) $value;
    if ($users_id <= 0) {
        throw new RuntimeException('Plugin configuration external_followup_user_id must be a positive user id.');
    }

    $user = new User();
    if (!$user->getFromDB($users_id)) {
        throw new RuntimeException('Configured external followup user does not exist.');
    }

    return $users_id;
}

/**
 * Returns a reference to the room-id cache array shared between glpichat_room_id()
 * and glpichat_clear_static_caches(). The static lives here so that the reset path
 * can reach it without needing a separate variable in the main function.
 *
 * @internal
 * @return array<string, int>
 */
function &glpichat_room_id_cache(): array
{
    static $cache = [];
    return $cache;
}

/**
 * Returns a reference to the ticket cache array shared between glpichat_ticket_or_fail()
 * and glpichat_clear_static_caches().
 *
 * @internal
 * @return array<int, Ticket>
 */
function &glpichat_ticket_or_fail_cache(): array
{
    static $cache = [];
    return $cache;
}

function glpichat_room_id(int $tickets_id, string $room_type): int
{
    $cache     = &glpichat_room_id_cache();
    $cache_key = $tickets_id . ':' . $room_type;

    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    global $DB;

    $room = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_rooms',
        'WHERE' => [
            'tickets_id' => $tickets_id,
            'room_type' => $room_type,
        ],
        'LIMIT' => 1,
    ])->current();

    if (is_array($room)) {
        $cache[$cache_key] = (int) $room['id'];
        return $cache[$cache_key];
    }

    $DB->insert('glpi_plugin_glpichat_rooms', [
        'tickets_id' => $tickets_id,
        'room_type' => $room_type,
        'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
    ]);

    $cache[$cache_key] = (int) $DB->insertId();
    return $cache[$cache_key];
}

function glpichat_ticket_or_fail(int $tickets_id): Ticket
{
    $cache = &glpichat_ticket_or_fail_cache();

    if (isset($cache[$tickets_id])) {
        return $cache[$tickets_id];
    }

    $ticket = new Ticket();
    if (!$ticket->getFromDB($tickets_id)) {
        throw new RuntimeException('Chamado não encontrado.');
    }

    $cache[$tickets_id] = $ticket;

    return $ticket;
}

function glpichat_ticket_status(int $tickets_id): int
{
    $ticket = glpichat_ticket_or_fail($tickets_id);
    return (int) ($ticket->fields['status'] ?? 0);
}

/**
 * Reset all static caches used by glpichat_room_id() and glpichat_ticket_or_fail().
 * Intended for use in test teardown to prevent cache leakage between test cases.
 * Has no effect on production behaviour.
 */
function glpichat_clear_static_caches(): void
{
    $room_cache   = &glpichat_room_id_cache();
    $ticket_cache = &glpichat_ticket_or_fail_cache();
    $room_cache   = [];
    $ticket_cache = [];
}

function glpichat_is_ticket_terminal_for_chat(int $tickets_id): bool
{
    return glpichat_is_ticket_terminal_status(glpichat_ticket_status($tickets_id));
}

function glpichat_assert_ticket_open_for_chat(int $tickets_id): void
{
    $status = glpichat_ticket_status($tickets_id);
    if (!glpichat_is_ticket_terminal_status($status)) {
        return;
    }

    throw new RuntimeException(sprintf(
        'O chat está arquivado para chamados com status "%s".',
        Ticket::getStatus($status)
    ));
}

function glpichat_can_read_ticket(int $tickets_id): bool
{
    $ticket = glpichat_ticket_or_fail($tickets_id);

    return glpichat_can_read_ticket_from_state(
        $ticket->can($tickets_id, READ),
        (bool) Session::haveRight('plugin_glpichat_public', READ),
        (bool) Session::haveRight('plugin_glpichat_internal', READ)
    );
}

function glpichat_assert_can_read_ticket(int $tickets_id): void
{
    if (!glpichat_can_read_ticket($tickets_id)) {
        throw new RuntimeException('Você não tem permissão para acessar este chat.');
    }
}

function glpichat_can_send(int $tickets_id, string $room_type): bool
{
    $ticket = glpichat_ticket_or_fail($tickets_id);

    return glpichat_can_send_from_state(
        glpichat_can_read_room_from_state(
            $ticket->can($tickets_id, READ),
            GLPICHAT_ROOM_PUBLIC,
            (bool) Session::haveRight('plugin_glpichat_public', READ),
            (bool) Session::haveRight('plugin_glpichat_internal', READ)
        ),
        glpichat_can_read_room_from_state(
            $ticket->can($tickets_id, READ),
            GLPICHAT_ROOM_INTERNAL,
            (bool) Session::haveRight('plugin_glpichat_public', READ),
            (bool) Session::haveRight('plugin_glpichat_internal', READ)
        ),
        glpichat_is_ticket_terminal_for_chat($tickets_id),
        $room_type,
        (bool) Session::haveRight('plugin_glpichat_public', CREATE),
        (bool) Session::haveRight('plugin_glpichat_internal', CREATE)
    );
}

function glpichat_assert_can_send(int $tickets_id, string $room_type): void
{
    glpichat_assert_ticket_open_for_chat($tickets_id);

    if (!glpichat_can_send($tickets_id, $room_type)) {
        throw new RuntimeException('Você não tem permissão para enviar mensagens neste chat.');
    }
}

function glpichat_can_read_room(int $tickets_id, string $room_type): bool
{
    $ticket = glpichat_ticket_or_fail($tickets_id);

    return glpichat_can_read_room_from_state(
        $ticket->can($tickets_id, READ),
        $room_type,
        (bool) Session::haveRight('plugin_glpichat_public', READ),
        (bool) Session::haveRight('plugin_glpichat_internal', READ)
    );
}

function glpichat_assert_can_read_room(int $tickets_id, string $room_type): void
{
    if (!glpichat_can_read_room($tickets_id, $room_type)) {
        throw new RuntimeException('Você não tem permissão para acessar esta sala.');
    }
}

function glpichat_can_view_participants(int $tickets_id): bool
{
    return glpichat_can_view_participants_from_state(
        glpichat_can_read_room($tickets_id, GLPICHAT_ROOM_PUBLIC),
        (bool) Session::haveRight('plugin_glpichat_invite', READ)
    );
}

function glpichat_assert_can_view_participants(int $tickets_id): void
{
    if (!glpichat_can_view_participants($tickets_id)) {
        throw new RuntimeException('Você não tem permissão para visualizar os participantes do chat.');
    }
}

function glpichat_can_create_invites(int $tickets_id): bool
{
    return glpichat_can_create_invites_from_state(
        glpichat_can_read_room($tickets_id, GLPICHAT_ROOM_PUBLIC),
        (bool) Session::haveRight('plugin_glpichat_invite', CREATE)
    );
}

function glpichat_assert_can_create_invites(int $tickets_id): void
{
    if (!glpichat_can_create_invites($tickets_id)) {
        throw new RuntimeException('Você não tem permissão para convidar externos.');
    }
}

function glpichat_can_update_invites(int $tickets_id): bool
{
    return glpichat_can_update_invites_from_state(
        glpichat_can_read_room($tickets_id, GLPICHAT_ROOM_PUBLIC),
        (bool) Session::haveRight('plugin_glpichat_invite', UPDATE)
    );
}

function glpichat_assert_can_update_invites(int $tickets_id): void
{
    if (!glpichat_can_update_invites($tickets_id)) {
        throw new RuntimeException('Você não tem permissão para gerenciar convites.');
    }
}

function glpichat_can_view_audit(int $tickets_id): bool
{
    return glpichat_can_view_audit_from_state(
        glpichat_can_read_ticket($tickets_id),
        (bool) Session::haveRight('plugin_glpichat_view_audit', READ)
    );
}

function glpichat_can_bootstrap_widget(): bool
{
    return glpichat_can_bootstrap_widget_from_state(
        (bool) Session::haveRight('plugin_glpichat_public', READ),
        (bool) Session::haveRight('plugin_glpichat_internal', READ)
    );
}

function glpichat_participant_id_for_user(int $rooms_id, int $users_id): int
{
    global $DB;

    $participant = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_participants',
        'WHERE' => [
            'rooms_id' => $rooms_id,
            'users_id' => $users_id,
            'participant_type' => GLPICHAT_ACTOR_USER,
            'left_at' => null,
        ],
        'LIMIT' => 1,
    ])->current();

    if (is_array($participant)) {
        return (int) $participant['id'];
    }

    $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    $DB->insert('glpi_plugin_glpichat_participants', [
        'rooms_id' => $rooms_id,
        'users_id' => $users_id,
        'participant_type' => GLPICHAT_ACTOR_USER,
        'joined_at' => $now,
        'last_activity' => $now,
        'last_seen' => $now,
    ]);

    $participants_id = (int) $DB->insertId();

    try {
        $room = glpichat_room_by_id($rooms_id);
    } catch (\Throwable $e) {
        return $participants_id; // Sala indisponível em contexto de setup — skip silencioso
    }

    $tickets_id = (int) ($room['tickets_id'] ?? 0);
    $user_name = (string) (getUserName($users_id) ?: ('Usuário #' . $users_id));

    try {
        glpichat_record_event(
            $rooms_id,
            $tickets_id,
            'user_joined',
            GLPICHAT_ACTOR_USER,
            $users_id,
            $participants_id,
            [
                'user_name' => $user_name,
            ]
        );
    } catch (\Throwable $e) {
        trigger_error('glpichat: falha ao registrar evento user_joined: ' . $e->getMessage(), E_USER_WARNING);
    }

    if ($tickets_id > 0) {
        try {
            glpichat_raise_user_joined_notification_event($tickets_id, $user_name);
        } catch (\Throwable $e) {
            trigger_error('glpichat: falha ao disparar notificação user_joined: ' . $e->getMessage(), E_USER_WARNING);
        }
    }

    return $participants_id;
}

function glpichat_active_participant_id_for_user(int $rooms_id, int $users_id): int
{
    global $DB;

    $participant = $DB->request([
        'SELECT' => ['id'],
        'FROM' => 'glpi_plugin_glpichat_participants',
        'WHERE' => [
            'rooms_id' => $rooms_id,
            'users_id' => $users_id,
            'participant_type' => GLPICHAT_ACTOR_USER,
            'left_at' => null,
        ],
        'LIMIT' => 1,
    ])->current();

    return is_array($participant) ? (int) ($participant['id'] ?? 0) : 0;
}

function glpichat_disconnect_user_participant(int $rooms_id, int $users_id): void
{
    global $DB;

    $participant = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_participants',
        'WHERE' => [
            'rooms_id' => $rooms_id,
            'users_id' => $users_id,
            'participant_type' => GLPICHAT_ACTOR_USER,
            'left_at' => null,
        ],
        'LIMIT' => 1,
    ])->current();

    if (!is_array($participant)) {
        return;
    }

    $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    $DB->update('glpi_plugin_glpichat_participants', [
        'left_at' => $now,
    ], [
        'id' => $participant['id'],
    ]);

    try {
        $room = glpichat_room_by_id($rooms_id);
    } catch (\Throwable $e) {
        return; // Sala removida — skip silencioso
    }

    $tickets_id = (int) ($room['tickets_id'] ?? 0);
    $user_name = (string) (getUserName($users_id) ?: ('Usuário #' . $users_id));

    try {
        glpichat_record_event(
            $rooms_id,
            $tickets_id,
            'user_left',
            GLPICHAT_ACTOR_USER,
            $users_id,
            (int) $participant['id'],
            [
                'user_name' => $user_name,
            ]
        );
    } catch (\Throwable $e) {
        trigger_error('glpichat: falha ao registrar evento user_left: ' . $e->getMessage(), E_USER_WARNING);
    }

    if ($tickets_id > 0) {
        try {
            glpichat_raise_user_left_notification_event($tickets_id, $user_name);
        } catch (\Throwable $e) {
            trigger_error('glpichat: falha ao disparar notificação user_left: ' . $e->getMessage(), E_USER_WARNING);
        }
    }
}

function glpichat_expire_inactive_user_participants(): void
{
    global $DB;

    $now_ts = time();
    $threshold_time = date('Y-m-d H:i:s', $now_ts - glpichat_config_int('user_inactivity_timeout_s', 120));

    $participants = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_participants',
        'WHERE' => [
            'participant_type' => GLPICHAT_ACTOR_USER,
            'left_at' => null,
            ['last_activity' => ['<', $threshold_time]],
        ],
    ]);

    $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

    foreach ($participants as $participant) {
        $participants_id = (int) $participant['id'];
        $rooms_id = (int) $participant['rooms_id'];
        $users_id = (int) $participant['users_id'];

        $DB->update('glpi_plugin_glpichat_participants', [
            'left_at' => $now,
        ], [
            'id' => $participants_id,
        ]);

        try {
            $room = glpichat_room_by_id($rooms_id);
        } catch (\Throwable $e) {
            continue; // Sala removida — skip silencioso
        }

        $tickets_id = (int) ($room['tickets_id'] ?? 0);
        $user_name = (string) (getUserName($users_id) ?: ('Usuário #' . $users_id));

        try {
            glpichat_record_event(
                $rooms_id,
                $tickets_id,
                'user_left',
                GLPICHAT_ACTOR_USER,
                $users_id,
                $participants_id,
                [
                    'user_name' => $user_name,
                ]
            );
        } catch (\Throwable $e) {
            trigger_error('glpichat: falha ao registrar evento user_left: ' . $e->getMessage(), E_USER_WARNING);
        }

        if ($tickets_id > 0) {
            try {
                glpichat_raise_user_left_notification_event($tickets_id, $user_name);
            } catch (\Throwable $e) {
                trigger_error('glpichat: falha ao disparar notificação user_left: ' . $e->getMessage(), E_USER_WARNING);
            }
        }
    }
}

function glpichat_add_followup(int $tickets_id, int $users_id, string $content, bool $is_private): int
{
    static $cached_requesttypes_id = null;

    $followup = new ITILFollowup();

    if ($cached_requesttypes_id === null) {
        $requesttype = new RequestType();
        $rt_search = ['name' => 'GLPI Chat'];
        $rt_id = $requesttype->findID($rt_search);
        if ($rt_id <= 0) {
            $rt_id = $requesttype->add([
                'name' => 'GLPI Chat',
                'is_active' => 1,
                'is_itilfollowup' => 1,
            ]);
        }
        $cached_requesttypes_id = $rt_id > 0 ? $rt_id : RequestType::getDefault('followup');
    }

    $requesttypes_id = $cached_requesttypes_id;

    $id = $followup->add([
        'itemtype' => Ticket::class,
        'items_id' => $tickets_id,
        'users_id' => $users_id,
        'content' => $content,
        'is_private' => $is_private ? 1 : 0,
        'requesttypes_id' => $requesttypes_id,
    ]);

    if (!$id) {
        throw new RuntimeException('Unable to create ticket followup.');
    }

    return (int) $id;
}

function glpichat_record_event(
    int $rooms_id,
    int $tickets_id,
    string $event_type,
    string $actor_type,
    int $users_id,
    int $participants_id,
    array $payload
): int {
    global $DB;

    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

    $DB->insert('glpi_plugin_glpichat_events', [
        'rooms_id' => $rooms_id,
        'tickets_id' => $tickets_id,
        'event_type' => $event_type,
        'actor_type' => $actor_type,
        'users_id' => $users_id,
        'participants_id' => $participants_id,
        'payload_json' => $json,
        'date_creation' => $now,
    ]);

    $event_id = (int) $DB->insertId();

    $DB->insert('glpi_plugin_glpichat_auditlogs', [
        'rooms_id' => $rooms_id,
        'tickets_id' => $tickets_id,
        'action' => $event_type,
        'actor_type' => $actor_type,
        'users_id' => $users_id,
        'participants_id' => $participants_id,
        'payload_json' => $json,
        'date_creation' => $now,
    ]);

    return $event_id;
}

function glpichat_raise_message_notification_event(
    int $tickets_id,
    string $room_type,
    string $actor_type,
    int $users_id,
    string $content
): void {
    $ticket = glpichat_ticket_or_fail($tickets_id);

    $event = glpichat_message_notification_event_name($room_type, $actor_type);

    $options = [
        'glpichat_room_type' => $room_type,
        'glpichat_actor_type' => $actor_type,
        'glpichat_users_id' => $users_id,
        'glpichat_content' => $content,
    ];

    if (!NotificationEvent::raiseEvent($event, $ticket, $options)) {
        throw new RuntimeException(sprintf('Unable to raise GLPI notification event "%s".', $event));
    }
}

function glpichat_raise_guest_joined_notification_event(int $tickets_id, string $guest_name): void
{
    $ticket = glpichat_ticket_or_fail($tickets_id);

    $event = 'glpichat_guest_joined';

    $options = [
        'glpichat_guest_name' => $guest_name,
    ];

    if (!NotificationEvent::raiseEvent($event, $ticket, $options)) {
        trigger_error(sprintf('Unable to raise GLPI notification event "%s".', $event), E_USER_WARNING);
    }
}

function glpichat_raise_guest_left_notification_event(int $tickets_id, string $guest_name): void
{
    $ticket = glpichat_ticket_or_fail($tickets_id);

    $event = 'glpichat_guest_left';

    $options = [
        'glpichat_guest_name' => $guest_name,
    ];

    if (!NotificationEvent::raiseEvent($event, $ticket, $options)) {
        trigger_error(sprintf('Unable to raise GLPI notification event "%s".', $event), E_USER_WARNING);
    }
}

function glpichat_raise_guest_left_timeout_notification_event(int $tickets_id, string $guest_name): void
{
    $ticket = glpichat_ticket_or_fail($tickets_id);

    $event = 'glpichat_guest_left_timeout';

    $options = [
        'glpichat_guest_name' => $guest_name,
    ];

    if (!NotificationEvent::raiseEvent($event, $ticket, $options)) {
        trigger_error(sprintf('Unable to raise GLPI notification event "%s".', $event), E_USER_WARNING);
    }
}

function glpichat_raise_user_joined_notification_event(int $tickets_id, string $user_name): void
{
    $ticket = glpichat_ticket_or_fail($tickets_id);

    $event = 'glpichat_user_joined';

    $options = [
        'glpichat_user_name' => $user_name,
    ];

    if (!NotificationEvent::raiseEvent($event, $ticket, $options)) {
        trigger_error(sprintf('Unable to raise GLPI notification event "%s".', $event), E_USER_WARNING);
    }
}

function glpichat_raise_user_left_notification_event(int $tickets_id, string $user_name): void
{
    $ticket = glpichat_ticket_or_fail($tickets_id);

    $event = 'glpichat_user_left';

    $options = [
        'glpichat_user_name' => $user_name,
    ];

    if (!NotificationEvent::raiseEvent($event, $ticket, $options)) {
        trigger_error(sprintf('Unable to raise GLPI notification event "%s".', $event), E_USER_WARNING);
    }
}

/**
 * @param array<string, mixed> $row
 * @return array{sender_name:string,preview:string}
 */
function glpichat_notification_message_summary_from_row(array $row): array
{
    global $DB;

    $sender_name = 'Sistema';
    $actor_type = (string) ($row['participant_type'] ?? '');
    $users_id = (int) ($row['users_id'] ?? 0);
    $participants_id = (int) ($row['participants_id'] ?? 0);

    $user_name = $users_id > 0 ? (string) (getUserName($users_id) ?: '') : '';
    $guest_name = null;
    if ($actor_type === GLPICHAT_ACTOR_GUEST && $participants_id > 0) {
        $participant = $DB->request([
            'FROM' => 'glpi_plugin_glpichat_participants',
            'WHERE' => ['id' => $participants_id],
            'LIMIT' => 1,
        ])->current();
        $guest_name = is_array($participant) && !empty($participant['guest_name'])
            ? (string) $participant['guest_name']
            : null;
    }

    return [
        'sender_name' => glpichat_sender_name_for_actor(
            $actor_type,
            $users_id,
            $participants_id,
            $user_name,
            $guest_name,
            'Usuario #',
            'Convidado #',
            '',
            $sender_name
        ),
        'preview' => glpichat_message_preview((string) ($row['content'] ?? ''), 'Nova mensagem no chat', 180),
    ];
}

function glpichat_latest_message_for_ticket(int $tickets_id): array
{
    global $DB;

    $row = $DB->request([
        'SELECT' => [
            'id',
            'content',
            'participant_type',
            'users_id',
            'participants_id',
        ],
        'FROM' => 'glpi_plugin_glpichat_messages',
        'WHERE' => ['tickets_id' => $tickets_id],
        'ORDER' => ['id DESC'],
        'LIMIT' => 1,
    ])->current();

    if (!is_array($row)) {
        return [
            'sender_name' => 'Sistema',
            'preview' => 'Nova mensagem no chat',
        ];
    }

    return glpichat_notification_message_summary_from_row($row);
}

function glpichat_latest_message_for_room(int $rooms_id): array
{
    global $DB;

    $row = $DB->request([
        'SELECT' => [
            'id',
            'content',
            'participant_type',
            'users_id',
            'participants_id',
        ],
        'FROM' => 'glpi_plugin_glpichat_messages',
        'WHERE' => ['rooms_id' => $rooms_id],
        'ORDER' => ['id DESC'],
        'LIMIT' => 1,
    ])->current();

    if (!is_array($row)) {
        return [
            'sender_name' => 'Sistema',
            'preview' => 'Nova mensagem no chat',
        ];
    }

    return glpichat_notification_message_summary_from_row($row);
}

function glpichat_unread_count_for_user_ticket(int $users_id, int $tickets_id): int
{
    global $DB;

    $participants = $DB->request([
        'SELECT' => [
            'glpi_plugin_glpichat_participants.id',
            'glpi_plugin_glpichat_participants.rooms_id',
        ],
        'FROM' => 'glpi_plugin_glpichat_participants',
        'INNER JOIN' => [
            'glpi_plugin_glpichat_rooms' => [
                'ON' => [
                    'glpi_plugin_glpichat_rooms' => 'id',
                    'glpi_plugin_glpichat_participants' => 'rooms_id',
                ],
            ],
        ],
        'WHERE' => [
            'glpi_plugin_glpichat_participants.users_id' => $users_id,
            'glpi_plugin_glpichat_participants.participant_type' => GLPICHAT_ACTOR_USER,
            'glpi_plugin_glpichat_participants.left_at' => null,
            'glpi_plugin_glpichat_rooms.tickets_id' => $tickets_id,
        ],
    ]);

    $participant_rows = [];
    foreach ($participants as $participant) {
        $participant_rows[] = $participant;
    }

    return glpichat_sum_unread_for_participants(
        $participant_rows,
        'glpichat_unread_count_fast'
    );
}

function glpichat_unread_count_for_user_room(int $users_id, int $rooms_id): int
{
    $participants_id = glpichat_active_participant_id_for_user($rooms_id, $users_id);

    if ($participants_id <= 0) {
        return 0;
    }

    return glpichat_unread_count_fast($rooms_id, $participants_id);
}

function glpichat_can_user_read_notification_room(int $users_id, int $tickets_id, string $room_type): bool
{
    if ($users_id <= 0 || !glpichat_is_valid_room_type($room_type)) {
        return false;
    }

    $ticket = glpichat_ticket_or_fail($tickets_id);
    $entities_id = (int) ($ticket->fields['entities_id'] ?? 0);

    return Profile::haveUserRight($users_id, glpichat_read_right_name($room_type), READ, $entities_id);
}

function glpichat_add_message(
    int $tickets_id,
    string $room_type,
    string $actor_type,
    int $users_id,
    int $participants_id,
    string $content,
    int $documents_id,
    int $itilfollowups_id
): int {
    global $DB;

    $rooms_id = glpichat_room_id($tickets_id, $room_type);
    $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

    $DB->insert('glpi_plugin_glpichat_messages', [
        'rooms_id' => $rooms_id,
        'tickets_id' => $tickets_id,
        'participant_type' => $actor_type,
        'users_id' => $users_id,
        'participants_id' => $participants_id,
        'message_type' => glpichat_message_type_from_document_id($documents_id),
        'content' => $content,
        'documents_id' => $documents_id,
        'itilfollowups_id' => $itilfollowups_id,
        'date_creation' => $now,
    ]);

    $message_id = (int) $DB->insertId();

    if ($participants_id > 0) {
        $DB->update('glpi_plugin_glpichat_participants', [
            'last_activity' => $now,
            'last_seen' => $now,
        ], [
            'id' => $participants_id,
        ]);
    }

    glpichat_record_event($rooms_id, $tickets_id, 'message_created', $actor_type, $users_id, $participants_id, [
        'message_id' => $message_id,
        'room_type' => $room_type,
        'documents_id' => $documents_id,
        'itilfollowups_id' => $itilfollowups_id,
    ]);

    glpichat_raise_message_notification_event($tickets_id, $room_type, $actor_type, $users_id, $content);

    return $message_id;
}

/**
 * Fetch display names for a batch of user IDs in a single query.
 * Replicates the name formatting of getUserName() / formatUserName() without anonymization,
 * which is acceptable in the chat context (agent/technician side).
 *
 * @param array<int> $user_ids
 * @return array<int, string>  Map of users_id => formatted name
 */
function glpichat_batch_user_names(array $user_ids): array
{
    global $DB;

    if (empty($user_ids)) {
        return [];
    }

    $unique_ids = array_values(array_unique(array_filter($user_ids, static fn(int $id): bool => $id > 0)));
    if (empty($unique_ids)) {
        return [];
    }

    $rows = $DB->request([
        'SELECT' => ['id', 'name', 'realname', 'firstname'],
        'FROM'   => 'glpi_users',
        'WHERE'  => ['id' => $unique_ids],
    ]);

    $map = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $map[$id] = (string) formatUserName(
            $id,
            $row['name'] ?? '',
            $row['realname'] ?? '',
            $row['firstname'] ?? ''
        );
    }

    return $map;
}

function glpichat_messages(int $rooms_id, int $last_message_id, string $min_date = ''): array
{
    global $DB;

    $raw_rows = [];
    $guest_name_by_participant = [];
    $where = [
        'rooms_id' => $rooms_id,
        ['id' => ['>', $last_message_id]],
    ];
    if ($min_date !== '') {
        $where[] = ['date_creation' => ['>=', $min_date]];
    }
    $iterator = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_messages',
        'WHERE' => $where,
        'ORDER' => ['id ASC'],
        'LIMIT' => 50,
    ]);

    foreach ($iterator as $row) {
        $raw_rows[] = $row;
    }

    // Collect all users_id values for a single batch lookup
    $user_ids = [];
    foreach ($raw_rows as $row) {
        $uid = (int) ($row['users_id'] ?? 0);
        if ($uid > 0) {
            $user_ids[] = $uid;
        }
    }
    $user_names = glpichat_batch_user_names($user_ids);

    $rows = [];
    foreach ($raw_rows as $row) {
        $actor_type = (string) ($row['participant_type'] ?? '');
        $users_id = (int) ($row['users_id'] ?? 0);
        $participant_id = (int) ($row['participants_id'] ?? 0);
        $guest_name = null;
        if ($actor_type === GLPICHAT_ACTOR_GUEST && $participant_id > 0) {
            if (!array_key_exists($participant_id, $guest_name_by_participant)) {
                $participant = $DB->request([
                    'FROM' => 'glpi_plugin_glpichat_participants',
                    'WHERE' => ['id' => $participant_id],
                    'LIMIT' => 1,
                ])->current();
                $guest_name_by_participant[$participant_id] = is_array($participant) && !empty($participant['guest_name'])
                    ? (string) $participant['guest_name']
                    : null;
            }
            $guest_name = $guest_name_by_participant[$participant_id];
        }

        $rows[] = glpichat_message_output_row(
            $row,
            glpichat_sender_name_for_actor(
                $actor_type,
                $users_id,
                $participant_id,
                $users_id > 0 ? ($user_names[$users_id] ?? '') : '',
                $guest_name,
                'User #',
                'Convidado #',
                ' (Convidado)',
                'Sistema'
            )
        );
    }

    return $rows;
}

function glpichat_message_by_id(int $message_id): array
{
    global $DB;

    if ($message_id <= 0) {
        throw new RuntimeException('Mensagem de chat não encontrada.');
    }

    $row = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_messages',
        'WHERE' => ['id' => $message_id],
        'LIMIT' => 1,
    ])->current();

    if (!is_array($row)) {
        throw new RuntimeException('Mensagem de chat não encontrada.');
    }

    $actor_type = (string) ($row['participant_type'] ?? '');
    $users_id = (int) ($row['users_id'] ?? 0);
    $participant_id = (int) ($row['participants_id'] ?? 0);
    $guest_name = null;

    if ($actor_type === GLPICHAT_ACTOR_GUEST && $participant_id > 0) {
        $participant = $DB->request([
            'FROM' => 'glpi_plugin_glpichat_participants',
            'WHERE' => ['id' => $participant_id],
            'LIMIT' => 1,
        ])->current();
        $guest_name = is_array($participant) && !empty($participant['guest_name'])
            ? (string) $participant['guest_name']
            : null;
    }

    return glpichat_message_output_row(
        $row,
        glpichat_sender_name_for_actor(
            $actor_type,
            $users_id,
            $participant_id,
            $users_id > 0 ? (string) (getUserName($users_id) ?: '') : '',
            $guest_name,
            'User #',
            'Convidado #',
            ' (Convidado)',
            'Sistema'
        )
    );
}

function glpichat_room_last_message_id(int $rooms_id): int
{
    global $DB;

    $row = $DB->request([
        'SELECT' => ['id'],
        'FROM' => 'glpi_plugin_glpichat_messages',
        'WHERE' => ['rooms_id' => $rooms_id],
        'ORDER' => ['id DESC'],
        'LIMIT' => 1,
    ])->current();

    if (!is_array($row)) {
        return 0;
    }

    return (int) ($row['id'] ?? 0);
}

function glpichat_unread_count_fast(int $rooms_id, int $participants_id): int
{
    return glpichat_unread_count_for_participant($rooms_id, $participants_id);
}

function glpichat_room_by_id(int $rooms_id): array
{
    global $DB;

    $room = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_rooms',
        'WHERE' => ['id' => $rooms_id],
        'LIMIT' => 1,
    ])->current();

    if (!is_array($room)) {
        throw new RuntimeException('Sala de chat não encontrada.');
    }

    return $room;
}

function glpichat_messages_before(int $rooms_id, int $before_id, int $limit): array
{
    global $DB;

    $where = ['rooms_id' => $rooms_id];
    if ($before_id > 0) {
        $where[] = ['id' => ['<', $before_id]];
    }

    $raw_rows = [];
    $iterator = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_messages',
        'WHERE' => $where,
        'ORDER' => ['id DESC'],
        'LIMIT' => $limit,
    ]);

    foreach ($iterator as $row) {
        $raw_rows[] = $row;
    }

    // Collect all users_id values for a single batch lookup
    $user_ids = [];
    foreach ($raw_rows as $row) {
        $uid = (int) ($row['users_id'] ?? 0);
        if ($uid > 0) {
            $user_ids[] = $uid;
        }
    }
    $user_names = glpichat_batch_user_names($user_ids);

    $rows = [];
    foreach ($raw_rows as $row) {
        $users_id = (int) ($row['users_id'] ?? 0);
        $rows[] = glpichat_message_output_row(
            $row,
            glpichat_sender_name_for_actor(
                (string) ($row['participant_type'] ?? ''),
                $users_id,
                (int) ($row['participants_id'] ?? 0),
                $users_id > 0 ? ($user_names[$users_id] ?? '') : '',
                null,
                'User #',
                'Convidado #',
                '',
                'Guest'
            )
        );
    }

    return array_reverse($rows);
}

function glpichat_upload_document(int $tickets_id, UploadedFile $file, bool $suppress_document_hook): int
{
    global $CFG_GLPI;

    $original_name = $file->getClientOriginalName();
    $uploadable_pattern = DocumentType::getUploadableFilePattern();
    if ($uploadable_pattern === '' || preg_match($uploadable_pattern, $original_name) !== 1) {
        throw new RuntimeException('Este tipo de arquivo não é permitido pelo GLPI.');
    }

    $file_size = (int) $file->getSize();
    $glpi_max_size = (int) ($CFG_GLPI['document_max_size'] ?? 0) * 1024 * 1024;
    if ($glpi_max_size > 0 && $file_size > $glpi_max_size) {
        throw new RuntimeException('O arquivo enviado excede o limite de tamanho do GLPI.');
    }

    $php_max_size = Toolbox::getPhpUploadSizeLimit();
    if ($php_max_size > 0 && $file_size > $php_max_size) {
        throw new RuntimeException('O arquivo enviado excede o limite de tamanho do servidor.');
    }

    $safe_name = bin2hex(random_bytes(8)) . '_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $original_name);
    $file->move(GLPI_UPLOAD_DIR, $safe_name);
    $stored_path = rtrim(GLPI_UPLOAD_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safe_name;

    try {
        $document = new Document();
        $GLOBALS['GLPICHAT_SUPPRESS_DOCUMENT_HOOK'] = $suppress_document_hook;
        $id = $document->add([
            'itemtype' => Ticket::class,
            'items_id' => $tickets_id,
            'name' => $original_name,
            'upload_file' => $safe_name,
            '_only_if_upload_succeed' => 1,
        ]);
    } catch (\Throwable $error) {
        $GLOBALS['GLPICHAT_SUPPRESS_DOCUMENT_HOOK'] = false;
        if (is_file($stored_path) && !unlink($stored_path)) {
            throw new RuntimeException('Unable to remove orphaned upload file after document creation failure.', 0, $error);
        }
        throw $error;
    }
    $GLOBALS['GLPICHAT_SUPPRESS_DOCUMENT_HOOK'] = false;

    if (!$id) {
        if (is_file($stored_path) && !unlink($stored_path)) {
            throw new RuntimeException('Unable to remove orphaned upload file after document creation failure.');
        }
        throw new RuntimeException('Unable to create GLPI document.');
    }

    return (int) $id;
}
