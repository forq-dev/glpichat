<?php

declare(strict_types=1);

use Glpi\DBAL\QueryExpression;

const GLPICHAT_RIGHTS_MODEL_VERSION = '6';

function glpichat_add_profile_rights(): void
{
    global $DB, $GLPI_CACHE;

    $GLPI_CACHE->set('all_possible_rights', []);

    $profiles = $DB->request([
        'SELECT' => ['id', 'name', 'interface'],
        'FROM'   => Profile::getTable(),
    ]);

    foreach ($profiles as $profile) {
        $profiles_id = (int) ($profile['id'] ?? 0);
        if ($profiles_id <= 0) {
            continue;
        }

        $profile_name      = strtolower((string) ($profile['name'] ?? ''));
        $profile_interface = strtolower((string) ($profile['interface'] ?? ''));

        // Determine sensible default permission mask based on profile category.
        // Standard GLPI profiles are detected by name convention.
        // Helpdesk (self-service) users get read + send-public only.
        // Tech/admin profiles get full read+write on all rights.
        $is_helpdesk           = $profile_interface === 'helpdesk';
        $is_central            = $profile_interface === 'central';
        $is_admin_like         = in_array($profile_name, ['super-admin', 'admin', 'administrator'], true);
        $is_tech_like          = in_array($profile_name, ['technician', 'technician+', 'observer', 'hotliner'], true);
        $is_unknown_central    = $is_central && !$is_admin_like && !$is_tech_like;

        foreach (glpichat_right_names() as $right_name) {
            // Compute the default mask for this right/profile combination.
            $default_mask = glpichat_default_right_mask(
                $right_name,
                $is_helpdesk,
                $is_admin_like,
                $is_tech_like,
                $is_unknown_central
            );

            // Check whether a row already exists for this profile+right pair.
            $existing = $DB->request([
                'SELECT' => ['rights'],
                'FROM'   => ProfileRight::getTable(),
                'WHERE'  => [
                    'profiles_id' => $profiles_id,
                    'name'        => $right_name,
                ],
                'LIMIT' => 1,
            ])->current();

            if (!is_array($existing)) {
                $seed_rights = glpichat_seed_rights_from_legacy_profile($profiles_id, $right_name, $default_mask);

                // Row is missing: insert with a legacy-compatible seed value.
                $DB->insert(ProfileRight::getTable(), [
                    'profiles_id' => $profiles_id,
                    'name'        => $right_name,
                    'rights'      => $seed_rights,
                ]);
                continue;
            }

            // Row exists: apply automatic upgrade for legacy profiles that only
            // have write bits (CREATE=4 or UPDATE=2) but are missing READ=1.
            $current_rights = (int) ($existing['rights'] ?? 0);
            $upgraded_rights = glpichat_upgrade_right_bits($right_name, $current_rights, $default_mask);

            if ($upgraded_rights !== $current_rights) {
                $DB->update(
                    ProfileRight::getTable(),
                    ['rights' => $upgraded_rights],
                    ['profiles_id' => $profiles_id, 'name' => $right_name]
                );
            }
        }
    }
}

function glpichat_ensure_profile_rights_model(): bool
{
    $configured_version = (string) Config::getConfigurationValue('plugin:glpichat', 'rights_model_version');
    if ($configured_version === GLPICHAT_RIGHTS_MODEL_VERSION) {
        return false;
    }

    glpichat_add_profile_rights();
    glpichat_install_default_notifications();
    Config::setConfigurationValues('plugin:glpichat', [
        'rights_model_version' => GLPICHAT_RIGHTS_MODEL_VERSION,
    ]);

    return true;
}

/**
 * Returns the sensible default permission bitmask for a given right and profile type.
 *
 * @param string $right_name      The GLPI plugin right name.
 * @param bool   $is_helpdesk        Whether the profile is a helpdesk/self-service profile.
 * @param bool   $is_admin_like      Whether the profile is an admin profile.
 * @param bool   $is_tech_like       Whether the profile is a tech profile.
 * @param bool   $is_unknown_central Whether the profile is a central profile that did not match the known naming buckets.
 * @return int The bitmask to use as default.
 */
function glpichat_default_right_mask(
    string $right_name,
    bool $is_helpdesk,
    bool $is_admin_like,
    bool $is_tech_like,
    bool $is_unknown_central
): int
{
    if ($is_helpdesk) {
        return match ($right_name) {
            'plugin_glpichat_public'        => READ | CREATE,
            // Helpdesk users do not get internal channel or admin access by default.
            default                          => 0,
        };
    }

    if ($is_admin_like) {
        return match ($right_name) {
            'plugin_glpichat_public'        => READ | CREATE,
            'plugin_glpichat_internal'      => READ | CREATE,
            'plugin_glpichat_invite'        => READ | CREATE | UPDATE,
            'plugin_glpichat_view_audit'    => READ,
            'plugin_glpichat_admin'         => READ | UPDATE,
            default                          => 0,
        };
    }

    if ($is_tech_like) {
        return match ($right_name) {
            'plugin_glpichat_public'        => READ | CREATE,
            'plugin_glpichat_internal'      => READ | CREATE,
            'plugin_glpichat_invite'        => READ | CREATE | UPDATE,
            'plugin_glpichat_view_audit'    => READ,
            default                          => 0,
        };
    }

    if ($is_unknown_central) {
        return match ($right_name) {
            'plugin_glpichat_public'        => READ | CREATE,
            'plugin_glpichat_internal'      => READ | CREATE,
            'plugin_glpichat_invite'        => READ | CREATE | UPDATE,
            'plugin_glpichat_view_audit'    => READ,
            default                          => 0,
        };
    }

    // Unknown profile type: do not touch the existing value; default to 0 for new rows.
    return 0;
}

