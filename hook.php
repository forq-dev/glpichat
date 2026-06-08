<?php

declare(strict_types=1);

require_once __DIR__ . '/src/functions.php';
require_once __DIR__ . '/src/EventCleanupCron.php';
require_once __DIR__ . '/src/HookHandler.php';

function plugin_glpichat_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_GLPICHAT_VERSION);

    foreach (glpichat_schema_queries() as $table => $query) {
        if (!$DB->tableExists($table)) {
            $DB->doQuery($query);
        }
    }

    glpichat_add_indexes($migration);

    if ($DB->tableExists('glpi_plugin_glpichat_participants') && !$DB->fieldExists('glpi_plugin_glpichat_participants', 'last_activity')) {
        $migration->addField('glpi_plugin_glpichat_participants', 'last_activity', 'TIMESTAMP NULL DEFAULT NULL');
    }
    if ($DB->tableExists('glpi_plugin_glpichat_participants') && !$DB->fieldExists('glpi_plugin_glpichat_participants', 'last_seen')) {
        $migration->addField('glpi_plugin_glpichat_participants', 'last_seen', 'TIMESTAMP NULL DEFAULT NULL');
    }
    if ($DB->tableExists('glpi_plugin_glpichat_participants') && !$DB->fieldExists('glpi_plugin_glpichat_participants', 'is_typing_until')) {
        $migration->addField('glpi_plugin_glpichat_participants', 'is_typing_until', 'TIMESTAMP NULL DEFAULT NULL');
    }

    $migration->addCrontask(\GlpiPlugin\Glpichat\EventCleanupCron::class, 'CleanEvents', DAY_TIMESTAMP);
    $migration->addCrontask(\GlpiPlugin\Glpichat\EventCleanupCron::class, 'CleanMessages', DAY_TIMESTAMP);
    $migration->addCrontask(\GlpiPlugin\Glpichat\EventCleanupCron::class, 'CleanAuditlogs', DAY_TIMESTAMP);

    glpichat_add_profile_rights();

    // Helper: preserve existing value if non-empty, otherwise use the provided default.
    // A stored value of '0' is valid (e.g. auto_open_ticket_chat = '0' means disabled)
    // and is correctly preserved because '0' !== ''.
    $cfg_default = static function (string $key, string $default): string {
        $v = (string) Config::getConfigurationValue('plugin:glpichat', $key);
        return $v !== '' ? $v : $default;
    };

    Config::setConfigurationValues('plugin:glpichat', [
        'external_followup_user_id'          => (string)(int) Config::getConfigurationValue('plugin:glpichat', 'external_followup_user_id'),
        'default_notifications_bootstrapped' => '1',
        'rights_model_version'               => GLPICHAT_RIGHTS_MODEL_VERSION,
        // Bloco A — Polling & Sincronização
        'poll_focused_ms'              => $cfg_default('poll_focused_ms', '2500'),
        'poll_idle_ms'                 => $cfg_default('poll_idle_ms', '9000'),
        'poll_bg_ms'                   => $cfg_default('poll_bg_ms', '18000'),
        'guest_poll_ms'                => $cfg_default('guest_poll_ms', '5000'),
        'meta_sync_ms'                 => $cfg_default('meta_sync_ms', '15000'),
        'invite_poll_ms'               => $cfg_default('invite_poll_ms', '10000'),
        'poll_max_channels'            => $cfg_default('poll_max_channels', '20'),
        'user_inactivity_timeout_s'    => $cfg_default('user_inactivity_timeout_s', '120'),
        // Bloco B — Sessão & Segurança
        'guest_session_ttl'            => $cfg_default('guest_session_ttl', '3600'),
        'invite_ttl_seconds'           => $cfg_default('invite_ttl_seconds', '3600'),
        'guest_send_throttle_s'        => $cfg_default('guest_send_throttle_s', '2'),
        'accept_rate_limit_window_s'   => $cfg_default('accept_rate_limit_window_s', '60'),
        'accept_rate_limit_max_attempts' => $cfg_default('accept_rate_limit_max_attempts', '10'),
        'max_invites_per_ticket'       => $cfg_default('max_invites_per_ticket', '10'),
        // Bloco C — Interface & UX
        'desktop_max_open'             => $cfg_default('desktop_max_open', '3'),
        'widget_default_position'      => $cfg_default('widget_default_position', 'bottom-right'),
        'typing_indicator_timeout_ms'  => $cfg_default('typing_indicator_timeout_ms', '3000'),
        'auto_open_ticket_chat'        => $cfg_default('auto_open_ticket_chat', '1'),
        'auto_scroll_threshold_px'     => $cfg_default('auto_scroll_threshold_px', '160'),
        'unread_badge_threshold'       => $cfg_default('unread_badge_threshold', '2'),
        // Bloco D — Comportamento do Chat
        'history_page_default'         => $cfg_default('history_page_default', '30'),
        'history_page_max'             => $cfg_default('history_page_max', '50'),
        // Bloco E — Limpeza & Retenção
        'events_retention_days'        => $cfg_default('events_retention_days', '30'),
        'messages_retention_days'      => $cfg_default('messages_retention_days', '365'),
        'auditlogs_retention_days'     => $cfg_default('auditlogs_retention_days', '180'),
    ]);

    // Register RequestType "GLPI Chat" if it doesn't exist
    $requesttype = new RequestType();
    $rt_search = ['name' => 'GLPI Chat'];
    $id = $requesttype->findID($rt_search);
    if ($id <= 0) {
        $requesttype->add([
            'name' => 'GLPI Chat',
            'is_active' => 1,
            'is_itilfollowup' => 1,
        ]);
    }

    glpichat_install_default_notifications();

    $migration->executeMigration();

    return true;
}

