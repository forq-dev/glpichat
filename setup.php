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

    $PLUGIN_HOOKS['csrf_compliant']['glpichat'] = true;
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['glpichat'] = ['public/css/glpichat.css'];
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['glpichat'] = ['public/js/glpichat.js'];
    $PLUGIN_HOOKS['add_css']['glpichat'] = ['public/css/glpichat.css'];
    $PLUGIN_HOOKS['add_javascript']['glpichat'] = ['public/js/glpichat.js'];
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['glpichat'] = [HookHandler::class, 'itemAdd'];
    $PLUGIN_HOOKS[Hooks::ITEM_GET_EVENTS]['glpichat'] = [NotificationTargetTicket::class => [HookHandler::class, 'itemGetEvents']];
    $PLUGIN_HOOKS[Hooks::ITEM_GET_DATA]['glpichat'] = [NotificationTargetTicket::class => [HookHandler::class, 'itemGetDatas']];

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
        'author'         => 'GLPI Chat contributors',
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