function glpichat_seed_rights_from_legacy_profile(int $profiles_id, string $right_name, int $default_mask): int
{
    global $DB;

    if ($right_name === 'plugin_glpichat_public') {
        $legacy_public_read = $DB->request([
            'SELECT' => ['rights'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => [
                'profiles_id' => $profiles_id,
                'name'        => 'plugin_glpichat_read_public',
            ],
            'LIMIT' => 1,
        ])->current();
        $legacy_public_send = $DB->request([
            'SELECT' => ['rights'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => [
                'profiles_id' => $profiles_id,
                'name'        => 'plugin_glpichat_send_public',
            ],
            'LIMIT' => 1,
        ])->current();
        $legacy_generic_read = $DB->request([
            'SELECT' => ['rights'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => [
                'profiles_id' => $profiles_id,
                'name'        => 'plugin_glpichat_read',
            ],
            'LIMIT' => 1,
        ])->current();

        $seed_rights = 0;
        if (is_array($legacy_public_read) && ((int) ($legacy_public_read['rights'] ?? 0) & READ) !== 0) {
            $seed_rights |= READ;
        }
        if (is_array($legacy_public_send)) {
            $legacy_public_send_rights = (int) ($legacy_public_send['rights'] ?? 0);
            if (($legacy_public_send_rights & READ) !== 0) {
                $seed_rights |= READ;
            }
            if (($legacy_public_send_rights & CREATE) !== 0) {
                $seed_rights |= CREATE;
            }
        }
        if (is_array($legacy_generic_read) && ((int) ($legacy_generic_read['rights'] ?? 0) & READ) !== 0) {
            $seed_rights |= READ;
        }
        if ($seed_rights > 0) {
            return $seed_rights;
        }
    }

    if ($right_name === 'plugin_glpichat_internal') {
        $legacy_internal_read = $DB->request([
            'SELECT' => ['rights'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => [
                'profiles_id' => $profiles_id,
                'name'        => 'plugin_glpichat_read_internal',
            ],
            'LIMIT' => 1,
        ])->current();
        $legacy_internal_send = $DB->request([
            'SELECT' => ['rights'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => [
                'profiles_id' => $profiles_id,
                'name'        => 'plugin_glpichat_send_internal',
            ],
            'LIMIT' => 1,
        ])->current();

        $seed_rights = 0;
        if (is_array($legacy_internal_read) && ((int) ($legacy_internal_read['rights'] ?? 0) & READ) !== 0) {
            $seed_rights |= READ;
        }
        if (is_array($legacy_internal_send)) {
            $legacy_internal_send_rights = (int) ($legacy_internal_send['rights'] ?? 0);
            if (($legacy_internal_send_rights & READ) !== 0) {
                $seed_rights |= READ;
            }
            if (($legacy_internal_send_rights & CREATE) !== 0) {
                $seed_rights |= CREATE;
            }
        }
        if ($seed_rights > 0) {
            return $seed_rights;
        }
    }

    return $default_mask;
}

/**
 * Upgrades the current rights bitmask for a given right to ensure READ is present
 * whenever the profile already holds any write bit (CREATE or UPDATE).
 *
 * This fixes the lockout caused by profiles migrated before READ was exposed in the
 * UI permission matrix: those profiles have CREATE=4 but are missing READ=1, which
 * causes Session::haveRight(..., READ) to return false and block legitimate access.
 *
 * @param string $right_name      The GLPI plugin right name (used for targeted logic).
 * @param int    $current_rights  The bitmask currently stored in the database.
 * @param int    $default_mask    The sensible default mask for this profile type.
 * @return int The upgraded bitmask (unchanged if no upgrade was needed).
 */
function glpichat_upgrade_right_bits(string $right_name, int $current_rights, int $default_mask): int
{
    // Rights that require READ whenever any write bit is present.
    $rights_requiring_read_upgrade = [
        'plugin_glpichat_public',
        'plugin_glpichat_internal',
        'plugin_glpichat_invite',
        'plugin_glpichat_view_audit',
        'plugin_glpichat_admin',
    ];

    if (!in_array($right_name, $rights_requiring_read_upgrade, true)) {
        return $current_rights;
    }

    // If the profile has any write bit but lacks READ, add READ.
    // This fixes the lockout caused by profiles migrated before READ was exposed
    // in the UI: those profiles have CREATE=4 but are missing READ=1.
    $has_write_bit = ($current_rights & (CREATE | UPDATE)) !== 0;
    $lacks_read    = ($current_rights & READ) === 0;

    if ($has_write_bit && $lacks_read) {
        return $current_rights | READ;
    }

    // Do NOT promote an existing row with rights = 0 to the default mask.
    // A value of 0 on an existing row means an admin explicitly revoked access
    // for this profile; silently restoring it would bypass that intent.
    // New rows (no existing row) are handled by the INSERT branch in
    // glpichat_add_profile_rights() before this function is ever called.

    return $current_rights;
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
            `last_activity` TIMESTAMP NULL DEFAULT NULL,
            `last_seen` TIMESTAMP NULL DEFAULT NULL,
            `is_typing_until` TIMESTAMP NULL DEFAULT NULL,
            `last_send_at` TIMESTAMP NULL DEFAULT NULL,
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
    glpichat_drop_mismatched_participants_room_user_index(['users_id', 'rooms_id']);
    $migration->addKey('glpi_plugin_glpichat_participants', ['users_id', 'rooms_id'], 'participants_room_user');
    $migration->addKey('glpi_plugin_glpichat_participants', 'session_token_hash', 'participants_session');
    $migration->addKey('glpi_plugin_glpichat_readstates', ['participants_id', 'rooms_id'], 'readstates_participant_room');
    $migration->addKey('glpi_plugin_glpichat_invites', 'expires_at', 'invites_expires_at');
    $migration->addKey('glpi_plugin_glpichat_auditlogs', ['tickets_id', 'date_creation'], 'audit_ticket_date');
}

function glpichat_ensure_runtime_schema(): void
{
    global $DB;

    static $schema_checked = false;
    if ($schema_checked) {
        return;
    }
    $schema_checked = true;

    $participants_table = 'glpi_plugin_glpichat_participants';
    if (!$DB->tableExists($participants_table)) {
        return;
    }

    $missing_columns = [];
    if (!$DB->fieldExists($participants_table, 'last_activity')) {
        $missing_columns[] = 'ADD COLUMN `last_activity` TIMESTAMP NULL DEFAULT NULL';
    }
    if (!$DB->fieldExists($participants_table, 'last_seen')) {
        $missing_columns[] = 'ADD COLUMN `last_seen` TIMESTAMP NULL DEFAULT NULL';
    }
    if (!$DB->fieldExists($participants_table, 'is_typing_until')) {
        $missing_columns[] = 'ADD COLUMN `is_typing_until` TIMESTAMP NULL DEFAULT NULL';
    }
    if (!$DB->fieldExists($participants_table, 'last_send_at')) {
        $missing_columns[] = 'ADD COLUMN `last_send_at` TIMESTAMP NULL DEFAULT NULL';
    }

    if ($missing_columns === []) {
        return;
    }

    $DB->doQuery(sprintf(
        'ALTER TABLE %s %s',
        $DB::quoteName($participants_table),
        implode(', ', $missing_columns)
    ));
}

function glpichat_drop_mismatched_participants_room_user_index(array $expected_columns): void
{
    global $DB;

    $table = 'glpi_plugin_glpichat_participants';
    $index = 'participants_room_user';
    if (!$DB->tableExists($table) || !isIndex($table, $index)) {
        return;
    }

    $columns = glpichat_index_columns($table, $index);
    if ($columns === $expected_columns) {
        return;
    }

    $DB->doQuery(sprintf(
        'ALTER TABLE %s DROP INDEX %s',
        $DB::quoteName($table),
        $DB::quoteName($index)
    ));
}

function glpichat_index_columns(string $table, string $index): array
{
    global $DB;

    $result = $DB->doQuery(sprintf('SHOW INDEX FROM %s', $DB::quoteName($table)));
    if (!$result) {
        throw new RuntimeException(sprintf('Failed to inspect index "%s" on table "%s".', $index, $table));
    }

    $indexed_columns = [];
    while ($row = $DB->fetchAssoc($result)) {
        if (($row['Key_name'] ?? null) !== $index) {
            continue;
        }

        $sequence = (int) ($row['Seq_in_index'] ?? 0);
        if ($sequence <= 0) {
            throw new RuntimeException(sprintf('Invalid sequence metadata for index "%s" on table "%s".', $index, $table));
        }

        $column = $row['Column_name'] ?? null;
        if (!is_string($column) || $column === '') {
            throw new RuntimeException(sprintf('Invalid column metadata for index "%s" on table "%s".', $index, $table));
        }

        $indexed_columns[$sequence] = $column;
    }

    ksort($indexed_columns);

    return array_values($indexed_columns);
}

function glpichat_install_default_notifications(): void
{
    $template_id = glpichat_ensure_default_notification_template();
    foreach (glpichat_default_notification_events() as $event) {
        glpichat_ensure_default_notification_definition($template_id, $event);
    }
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
    glpichat_ensure_notification_mode($notification_id, $template_id, Notification_NotificationTemplate::MODE_MAIL);
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
