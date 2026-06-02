<?php

declare(strict_types=1);

use Glpi\DBAL\QueryExpression;
use Symfony\Component\HttpFoundation\File\UploadedFile;

const GLPICHAT_ROOM_PUBLIC = 'public';
const GLPICHAT_ROOM_INTERNAL = 'internal';
const GLPICHAT_ACTOR_USER = 'user';
const GLPICHAT_ACTOR_GUEST = 'guest';
const GLPICHAT_INVITE_TTL_SECONDS = 3600;
const GLPICHAT_GUEST_COOKIE = 'glpichat_guest_session';

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
        'plugin_glpichat_read',
        'plugin_glpichat_send_public',
        'plugin_glpichat_send_internal',
        'plugin_glpichat_invite',
        'plugin_glpichat_view_audit',
        'plugin_glpichat_admin',
    ];
}

function glpichat_add_profile_rights(): void
{
    global $DB, $GLPI_CACHE;

    $GLPI_CACHE->set('all_possible_rights', []);
    $profiles = $DB->request([
        'SELECT' => ['id'],
        'FROM' => Profile::getTable(),
    ]);

    foreach ($profiles as $profile) {
        $profiles_id = (int) ($profile['id'] ?? 0);
        if ($profiles_id <= 0) {
            continue;
        }

        foreach (glpichat_right_names() as $name) {
            $DB->updateOrInsert(ProfileRight::getTable(), [
                'profiles_id' => $profiles_id,
                'name' => $name,
            ], [
                'profiles_id' => $profiles_id,
                'name' => $name,
            ]);
        }
    }
}

