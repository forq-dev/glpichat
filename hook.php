<?php

declare(strict_types=1);

require_once __DIR__ . '/src/functions.php';

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

    ProfileRight::addProfileRights(glpichat_right_names());
    Config::setConfigurationValues('plugin:glpichat', [
        'external_followup_user_id' => (string) Config::getConfigurationValue('plugin:glpichat', 'external_followup_user_id') ?: '0',
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
    Config::deleteConfigurationValues('plugin:glpichat', ['external_followup_user_id']);

    // Delete RequestType "GLPI Chat" if it exists
    $requesttype = new RequestType();
    $rt_search = ['name' => 'GLPI Chat'];
    $id = $requesttype->findID($rt_search);
    if ($id > 0) {
        $requesttype->delete(['id' => $id], true);
    }

    return true;
}
