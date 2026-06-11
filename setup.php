<?php

declare(strict_types=1);

use Glpi\Http\SessionManager;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Glpichat\EventCleanupCron;
use GlpiPlugin\Glpichat\HookHandler;
use GlpiPlugin\Glpichat\ProfileTab;
use GlpiPlugin\Glpichat\TicketTab;

define('PLUGIN_GLPICHAT_VERSION', '1.0.0');

require_once __DIR__ . '/src/functions.php';

function plugin_init_glpichat(): void
{
    global $PLUGIN_HOOKS;

    // Schema/rights mutations must only run in an admin or CLI/cron context.
    // On any regular non-admin request, skip mutations silently; the next admin
    // login will trigger them. This prevents privilege escalation where an
    // unprivileged request could ALTER TABLE or rewrite profile rights.
    $can_mutate = isCommandLine()
        || Session::isCron()
        || Session::haveRight('config', UPDATE);

    if ($can_mutate) {
        glpichat_ensure_runtime_schema();
        $rights_model_changed = glpichat_ensure_profile_rights_model();
        if (
            $rights_model_changed
            && Session::getLoginUserID() !== false
            && isset($_SESSION['glpiactiveprofile']['id'])
        ) {
            Session::reloadCurrentProfile();
        }
    }

    $PLUGIN_HOOKS['csrf_compliant']['glpichat'] = true;
    $PLUGIN_HOOKS['config_page']['glpichat'] = 'front/config.php';
    if (Session::getLoginUserID() !== false && glpichat_can_bootstrap_widget()) {
        $PLUGIN_HOOKS[Hooks::ADD_CSS]['glpichat'] = ['public/css/glpichat.css'];
        // Load glpichat-widget.js as traditional script (ES6 modules will be handled in Bundling Phase 5)
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['glpichat'] = ['public/js/glpichat-widget.js'];
        $PLUGIN_HOOKS['add_css']['glpichat'] = ['public/css/glpichat.css'];
        $PLUGIN_HOOKS['add_javascript']['glpichat'] = ['public/js/glpichat-widget.js'];
    }
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['glpichat'] = [HookHandler::class, 'itemAdd'];
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['glpichat'] = [HookHandler::class, 'preItemUpdate'];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['glpichat'] = [HookHandler::class, 'itemUpdate'];
    $PLUGIN_HOOKS[Hooks::ITEM_GET_EVENTS]['glpichat'] = [NotificationTargetTicket::class => [HookHandler::class, 'itemGetEvents']];
    $PLUGIN_HOOKS[Hooks::ITEM_GET_DATA]['glpichat'] = [NotificationTargetTicket::class => [HookHandler::class, 'itemGetDatas']];
    // Filter recipients for glpichat_internal_message: users without plugin_glpichat_internal READ
    // are removed from the target list before any email is queued, so they never receive even
    // an empty notification revealing the existence of the internal-channel event.
    $PLUGIN_HOOKS[Hooks::ADD_RECIPIENT_TO_TARGET]['glpichat'] = [NotificationTargetTicket::class => [HookHandler::class, 'filterInternalMessageTargets']];
    // Block download of documents uploaded to the internal channel for users without
    // plugin_glpichat_internal READ. CommonDBTM::can() fires this hook for every
    // Document::can($id, READ) call — including those triggered by canViewFile().
    $PLUGIN_HOOKS[Hooks::ITEM_CAN]['glpichat'] = [Document::class => [HookHandler::class, 'canDocument']];

    Plugin::registerClass(TicketTab::class, ['addtabon' => Ticket::class]);
    Plugin::registerClass(ProfileTab::class, ['addtabon' => Profile::class]);
    Plugin::registerClass(EventCleanupCron::class);

    SessionManager::registerPluginStatelessPath('glpichat', '#^/Guest/.*#');

    // Prevent glpichat rights from being stripped from the session for helpdesk interface users
    foreach (glpichat_right_names() as $right) {
        \Profile::$helpdesk_rights[] = $right;
    }
}

function plugin_version_glpichat(): array
{
    return [
        'name'           => 'GLPI Chat',
        'version'        => PLUGIN_GLPICHAT_VERSION,
        'author'         => 'Forq Labs',
        'license'        => 'GPLv3+',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => '11.0.7',
            ],
            'php' => [
                'min' => '8.2',
            ],
        ],
    ];
}

function plugin_glpichat_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, '11.0.7', '>=');
}

function plugin_glpichat_check_config(): bool
{
    return true;
}
