<?php

declare(strict_types=1);

use GlpiPlugin\Glpichat\EventCleanupCron;

$loader = require dirname(__DIR__, 5) . '/vendor/autoload.php';
$loader->addPsr4('GlpiPlugin\\Glpichat\\', dirname(__DIR__, 3) . '/src/');

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

if (!function_exists('plugin_init_glpichat')) {
    require dirname(__DIR__, 3) . '/setup.php';
}
if (!function_exists('plugin_glpichat_install')) {
    require dirname(__DIR__, 3) . '/hook.php';
}

/**
 * @return never
 */
function task6Fail(string $message): void
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function task6Pass(string $message): void
{
    fwrite(STDOUT, "[PASS] {$message}\n");
}

function task6Assert(bool $condition, string $message): void
{
    if (!$condition) {
        task6Fail($message);
    }
}

function task6EventExists(int $events_id): bool
{
    global $DB;

    $rows = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_events',
        'WHERE' => [
            'id' => $events_id,
        ],
    ]);

    return $rows->count() > 0;
}

function task6InsertEvent(string $event_type, string $date_creation): int
{
    global $DB;

    $DB->insert('glpi_plugin_glpichat_events', [
        'rooms_id' => 1,
        'tickets_id' => 1,
        'event_type' => $event_type,
        'actor_type' => 'system',
        'users_id' => 0,
        'participants_id' => 0,
        'payload_json' => json_encode(['source' => 'event_cleanup_smoke_test'], JSON_THROW_ON_ERROR),
        'date_creation' => $date_creation,
    ]);

    return (int) $DB->insertId();
}

plugin_glpichat_install();

$index_columns = glpichat_index_columns('glpi_plugin_glpichat_participants', 'participants_room_user');
task6Assert($index_columns === ['users_id', 'rooms_id'], 'Indice participants_room_user deve estar em users_id, rooms_id');
task6Pass('Indice participants_room_user esta otimizado');

$cron_rows = $DB->request([
    'FROM' => 'glpi_crontasks',
    'WHERE' => [
        'itemtype' => EventCleanupCron::class,
        'name' => 'CleanEvents',
    ],
]);
task6Assert($cron_rows->count() === 1, 'CronTask CleanEvents deve estar registrado');
task6Pass('CronTask CleanEvents esta registrado');

$old_event_id = task6InsertEvent('event_cleanup_smoke_old', date('Y-m-d H:i:s', strtotime('-31 days')));
$fresh_event_id = task6InsertEvent('event_cleanup_smoke_fresh', date('Y-m-d H:i:s', strtotime('-1 day')));

try {
    $deleted = EventCleanupCron::deleteExpiredEvents();
    task6Assert($deleted >= 1, 'Limpeza deve remover pelo menos o evento antigo de teste');
    task6Assert(!task6EventExists($old_event_id), 'Evento antigo deve ser removido');
    task6Assert(task6EventExists($fresh_event_id), 'Evento recente deve permanecer');
    task6Pass('Cron de limpeza remove eventos antigos e preserva eventos recentes');
} finally {
    $DB->delete('glpi_plugin_glpichat_events', [
        'event_type' => ['event_cleanup_smoke_old', 'event_cleanup_smoke_fresh'],
    ]);
}

fwrite(STDOUT, "[PASS] Smoke de cleanup de eventos concluido\n");