function plugin_glpichat_uninstall(): bool
{
    global $DB;

    foreach (array_reverse(glpichat_table_names()) as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery(sprintf('DROP TABLE `%s`', $table));
        }
    }

    ProfileRight::deleteProfileRights(array_merge(glpichat_right_names(), glpichat_legacy_right_names()));
    Config::deleteConfigurationValues('plugin:glpichat', [
        'external_followup_user_id',
        'default_notifications_bootstrapped',
        'rights_model_version',
        // Bloco A
        'poll_focused_ms',
        'poll_idle_ms',
        'poll_bg_ms',
        'guest_poll_ms',
        'meta_sync_ms',
        'invite_poll_ms',
        'poll_max_channels',
        'user_inactivity_timeout_s',
        // Bloco B
        'guest_session_ttl',
        'invite_ttl_seconds',
        'guest_send_throttle_s',
        'accept_rate_limit_window_s',
        'accept_rate_limit_max_attempts',
        'max_invites_per_ticket',
        // Bloco C
        'desktop_max_open',
        'widget_default_position',
        'typing_indicator_timeout_ms',
        'auto_open_ticket_chat',
        'auto_scroll_threshold_px',
        'unread_badge_threshold',
        // Bloco D
        'history_page_default',
        'history_page_max',
        // Bloco E
        'events_retention_days',
        'messages_retention_days',
        'auditlogs_retention_days',
    ]);
    $DB->delete('glpi_crontasks', [
        'itemtype' => \GlpiPlugin\Glpichat\EventCleanupCron::class,
        'name' => 'CleanEvents',
    ]);
    $DB->delete('glpi_crontasks', [
        'itemtype' => \GlpiPlugin\Glpichat\EventCleanupCron::class,
        'name' => 'CleanMessages',
    ]);
    $DB->delete('glpi_crontasks', [
        'itemtype' => \GlpiPlugin\Glpichat\EventCleanupCron::class,
        'name' => 'CleanAuditlogs',
    ]);

    // Delete RequestType "GLPI Chat" if it exists
    $requesttype = new RequestType();
    $rt_search = ['name' => 'GLPI Chat'];
    $id = $requesttype->findID($rt_search);
    if ($id > 0) {
        $requesttype->delete(['id' => $id], true);
    }

    return true;
}

function plugin_glpichat_item_get_events(mixed $target): void
{
    \GlpiPlugin\Glpichat\HookHandler::itemGetEvents($target);
}

function plugin_glpichat_item_get_datas(mixed $target): void
{
    \GlpiPlugin\Glpichat\HookHandler::itemGetDatas($target);
}
