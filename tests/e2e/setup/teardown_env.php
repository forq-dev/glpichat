<?php

declare(strict_types=1);

/**
 * Esteira glpichat - teardown de ambiente.
 *
 * Remove os dados do plugin associados ao ticket dedicado da suite e, por fim,
 * remove (purge) o proprio Ticket.
 *
 * O ticket id e recebido via env GLPICHAT_TICKET_ID ou argv[1].
 *
 * Rodado no container via:
 *   docker exec -e GLPICHAT_TICKET_ID glpi php \
 *     /var/www/glpi/plugins/glpichat/tests/e2e/setup/teardown_env.php
 */

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

Session::start();
plugin_glpichat_install();
plugin_init_glpichat();

function teardownInfo(string $message): void
{
    fwrite(STDOUT, "[INFO] {$message}\n");
}

function teardownPass(string $message): void
{
    fwrite(STDOUT, "[PASS] {$message}\n");
}

function teardownWarn(string $message): void
{
    fwrite(STDOUT, "[WARN] {$message}\n");
}

global $DB;

$tickets_id = (int) (getenv('GLPICHAT_TICKET_ID') ?: 0);
if ($tickets_id <= 0 && isset($argv[1])) {
    $tickets_id = (int) $argv[1];
}

if ($tickets_id <= 0) {
    fwrite(STDERR, "[FAIL] GLPICHAT_TICKET_ID ausente ou invalido; nada a remover\n");
    exit(1);
}

teardownInfo("Teardown do ticket {$tickets_id}");

// 1. Resolver as salas do ticket.
$rooms_ids = [];
try {
    $rows = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => 'glpi_plugin_glpichat_rooms',
        'WHERE'  => ['tickets_id' => $tickets_id],
    ]);
    foreach ($rows as $row) {
        $rooms_ids[] = (int) ($row['id'] ?? 0);
    }
    $rooms_ids = array_values(array_filter($rooms_ids, static fn(int $id): bool => $id > 0));
    teardownInfo('Salas encontradas: ' . (count($rooms_ids) > 0 ? implode(',', $rooms_ids) : '(nenhuma)'));
} catch (Throwable $error) {
    teardownWarn('Falha ao listar salas: ' . $error->getMessage());
}

// 2. Remover dados por rooms_id (best-effort).
if (count($rooms_ids) > 0) {
    $by_room_tables = [
        'glpi_plugin_glpichat_messages',
        'glpi_plugin_glpichat_events',
        'glpi_plugin_glpichat_invites',
        'glpi_plugin_glpichat_readstates',
        'glpi_plugin_glpichat_auditlogs',
        'glpi_plugin_glpichat_participants',
    ];
    foreach ($by_room_tables as $table) {
        try {
            $DB->delete($table, ['rooms_id' => $rooms_ids]);
            teardownInfo("Removido de {$table} (rooms_id IN " . implode(',', $rooms_ids) . ')');
        } catch (Throwable $error) {
            teardownWarn("Falha ao limpar {$table}: " . $error->getMessage());
        }
    }
}

// 3. Limpeza adicional por tickets_id (algumas tabelas carregam tickets_id).
$by_ticket_tables = [
    'glpi_plugin_glpichat_messages',
    'glpi_plugin_glpichat_events',
    'glpi_plugin_glpichat_invites',
    'glpi_plugin_glpichat_auditlogs',
];
foreach ($by_ticket_tables as $table) {
    try {
        if ($DB->fieldExists($table, 'tickets_id')) {
            $DB->delete($table, ['tickets_id' => $tickets_id]);
            teardownInfo("Removido de {$table} (tickets_id={$tickets_id})");
        }
    } catch (Throwable $error) {
        teardownWarn("Falha ao limpar {$table} por tickets_id: " . $error->getMessage());
    }
}

// 4. Remover as salas.
try {
    $DB->delete('glpi_plugin_glpichat_rooms', ['tickets_id' => $tickets_id]);
    teardownInfo("Removido de glpi_plugin_glpichat_rooms (tickets_id={$tickets_id})");
} catch (Throwable $error) {
    teardownWarn('Falha ao remover salas: ' . $error->getMessage());
}

// 5. Purgar o Ticket.
try {
    $ticket = new Ticket();
    if ($ticket->getFromDB($tickets_id)) {
        $deleted = $ticket->delete(['id' => $tickets_id], true);
        if ($deleted) {
            teardownPass("Ticket {$tickets_id} removido (purge)");
        } else {
            teardownWarn("Ticket {$tickets_id} nao pode ser removido via API; tentando delete direto");
            $DB->delete('glpi_tickets', ['id' => $tickets_id]);
            $DB->delete('glpi_tickets_users', ['tickets_id' => $tickets_id]);
            $DB->delete('glpi_groups_tickets', ['tickets_id' => $tickets_id]);
            teardownInfo("Ticket {$tickets_id} removido via delete direto (fallback)");
        }
    } else {
        teardownWarn("Ticket {$tickets_id} ja nao existe em glpi_tickets");
    }
} catch (Throwable $error) {
    teardownWarn('Falha ao remover o Ticket: ' . $error->getMessage());
}

fwrite(STDOUT, "[PASS] Teardown da esteira concluido\n");
exit(0);