function glpichat_schema_queries(): array
{
    $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC';

    return [
        'glpi_plugin_glpichat_rooms' => "CREATE TABLE `glpi_plugin_glpichat_rooms` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tickets_id` INT UNSIGNED NOT NULL,
            `room_type` VARCHAR(20) NOT NULL,
            `date_creation` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`tickets_id`, `room_type`)
        ) $options",
        'glpi_plugin_glpichat_participants' => "CREATE TABLE `glpi_plugin_glpichat_participants` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `rooms_id` INT UNSIGNED NOT NULL,
            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `guest_name` VARCHAR(255) DEFAULT NULL,
            `guest_email` VARCHAR(255) DEFAULT NULL,
            `participant_type` VARCHAR(20) NOT NULL,
            `session_token_hash` CHAR(64) DEFAULT NULL,
            `joined_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `left_at` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) $options",
        'glpi_plugin_glpichat_messages' => "CREATE TABLE `glpi_plugin_glpichat_messages` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `rooms_id` INT UNSIGNED NOT NULL,
            `tickets_id` INT UNSIGNED NOT NULL,
            `participant_type` VARCHAR(20) NOT NULL,
            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `participants_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `message_type` VARCHAR(30) NOT NULL,
            `content` LONGTEXT NOT NULL,
            `documents_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `itilfollowups_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) $options",
        'glpi_plugin_glpichat_events' => "CREATE TABLE `glpi_plugin_glpichat_events` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `rooms_id` INT UNSIGNED NOT NULL,
            `tickets_id` INT UNSIGNED NOT NULL,
            `event_type` VARCHAR(60) NOT NULL,
            `actor_type` VARCHAR(20) NOT NULL,
            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `participants_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `payload_json` JSON NOT NULL,
            `date_creation` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) $options",
        'glpi_plugin_glpichat_invites' => "CREATE TABLE `glpi_plugin_glpichat_invites` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `rooms_id` INT UNSIGNED NOT NULL,
            `tickets_id` INT UNSIGNED NOT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `created_by` INT UNSIGNED NOT NULL,
            `guest_name` VARCHAR(255) NOT NULL,
            `guest_email` VARCHAR(255) DEFAULT NULL,
            `expires_at` TIMESTAMP NOT NULL,
            `accepted_at` TIMESTAMP NULL DEFAULT NULL,
            `revoked_at` TIMESTAMP NULL DEFAULT NULL,
            `participants_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `token_hash` (`token_hash`)
        ) $options",
        'glpi_plugin_glpichat_readstates' => "CREATE TABLE `glpi_plugin_glpichat_readstates` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `rooms_id` INT UNSIGNED NOT NULL,
            `participants_id` INT UNSIGNED NOT NULL,
            `last_read_messages_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_mod` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `room_participant` (`rooms_id`, `participants_id`)
        ) $options",
        'glpi_plugin_glpichat_auditlogs' => "CREATE TABLE `glpi_plugin_glpichat_auditlogs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `rooms_id` INT UNSIGNED NOT NULL,
            `tickets_id` INT UNSIGNED NOT NULL,
            `action` VARCHAR(60) NOT NULL,
            `actor_type` VARCHAR(20) NOT NULL,
            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `participants_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `payload_json` JSON NOT NULL,
            `date_creation` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) $options",
    ];
}

function glpichat_add_indexes(Migration $migration): void
{
    $migration->addKey('glpi_plugin_glpichat_messages', ['rooms_id', 'id'], 'messages_room_id');
    $migration->addKey('glpi_plugin_glpichat_messages', ['tickets_id', 'id'], 'messages_ticket_id');
    $migration->addKey('glpi_plugin_glpichat_messages', ['participants_id', 'id'], 'messages_participant_id');
    $migration->addKey('glpi_plugin_glpichat_events', ['rooms_id', 'id'], 'events_room_id');
    $migration->addKey('glpi_plugin_glpichat_events', ['tickets_id', 'id'], 'events_ticket_id');
    $migration->addKey('glpi_plugin_glpichat_participants', ['rooms_id', 'users_id'], 'participants_room_user');
    $migration->addKey('glpi_plugin_glpichat_participants', 'session_token_hash', 'participants_session');
    $migration->addKey('glpi_plugin_glpichat_readstates', ['participants_id', 'rooms_id'], 'readstates_participant_room');
    $migration->addKey('glpi_plugin_glpichat_invites', 'expires_at', 'invites_expires_at');
    $migration->addKey('glpi_plugin_glpichat_auditlogs', ['tickets_id', 'date_creation'], 'audit_ticket_date');
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

function glpichat_room_id(int $tickets_id, string $room_type): int
{
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
        return (int) $room['id'];
    }

    $DB->insert('glpi_plugin_glpichat_rooms', [
        'tickets_id' => $tickets_id,
        'room_type' => $room_type,
        'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
    ]);

    return (int) $DB->insertId();
}

function glpichat_ticket_or_fail(int $tickets_id): Ticket
{
    $ticket = new Ticket();
    if (!$ticket->getFromDB($tickets_id)) {
        throw new RuntimeException('Ticket not found.');
    }

    return $ticket;
}

function glpichat_can_read_ticket(int $tickets_id): bool
{
    $ticket = glpichat_ticket_or_fail($tickets_id);
    if (!$ticket->can($tickets_id, READ)) {
        return false;
    }

    return (bool) Session::haveRight('plugin_glpichat_read', READ);
}

function glpichat_assert_can_read_ticket(int $tickets_id): void
{
    if (!glpichat_can_read_ticket($tickets_id)) {
        throw new RuntimeException('User cannot read this chat.');
    }
}

function glpichat_can_send(int $tickets_id, string $room_type): bool
{
    if (!glpichat_can_read_ticket($tickets_id)) {
        return false;
    }

    $right = $room_type === GLPICHAT_ROOM_INTERNAL
        ? 'plugin_glpichat_send_internal'
        : 'plugin_glpichat_send_public';

    return (bool) Session::haveRight($right, CREATE);
}

function glpichat_assert_can_send(int $tickets_id, string $room_type): void
{
    if (!glpichat_can_send($tickets_id, $room_type)) {
        throw new RuntimeException('User cannot send messages to this room.');
    }
}

function glpichat_can_read_room(int $tickets_id, string $room_type): bool
{
    if (!glpichat_can_read_ticket($tickets_id)) {
        return false;
    }

    if ($room_type === GLPICHAT_ROOM_INTERNAL) {
        return (bool) Session::haveRight('plugin_glpichat_send_internal', CREATE);
    }

    return true;
}

function glpichat_assert_can_read_room(int $tickets_id, string $room_type): void
{
    if (!glpichat_can_read_room($tickets_id, $room_type)) {
        throw new RuntimeException('User cannot read this room.');
    }
}

function glpichat_can_view_participants(int $tickets_id): bool
{
    return glpichat_can_read_ticket($tickets_id)
        && (bool) Session::haveRight('plugin_glpichat_invite', READ);
}

function glpichat_assert_can_view_participants(int $tickets_id): void
{
    if (!glpichat_can_view_participants($tickets_id)) {
        throw new RuntimeException('User cannot view chat participants.');
    }
}

function glpichat_can_view_audit(int $tickets_id): bool
{
    return glpichat_can_read_ticket($tickets_id)
        && (bool) Session::haveRight('plugin_glpichat_view_audit', READ);
}

function glpichat_assert_can_view_audit(int $tickets_id): void
{
    if (!glpichat_can_view_audit($tickets_id)) {
        throw new RuntimeException('User cannot view chat audit.');
    }
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

    $DB->insert('glpi_plugin_glpichat_participants', [
        'rooms_id' => $rooms_id,
        'users_id' => $users_id,
        'participant_type' => GLPICHAT_ACTOR_USER,
        'joined_at' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
    ]);

    return (int) $DB->insertId();
}

function glpichat_add_followup(int $tickets_id, int $users_id, string $content, bool $is_private): int
{
    $followup = new ITILFollowup();

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

    $requesttypes_id = $rt_id > 0 ? $rt_id : RequestType::getDefault('followup');

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

    $event = match (true) {
        $actor_type === GLPICHAT_ACTOR_GUEST => 'glpichat_guest_message',
        $room_type === GLPICHAT_ROOM_INTERNAL => 'glpichat_internal_message',
        default => 'glpichat_public_message',
    };

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
        default => 'GLPI CHAT - Notificacao chat',
    };
}

function glpichat_default_notification_events(): array
{
    return [
        'glpichat_public_message',
        'glpichat_internal_message',
        'glpichat_guest_message',
    ];
}

function glpichat_default_template_subject(): string
{
    return '##glpichat.ticket_label##';
}

function glpichat_default_template_text(): string
{
    return "##glpichat.ticket_label##\n##glpichat.sender_name##: ##glpichat.message_preview## ##glpichat.unread_badge##";
}

function glpichat_default_template_html(): string
{
    return '<p><strong>##glpichat.ticket_label##</strong></p><p>##glpichat.sender_name##: ##glpichat.message_preview## ##glpichat.unread_badge##</p>';
}

function glpichat_install_default_notifications(): void
{
    $template_id = glpichat_ensure_default_notification_template();
    foreach (glpichat_default_notification_events() as $event) {
        glpichat_ensure_default_notification_definition($template_id, $event);
    }
}

function glpichat_bootstrap_default_notifications_once(): void
{
    $is_bootstrapped = (string) Config::getConfigurationValue('plugin:glpichat', 'default_notifications_bootstrapped');
    if ($is_bootstrapped === '1') {
        return;
    }

    glpichat_install_default_notifications();
    Config::setConfigurationValues('plugin:glpichat', [
        'default_notifications_bootstrapped' => '1',
    ]);
}

function glpichat_ensure_default_notification_template(): int
{
    $template = new NotificationTemplate();
    $existing = $template->find([
        'name' => glpichat_default_notification_template_name(),
        'itemtype' => Ticket::class,
    ]);

    if (is_array($existing) && count($existing) > 0) {
        $row = reset($existing);
        $template_id = (int) ($row['id'] ?? 0);
        if ($template_id <= 0) {
            throw new RuntimeException('Invalid existing notification template id.');
        }
        glpichat_upsert_notification_template_translation($template_id);
        return $template_id;
    }

    $template_id = (int) $template->add([
        'name' => glpichat_default_notification_template_name(),
        'itemtype' => Ticket::class,
        'comment' => 'Default GLPI Chat notification template',
    ]);
    if ($template_id <= 0) {
        throw new RuntimeException('Unable to create default GLPI Chat notification template.');
    }

    glpichat_upsert_notification_template_translation($template_id);
    return $template_id;
}

function glpichat_upsert_notification_template_translation(int $template_id): void
{
    $translation = new NotificationTemplateTranslation();
    $existing = $translation->find([
        'notificationtemplates_id' => $template_id,
        'language' => '',
    ]);

    $input = [
        'notificationtemplates_id' => $template_id,
        'language' => '',
        'subject' => glpichat_default_template_subject(),
        'content_text' => glpichat_default_template_text(),
        'content_html' => glpichat_default_template_html(),
    ];

    if (is_array($existing) && count($existing) > 0) {
        $row = reset($existing);
        $translation_id = (int) ($row['id'] ?? 0);
        if ($translation_id <= 0) {
            throw new RuntimeException('Invalid existing notification template translation id.');
        }
        $input['id'] = $translation_id;
        if (!$translation->update($input)) {
            throw new RuntimeException('Unable to update default GLPI Chat template translation.');
        }
        return;
    }

    $translation_id = (int) $translation->add($input);
    if ($translation_id <= 0) {
        throw new RuntimeException('Unable to create default GLPI Chat template translation.');
    }
}

function glpichat_ensure_default_notification_definition(int $template_id, string $event): void
{
    $notification = new Notification();
    $definition_name = glpichat_default_notification_definition_name($event);
    $existing = $notification->find([
        'name' => $definition_name,
        'itemtype' => Ticket::class,
        'event' => $event,
        'entities_id' => 0,
    ]);

    if (is_array($existing) && count($existing) > 0) {
        $row = reset($existing);
        $notification_id = (int) ($row['id'] ?? 0);
        if ($notification_id <= 0) {
            throw new RuntimeException('Invalid existing notification definition id.');
        }
    } else {
        $notification_id = (int) $notification->add([
            'name' => $definition_name,
            'entities_id' => 0,
            'itemtype' => Ticket::class,
            'event' => $event,
            'is_recursive' => 1,
            'is_active' => 1,
            'comment' => 'Default GLPI Chat notification definition',
        ]);
        if ($notification_id <= 0) {
            throw new RuntimeException(sprintf('Unable to create notification definition for event "%s".', $event));
        }
    }

    glpichat_ensure_notification_mode($notification_id, $template_id, Notification_NotificationTemplate::MODE_AJAX);
    glpichat_ensure_default_notification_targets($notification_id);
}

function glpichat_ensure_notification_mode(int $notification_id, int $template_id, string $mode): void
{
    $relation = new Notification_NotificationTemplate();
    $existing = $relation->find([
        'notifications_id' => $notification_id,
        'mode' => $mode,
    ]);

    if (is_array($existing) && count($existing) > 0) {
        $row = reset($existing);
        $relation_id = (int) ($row['id'] ?? 0);
        if ($relation_id <= 0) {
            throw new RuntimeException('Invalid existing notification/template relation id.');
        }
        if ((int) ($row['notificationtemplates_id'] ?? 0) !== $template_id) {
            if (!$relation->update([
                'id' => $relation_id,
                'notifications_id' => $notification_id,
                'notificationtemplates_id' => $template_id,
                'mode' => $mode,
            ])) {
                throw new RuntimeException('Unable to update notification/template relation.');
            }
        }
        return;
    }

    $relation_id = (int) $relation->add([
        'notifications_id' => $notification_id,
        'notificationtemplates_id' => $template_id,
        'mode' => $mode,
    ]);
    if ($relation_id <= 0) {
        throw new RuntimeException('Unable to create notification/template relation.');
    }
}

function glpichat_ensure_default_notification_targets(int $notification_id): void
{
    $target = new NotificationTarget();
    $default_targets = [
        Notification::ASSIGN_TECH,
        Notification::ASSIGN_GROUP,
        Notification::OBSERVER,
        Notification::OBSERVER_GROUP,
    ];

    foreach ($default_targets as $items_id) {
        $existing = $target->find([
            'notifications_id' => $notification_id,
            'type' => Notification::USER_TYPE,
            'items_id' => $items_id,
            'is_exclusion' => 0,
        ]);
        if (is_array($existing) && count($existing) > 0) {
            continue;
        }
        $target_id = (int) $target->add([
            'notifications_id' => $notification_id,
            'type' => Notification::USER_TYPE,
            'items_id' => $items_id,
            'is_exclusion' => 0,
        ]);
        if ($target_id <= 0) {
            throw new RuntimeException(sprintf('Unable to add default notification target (%d).', $items_id));
        }
    }
}

function glpichat_latest_message_for_ticket(int $tickets_id): array
{
    global $DB;

    $row = $DB->request([
        'SELECT' => [
            'm.id',
            'm.content',
            'm.participant_type',
            'm.users_id',
            'm.participants_id',
        ],
        'FROM' => ['m' => 'glpi_plugin_glpichat_messages'],
        'WHERE' => ['m.tickets_id' => $tickets_id],
        'ORDER' => ['m.id DESC'],
        'LIMIT' => 1,
    ])->current();

    if (!is_array($row)) {
        return [
            'sender_name' => 'Sistema',
            'preview' => 'Nova mensagem no chat',
        ];
    }

    $sender_name = 'Sistema';
    $actor_type = (string) ($row['participant_type'] ?? '');
    $users_id = (int) ($row['users_id'] ?? 0);
    $participants_id = (int) ($row['participants_id'] ?? 0);

    if ($actor_type === GLPICHAT_ACTOR_USER && $users_id > 0) {
        $sender_name = getUserName($users_id) ?: ('Usuario #' . $users_id);
    } elseif ($actor_type === GLPICHAT_ACTOR_GUEST && $participants_id > 0) {
        $participant = $DB->request([
            'FROM' => 'glpi_plugin_glpichat_participants',
            'WHERE' => ['id' => $participants_id],
            'LIMIT' => 1,
        ])->current();
        $sender_name = is_array($participant) && !empty($participant['guest_name'])
            ? (string) $participant['guest_name']
            : ('Convidado #' . $participants_id);
    }

    $content = trim((string) ($row['content'] ?? ''));
    if ($content === '') {
        $content = 'Nova mensagem no chat';
    }
    if (strlen($content) > 180) {
        $content = substr($content, 0, 177) . '...';
    }

    return [
        'sender_name' => $sender_name,
        'preview' => $content,
    ];
}

function glpichat_unread_count_for_user_ticket(int $users_id, int $tickets_id): int
{
    global $DB;

    $participants = $DB->request([
        'SELECT' => ['p.id', 'p.rooms_id'],
        'FROM' => ['p' => 'glpi_plugin_glpichat_participants'],
        'INNER JOIN' => [
            'glpi_plugin_glpichat_rooms' => [
                'ON' => [
                    'glpi_plugin_glpichat_rooms' => 'id',
                    'p' => 'rooms_id',
                ],
            ],
        ],
        'WHERE' => [
            'p.users_id' => $users_id,
            'p.participant_type' => GLPICHAT_ACTOR_USER,
            'p.left_at' => null,
            'glpi_plugin_glpichat_rooms.tickets_id' => $tickets_id,
        ],
    ]);

    $total_unread = 0;
    foreach ($participants as $participant) {
        $rooms_id = (int) ($participant['rooms_id'] ?? 0);
        $participants_id = (int) ($participant['id'] ?? 0);
        if ($rooms_id <= 0 || $participants_id <= 0) {
            continue;
        }
        $total_unread += glpichat_unread_count_fast($rooms_id, $participants_id);
    }

    if ($total_unread > 0) {
        return $total_unread;
    }

    $fallback = $DB->request([
        'SELECT' => ['COUNT' => 'cpt'],
        'FROM' => 'glpi_plugin_glpichat_messages',
        'WHERE' => ['tickets_id' => $tickets_id],
    ])->current();

    return (int) ($fallback['cpt'] ?? 0);
}

function glpichat_display_unread_count(int $raw_unread): int
{
    if ($raw_unread <= 1) {
        return 0;
    }

    return $raw_unread;
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
        'message_type' => $documents_id > 0 ? 'attachment' : 'message',
        'content' => $content,
        'documents_id' => $documents_id,
        'itilfollowups_id' => $itilfollowups_id,
        'date_creation' => $now,
    ]);

    $message_id = (int) $DB->insertId();

    glpichat_record_event($rooms_id, $tickets_id, 'message_created', $actor_type, $users_id, $participants_id, [
        'message_id' => $message_id,
        'room_type' => $room_type,
        'documents_id' => $documents_id,
        'itilfollowups_id' => $itilfollowups_id,
    ]);

    glpichat_raise_message_notification_event($tickets_id, $room_type, $actor_type, $users_id, $content);

    return $message_id;
}

function glpichat_messages(int $rooms_id, int $last_message_id): array
{
    global $DB;

    $rows = [];
    $guest_name_by_participant = [];
    $iterator = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_messages',
        'WHERE' => [
            'rooms_id' => $rooms_id,
            ['id' => ['>', $last_message_id]],
        ],
        'ORDER' => ['id ASC'],
        'LIMIT' => 50,
    ]);

    foreach ($iterator as $row) {
        $sender_name = '';
        if ($row['participant_type'] === 'user' && $row['users_id'] > 0) {
            $sender_name = getUserName($row['users_id']);
            if (empty($sender_name)) {
                $sender_name = 'User #' . $row['users_id'];
            }
        } elseif ($row['participant_type'] === 'guest' && $row['participants_id'] > 0) {
            $participant_id = (int) $row['participants_id'];
            if (!array_key_exists($participant_id, $guest_name_by_participant)) {
                $participant = $DB->request([
                    'FROM' => 'glpi_plugin_glpichat_participants',
                    'WHERE' => ['id' => $participant_id],
                    'LIMIT' => 1,
                ])->current();
                if (is_array($participant) && !empty($participant['guest_name'])) {
                    $guest_name_by_participant[$participant_id] = $participant['guest_name'] . ' (Convidado)';
                } else {
                    $guest_name_by_participant[$participant_id] = 'Convidado #' . $participant_id;
                }
            }
            $sender_name = (string) $guest_name_by_participant[$participant_id];
            if ($sender_name === '') {
                $sender_name = 'Convidado #' . $participant_id;
            }
        } else {
            $sender_name = 'Sistema';
        }

        $rows[] = [
            'id' => (int) $row['id'],
            'actor_type' => $row['participant_type'],
            'users_id' => (int) $row['users_id'],
            'participants_id' => (int) $row['participants_id'],
            'sender_name' => $sender_name,
            'message_type' => $row['message_type'],
            'content' => $row['content'],
            'documents_id' => (int) $row['documents_id'],
            'itilfollowups_id' => (int) $row['itilfollowups_id'],
            'date_creation' => $row['date_creation'],
        ];
    }

    return $rows;
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

function glpichat_events(int $rooms_id, int $last_event_id): array
{
    global $DB;

    $rows = [];
    $iterator = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_events',
        'WHERE' => [
            'rooms_id' => $rooms_id,
            ['id' => ['>', $last_event_id]],
        ],
        'ORDER' => ['id ASC'],
        'LIMIT' => 50,
    ]);

    foreach ($iterator as $row) {
        $rows[] = [
            'id' => (int) $row['id'],
            'event_type' => $row['event_type'],
            'actor_type' => $row['actor_type'],
            'users_id' => (int) $row['users_id'],
            'participants_id' => (int) $row['participants_id'],
            'payload' => json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR),
            'date_creation' => $row['date_creation'],
        ];
    }

    return $rows;
}

function glpichat_unread_count_fast(int $rooms_id, int $participants_id): int
{
    if ($participants_id <= 0) {
        return 0;
    }

    $last_message_id = glpichat_room_last_message_id($rooms_id);
    if ($last_message_id <= 0) {
        return 0;
    }

    global $DB;
    $read = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_readstates',
        'WHERE' => [
            'rooms_id' => $rooms_id,
            'participants_id' => $participants_id,
        ],
        'LIMIT' => 1,
    ])->current();
    $last_read = is_array($read) ? (int) $read['last_read_messages_id'] : 0;

    return max(0, $last_message_id - $last_read);
}

function glpichat_create_invite(int $tickets_id, string $guest_name, string $guest_email): array
{
    global $DB, $CFG_GLPI;

    glpichat_assert_can_read_ticket($tickets_id);
    if (!Session::haveRight('plugin_glpichat_invite', CREATE)) {
        throw new RuntimeException('User cannot invite guests.');
    }

    $rooms_id = glpichat_room_id($tickets_id, GLPICHAT_ROOM_PUBLIC);
    $token = bin2hex(random_bytes(32));
    $token_hash = hash('sha256', $token);
    $expires_at = date('Y-m-d H:i:s', time() + GLPICHAT_INVITE_TTL_SECONDS);

    $DB->insert('glpi_plugin_glpichat_invites', [
        'rooms_id' => $rooms_id,
        'tickets_id' => $tickets_id,
        'token_hash' => $token_hash,
        'created_by' => glpichat_current_user_id(),
        'guest_name' => $guest_name,
        'guest_email' => $guest_email,
        'expires_at' => $expires_at,
        'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
    ]);

    $invite_id = (int) $DB->insertId();
    glpichat_record_event($rooms_id, $tickets_id, 'guest_invited', GLPICHAT_ACTOR_USER, glpichat_current_user_id(), 0, [
        'invite_id' => $invite_id,
        'guest_name' => $guest_name,
        'expires_at' => $expires_at,
    ]);

    return [
        'id' => $invite_id,
        'expires_at' => $expires_at,
        'url' => rtrim($CFG_GLPI['url_base'], '/') . '/plugins/glpichat/Accept/' . $token,
    ];
}

function glpichat_accept_invite(string $token): array
{
    global $DB;

    $token_hash = hash('sha256', $token);
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

    if (!is_array($invite)) {
        throw new RuntimeException('Invite is invalid, expired, accepted, or revoked.');
    }

    $session_token = bin2hex(random_bytes(32));
    $session_hash = hash('sha256', $session_token);

    $DB->insert('glpi_plugin_glpichat_participants', [
        'rooms_id' => (int) $invite['rooms_id'],
        'users_id' => 0,
        'guest_name' => $invite['guest_name'],
        'guest_email' => $invite['guest_email'],
        'participant_type' => GLPICHAT_ACTOR_GUEST,
        'session_token_hash' => $session_hash,
        'joined_at' => date('Y-m-d H:i:s'),
    ]);

    $participants_id = (int) $DB->insertId();
    $DB->update('glpi_plugin_glpichat_invites', [
        'accepted_at' => date('Y-m-d H:i:s'),
        'participants_id' => $participants_id,
    ], [
        'id' => (int) $invite['id'],
    ]);

    glpichat_record_event((int) $invite['rooms_id'], (int) $invite['tickets_id'], 'guest_joined', GLPICHAT_ACTOR_GUEST, 0, $participants_id, [
        'invite_id' => (int) $invite['id'],
        'guest_name' => $invite['guest_name'],
    ]);

    glpichat_add_followup(
        (int) $invite['tickets_id'],
        glpichat_external_followup_user_id(),
        sprintf('Guest %s joined the chat.', $invite['guest_name']),
        false
    );

    return [
        'session_token' => $session_token,
        'participants_id' => $participants_id,
        'rooms_id' => (int) $invite['rooms_id'],
        'tickets_id' => (int) $invite['tickets_id'],
        'guest_name' => $invite['guest_name'],
    ];
}

function glpichat_guest_participant(string $session_token): array
{
    global $DB;

    $participant = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_participants',
        'WHERE' => [
            'session_token_hash' => hash('sha256', $session_token),
            'participant_type' => GLPICHAT_ACTOR_GUEST,
            'left_at' => null,
        ],
        'LIMIT' => 1,
    ])->current();

    if (!is_array($participant)) {
        throw new RuntimeException('Guest session is invalid.');
    }

    return $participant;
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
        throw new RuntimeException('Chat room not found.');
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

    $rows = [];
    $iterator = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_messages',
        'WHERE' => $where,
        'ORDER' => ['id DESC'],
        'LIMIT' => $limit,
    ]);

    foreach ($iterator as $row) {
        $rows[] = [
            'id' => (int) $row['id'],
            'actor_type' => (string) $row['participant_type'],
            'users_id' => (int) $row['users_id'],
            'participants_id' => (int) $row['participants_id'],
            'sender_name' => (string) ($row['participant_type'] === 'user' ? (getUserName((int) $row['users_id']) ?: ('User #' . (int) $row['users_id'])) : 'Guest'),
            'message_type' => (string) $row['message_type'],
            'content' => (string) $row['content'],
            'documents_id' => (int) $row['documents_id'],
            'itilfollowups_id' => (int) $row['itilfollowups_id'],
            'date_creation' => (string) $row['date_creation'],
        ];
    }

    return array_reverse($rows);
}

function glpichat_mark_read(int $rooms_id, int $participants_id, int $last_read_messages_id): void
{
    global $DB;

    if ($participants_id <= 0) {
        return;
    }

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
        $next = max($current, $last_read_messages_id);
        $DB->update('glpi_plugin_glpichat_readstates', [
            'last_read_messages_id' => $next,
            'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ], [
            'id' => (int) $existing['id'],
        ]);
        return;
    }

    $DB->insert('glpi_plugin_glpichat_readstates', [
        'rooms_id' => $rooms_id,
        'participants_id' => $participants_id,
        'last_read_messages_id' => max(0, $last_read_messages_id),
        'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
    ]);
}

function glpichat_unread_count(int $rooms_id, int $participants_id): int
{
    global $DB;

    $read = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_readstates',
        'WHERE' => [
            'rooms_id' => $rooms_id,
            'participants_id' => $participants_id,
        ],
        'LIMIT' => 1,
    ])->current();
    $last_read = is_array($read) ? (int) $read['last_read_messages_id'] : 0;

    return (int) $DB->request([
        'COUNT' => 'cpt',
        'FROM' => 'glpi_plugin_glpichat_messages',
        'WHERE' => [
            'rooms_id' => $rooms_id,
            ['id' => ['>', $last_read]],
        ],
    ])->current()['cpt'];
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

    if (count($linked_tickets) > 0) {
        $ticket_rows = $DB->request([
            'SELECT' => ['id', 'status'],
            'FROM' => 'glpi_tickets',
            'WHERE' => ['id' => array_keys($linked_tickets)],
        ]);
        foreach ($ticket_rows as $row) {
            $ticket_status_by_id[(int) $row['id']] = (int) $row['status'];
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

            $key = (int) $tickets_id . ':' . $room_type;
            if (isset($seen[$key])) {
                continue;
            }

            $rooms_id = glpichat_room_id((int) $tickets_id, $room_type);
            $participants_id = glpichat_participant_id_for_user($rooms_id, $users_id);
            $seen[$key] = true;
            $result[] = [
                'rooms_id' => $rooms_id,
                'tickets_id' => (int) $tickets_id,
                'room_type' => $room_type,
                'participants_id' => $participants_id,
                'unread_count' => glpichat_unread_count_fast($rooms_id, $participants_id),
                'last_message_id' => glpichat_room_last_message_id($rooms_id),
                'ticket_status' => $ticket_status_by_id[(int) $tickets_id] ?? 0,
            ];
        }

        if (count($result) >= 80) {
            break;
        }
    }

    return $result;
}

function glpichat_list_invites(int $tickets_id): array
{
    global $DB;

    glpichat_assert_can_read_ticket($tickets_id);
    glpichat_assert_can_view_participants($tickets_id);

    $rows = [];
    $iterator = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_invites',
        'WHERE' => ['tickets_id' => $tickets_id],
        'ORDER' => ['id DESC'],
        'LIMIT' => 100,
    ]);

    foreach ($iterator as $row) {
        $status = 'active';
        if (!empty($row['accepted_at'])) {
            $status = 'accepted';
        } elseif (!empty($row['revoked_at'])) {
            $status = 'revoked';
        } elseif (strtotime((string) $row['expires_at']) <= time()) {
            $status = 'expired';
        }

        $rows[] = [
            'id' => (int) $row['id'],
            'guest_name' => (string) $row['guest_name'],
            'guest_email' => (string) ($row['guest_email'] ?? ''),
            'created_by' => (int) $row['created_by'],
            'created_by_name' => getUserName((int) $row['created_by']) ?: ('User #' . (int) $row['created_by']),
            'date_creation' => (string) $row['date_creation'],
            'expires_at' => (string) $row['expires_at'],
            'accepted_at' => (string) ($row['accepted_at'] ?? ''),
            'revoked_at' => (string) ($row['revoked_at'] ?? ''),
            'was_accessed' => !empty($row['accepted_at']),
            'status' => $status,
        ];
    }

    return $rows;
}

function glpichat_revoke_invite(int $invite_id): void
{
    global $DB;

    $invite = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_invites',
        'WHERE' => ['id' => $invite_id],
        'LIMIT' => 1,
    ])->current();

    if (!is_array($invite)) {
        throw new RuntimeException('Invite not found.');
    }

    glpichat_assert_can_read_ticket((int) $invite['tickets_id']);
    if (!Session::haveRight('plugin_glpichat_invite', CREATE)) {
        throw new RuntimeException('User cannot revoke invites.');
    }

    if (!empty($invite['accepted_at']) || !empty($invite['revoked_at'])) {
        throw new RuntimeException('Invite cannot be revoked.');
    }

    $DB->update('glpi_plugin_glpichat_invites', [
        'revoked_at' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
    ], [
        'id' => $invite_id,
    ]);

    glpichat_record_event((int) $invite['rooms_id'], (int) $invite['tickets_id'], 'invite_revoked', GLPICHAT_ACTOR_USER, glpichat_current_user_id(), 0, [
        'invite_id' => $invite_id,
    ]);
}

function glpichat_reissue_invite(int $invite_id): array
{
    global $DB, $CFG_GLPI;

    $invite = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_invites',
        'WHERE' => ['id' => $invite_id],
        'LIMIT' => 1,
    ])->current();

    if (!is_array($invite)) {
        throw new RuntimeException('Invite not found.');
    }

    glpichat_assert_can_read_ticket((int) $invite['tickets_id']);
    if (!Session::haveRight('plugin_glpichat_invite', CREATE)) {
        throw new RuntimeException('User cannot reissue invites.');
    }
    if (!empty($invite['accepted_at'])) {
        throw new RuntimeException('Accepted invite cannot be reissued.');
    }

    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', time() + GLPICHAT_INVITE_TTL_SECONDS);
    $DB->update('glpi_plugin_glpichat_invites', [
        'token_hash' => hash('sha256', $token),
        'revoked_at' => null,
        'expires_at' => $expires_at,
    ], [
        'id' => $invite_id,
    ]);

    glpichat_record_event((int) $invite['rooms_id'], (int) $invite['tickets_id'], 'invite_reissued', GLPICHAT_ACTOR_USER, glpichat_current_user_id(), 0, [
        'invite_id' => $invite_id,
        'expires_at' => $expires_at,
    ]);

    return [
        'id' => $invite_id,
        'expires_at' => $expires_at,
        'url' => rtrim($CFG_GLPI['url_base'], '/') . '/plugins/glpichat/Accept/' . $token,
    ];
}

function glpichat_upload_document(int $tickets_id, UploadedFile $file, bool $suppress_document_hook): int
{
    $original_name = $file->getClientOriginalName();
    $safe_name = bin2hex(random_bytes(8)) . '_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $original_name);
    $file->move(GLPI_UPLOAD_DIR, $safe_name);

    $document = new Document();
    $GLOBALS['GLPICHAT_SUPPRESS_DOCUMENT_HOOK'] = $suppress_document_hook;
    $id = $document->add([
        'itemtype' => Ticket::class,
        'items_id' => $tickets_id,
        'name' => $original_name,
        'upload_file' => $safe_name,
        '_only_if_upload_succeed' => 1,
    ]);
    $GLOBALS['GLPICHAT_SUPPRESS_DOCUMENT_HOOK'] = false;

    if (!$id) {
        throw new RuntimeException('Unable to create GLPI document.');
    }

    return (int) $id;
}
