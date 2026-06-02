<?php

declare(strict_types=1);

require_once __DIR__ . '/src/functions.php';
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

    glpichat_add_profile_rights();
    Config::setConfigurationValues('plugin:glpichat', [
        'external_followup_user_id' => (string) Config::getConfigurationValue('plugin:glpichat', 'external_followup_user_id') ?: '0',
        'default_notifications_bootstrapped' => '1',
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

    ProfileRight::deleteProfileRights(glpichat_right_names());
    Config::deleteConfigurationValues('plugin:glpichat', ['external_followup_user_id', 'default_notifications_bootstrapped']);

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
