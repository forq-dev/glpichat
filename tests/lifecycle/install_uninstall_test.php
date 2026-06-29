<?php

declare(strict_types=1);

// =============================================================================
// LIFECYCLE — ciclo de vida completo install/uninstall do plugin glpichat.
//
// Esta e a camada que roda por ULTIMO na esteira (depois de unit/smoke/e2e),
// validando o ciclo install -> idempotencia -> migracao de direitos -> uninstall
// (limpeza COMPLETA, incl. notificacoes sem orfaos) -> reinstalacao final.
//
// DESTRUTIVO: executa plugin_glpichat_uninstall() (DROP TABLE) e so deve rodar
// contra um BANCO DESCARTAVEL/DE TESTE.
//
// Guard: roda apenas com GLPICHAT_ALLOW_DESTRUCTIVE_TEST=1 (exportado pela fase
// lifecycle do tests/run_full_suite.sh). Sem o opt-in faz SKIP para evitar wipe
// acidental se o arquivo for executado isoladamente.
//
// RESTAURACAO GARANTIDA: como exit() NAO dispara blocos finally em PHP, a
// reinstalacao do schema + restauracao do snapshot de configs e registrada via
// register_shutdown_function(), de forma que MESMO EM FALHA (assert -> exit 1) o
// ambiente seja restaurado e nao quebre execucoes subsequentes.
// =============================================================================

use GlpiPlugin\Glpichat\EventCleanupCron;

$loader = require dirname(__DIR__, 4) . '/vendor/autoload.php';
$loader->addPsr4('GlpiPlugin\\Glpichat\\', dirname(__DIR__, 2) . '/src/');

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

if (!function_exists('plugin_init_glpichat')) {
    require dirname(__DIR__, 2) . '/setup.php';
}
if (!function_exists('plugin_glpichat_install')) {
    require dirname(__DIR__, 2) . '/hook.php';
}

Session::start();

// -----------------------------------------------------------------------------
// Helpers de asserção.
// -----------------------------------------------------------------------------

/**
 * @return never
 */
function lcFail(string $message): void
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function lcPass(string $message): void
{
    fwrite(STDOUT, "[PASS] {$message}\n");
}

function lcAssert(bool $condition, string $message): void
{
    if (!$condition) {
        lcFail($message);
    }
}

function lcCount(array $criteria, string $table): int
{
    global $DB;
    return (int) $DB->request(['COUNT' => 'cpt', 'FROM' => $table, 'WHERE' => $criteria])->current()['cpt'];
}

function lcRequestTypeCount(): int
{
    return lcCount(['name' => 'GLPI Chat'], RequestType::getTable());
}

function lcCrontaskCount(string $name): int
{
    return lcCount(['itemtype' => EventCleanupCron::class, 'name' => $name], 'glpi_crontasks');
}

/** @return list<int> */
function lcTemplateIds(): array
{
    global $DB;
    $ids = [];
    foreach ($DB->request([
        'SELECT' => ['id'],
        'FROM'   => NotificationTemplate::getTable(),
        'WHERE'  => [
            'itemtype' => Ticket::class,
            'name'     => [
                glpichat_default_notification_template_name(),
                glpichat_event_notification_template_name(),
            ],
        ],
    ]) as $row) {
        $ids[] = (int) $row['id'];
    }
    return $ids;
}

/** @return list<int> */
function lcDefinitionIds(): array
{
    global $DB;
    $ids = [];
    foreach ($DB->request([
        'SELECT' => ['id'],
        'FROM'   => Notification::getTable(),
        'WHERE'  => [
            'itemtype' => Ticket::class,
            'name'     => array_values(array_map(
                'glpichat_default_notification_definition_name',
                glpichat_default_notification_events()
            )),
        ],
    ]) as $row) {
        $ids[] = (int) $row['id'];
    }
    return $ids;
}

/** Definicoes remanescentes detectadas pela coluna `event` (glpichat_*). */
function lcDefinitionsByEvent(): int
{
    global $DB;
    return $DB->request([
        'FROM'  => Notification::getTable(),
        'WHERE' => ['event' => glpichat_default_notification_events()],
    ])->count();
}

/** Templates remanescentes detectados por prefixo de nome 'GLPI CHAT - %'. */
function lcTemplatesByName(): int
{
    global $DB;
    return $DB->request([
        'FROM'  => NotificationTemplate::getTable(),
        'WHERE' => ['name' => ['LIKE', 'GLPI CHAT - %']],
    ])->count();
}

function lcTranslationsFor(array $template_ids): int
{
    global $DB;
    if ($template_ids === []) {
        return 0;
    }
    return $DB->request([
        'FROM'  => NotificationTemplateTranslation::getTable(),
        'WHERE' => ['notificationtemplates_id' => $template_ids],
    ])->count();
}

function lcTargetsFor(array $definition_ids): int
{
    global $DB;
    if ($definition_ids === []) {
        return 0;
    }
    return $DB->request([
        'FROM'  => NotificationTarget::getTable(),
        'WHERE' => ['notifications_id' => $definition_ids],
    ])->count();
}

function lcProfileRightCount(): int
{
    return lcCount(
        ['name' => array_merge(glpichat_right_names(), glpichat_legacy_right_names())],
        ProfileRight::getTable()
    );
}

/**
 * Detecta duplicacao de linhas de profilerights por (profiles_id, name).
 * Retorna o maior numero de linhas encontrado para um mesmo par.
 */
function lcMaxProfileRightDuplication(): int
{
    global $DB;
    $seen = [];
    foreach ($DB->request([
        'SELECT' => ['profiles_id', 'name'],
        'FROM'   => ProfileRight::getTable(),
        'WHERE'  => ['name' => glpichat_right_names()],
    ]) as $row) {
        $key = (int) $row['profiles_id'] . '|' . (string) $row['name'];
        $seen[$key] = ($seen[$key] ?? 0) + 1;
    }
    return $seen === [] ? 0 : max($seen);
}

// -----------------------------------------------------------------------------
// GUARD DESTRUTIVO.
// -----------------------------------------------------------------------------
if (getenv('GLPICHAT_ALLOW_DESTRUCTIVE_TEST') !== '1') {
    fwrite(STDOUT, "[SKIP] Teste destrutivo (DROP TABLE). Defina GLPICHAT_ALLOW_DESTRUCTIVE_TEST=1 e use um banco descartavel para executar.\n");
    exit(0);
}

global $DB;

// Snapshot do estado REAL das configs do plugin ANTES de qualquer alteracao.
// O uninstall apaga todas as configs e o reinstall recria os DEFAULTS; por isso
// reaplicamos este snapshot ao final para nao degradar o ambiente compartilhado
// (ex.: external_followup_user_id, necessario ao fluxo de convidado).
$config_backup = [];
foreach ($DB->request([
    'SELECT' => ['name', 'value'],
    'FROM'   => 'glpi_configs',
    'WHERE'  => ['context' => 'plugin:glpichat'],
]) as $row) {
    $config_backup[(string) $row['name']] = (string) $row['value'];
}
$external_followup_backup = $config_backup['external_followup_user_id'] ?? null;

// RESTAURACAO GARANTIDA (roda mesmo apos exit() de um assert que falhou).
register_shutdown_function(static function () use (&$config_backup): void {
    global $DB;
    try {
        $DB->clearSchemaCache();
        plugin_glpichat_install();
        if ($config_backup !== []) {
            Config::setConfigurationValues('plugin:glpichat', $config_backup);
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, '[WARN] Falha ao reinstalar/restaurar o plugin no shutdown: ' . $e->getMessage() . "\n");
    }
});

// =============================================================================
// A) INSTALL — estado base instalado e limpo, com todos os objetos esperados.
// =============================================================================
$DB->clearSchemaCache();
plugin_glpichat_install();

// A.1 — Todas as tabelas do plugin existem.
foreach (glpichat_table_names() as $table) {
    lcAssert($DB->tableExists($table), "Install deve criar a tabela {$table}");
}
lcPass('A.1 Install cria todas as tabelas do plugin');

// A.2 — Indices-chave existem.
lcAssert(isIndex('glpi_plugin_glpichat_messages', 'messages_room_id'), 'Indice messages_room_id deve existir');
lcAssert(isIndex('glpi_plugin_glpichat_invites', 'invites_expires_at'), 'Indice invites_expires_at deve existir');
lcAssert(isIndex('glpi_plugin_glpichat_participants', 'idx_rooms_typing'), 'Indice idx_rooms_typing deve existir');
lcPass('A.2 Install cria os indices-chave (messages_room_id, invites_expires_at, idx_rooms_typing)');

// A.3 — ProfileRights criados para todos os direitos atuais.
foreach (glpichat_right_names() as $right) {
    lcAssert(
        lcCount(['name' => $right], ProfileRight::getTable()) > 0,
        "Install deve criar ProfileRight para o direito {$right}"
    );
}
lcPass('A.3 Install cria ProfileRights para todos os direitos do plugin');

// A.4 — Configs do plugin presentes.
lcAssert(
    (string) Config::getConfigurationValue('plugin:glpichat', 'invite_ttl_seconds') !== '',
    'Install deve gravar a config invite_ttl_seconds'
);
lcAssert(
    (string) Config::getConfigurationValue('plugin:glpichat', 'rights_model_version') === GLPICHAT_RIGHTS_MODEL_VERSION,
    'Install deve gravar rights_model_version == GLPICHAT_RIGHTS_MODEL_VERSION'
);
lcPass('A.4 Install grava as configs do plugin (invite_ttl_seconds, rights_model_version)');

// A.5 — RequestType "GLPI Chat".
lcAssert(lcRequestTypeCount() === 1, 'Install deve criar o RequestType "GLPI Chat"');
lcPass('A.5 Install cria o RequestType "GLPI Chat"');

// A.6 — Crontasks registradas.
lcAssert(lcCrontaskCount('CleanEvents') === 1, 'Install deve registrar a crontask CleanEvents');
lcAssert(lcCrontaskCount('CleanMessages') === 1, 'Install deve registrar a crontask CleanMessages');
lcAssert(lcCrontaskCount('CleanAuditlogs') === 1, 'Install deve registrar a crontask CleanAuditlogs');
lcPass('A.6 Install registra as crontasks CleanEvents/CleanMessages/CleanAuditlogs');

// A.7 — Notificacoes: templates, definicoes, translations e targets.
$tpl_ids = lcTemplateIds();
$def_ids = lcDefinitionIds();
lcAssert(count($tpl_ids) === 2, 'Install deve criar 2 templates "GLPI CHAT - ..."');
lcAssert(count($def_ids) > 0, 'Install deve criar definicoes de notificacao GLPI CHAT');
lcAssert(lcTranslationsFor($tpl_ids) > 0, 'Install deve criar translations dos templates GLPI CHAT');
lcAssert(lcTargetsFor($def_ids) > 0, 'Install deve criar targets das notificacoes GLPI CHAT');
lcPass('A.7 Install semeia templates/definicoes/translations/targets de notificacao');

// =============================================================================
// B) IDEMPOTENCIA — rodar install de novo nao duplica objetos.
// =============================================================================
$before = [
    'templates'   => count(lcTemplateIds()),
    'definitions' => count(lcDefinitionIds()),
    'requesttype' => lcRequestTypeCount(),
    'cron_events' => lcCrontaskCount('CleanEvents'),
    'cron_msgs'   => lcCrontaskCount('CleanMessages'),
    'cron_audit'  => lcCrontaskCount('CleanAuditlogs'),
];

plugin_glpichat_install(); // segunda execucao

$after = [
    'templates'   => count(lcTemplateIds()),
    'definitions' => count(lcDefinitionIds()),
    'requesttype' => lcRequestTypeCount(),
    'cron_events' => lcCrontaskCount('CleanEvents'),
    'cron_msgs'   => lcCrontaskCount('CleanMessages'),
    'cron_audit'  => lcCrontaskCount('CleanAuditlogs'),
];

lcAssert($after['templates'] === 2, 'Apos 2x install devem existir exatamente 2 templates de notificacao');
lcAssert($before === $after, 'install repetido nao deve duplicar templates/definitions/RequestType/crontasks');
lcAssert(
    lcMaxProfileRightDuplication() <= 1,
    'install repetido nao deve duplicar linhas de profilerights por (profile, right)'
);
lcPass('B Idempotencia: install repetido nao duplica objetos nem profilerights');

// =============================================================================
// C) MIGRACAO rights_model_version — re-semeia uma vez e depois e no-op.
// =============================================================================
Config::setConfigurationValues('plugin:glpichat', ['rights_model_version' => '0']);
lcAssert(
    glpichat_ensure_profile_rights_model() === true,
    'Com versao antiga (0), glpichat_ensure_profile_rights_model deve re-semear e retornar true'
);
lcAssert(
    (string) Config::getConfigurationValue('plugin:glpichat', 'rights_model_version') === GLPICHAT_RIGHTS_MODEL_VERSION,
    'Migracao deve regravar rights_model_version == GLPICHAT_RIGHTS_MODEL_VERSION'
);
lcAssert(
    lcMaxProfileRightDuplication() <= 1,
    'Re-semear o modelo de direitos nao deve duplicar profilerights'
);
lcAssert(
    glpichat_ensure_profile_rights_model() === false,
    'Com a versao atual ja gravada, a migracao deve ser no-op (retornar false)'
);
lcPass('C Migracao rights_model_version re-semeia uma vez e depois e no-op');

// Captura de ids de notificacao ANTES do uninstall, para validar orfaos depois.
$pre_uninstall_tpl_ids = lcTemplateIds();
$pre_uninstall_def_ids = lcDefinitionIds();
lcAssert($DB->tableExists('glpi_plugin_glpichat_rooms'), 'Tabela de rooms deve existir antes do uninstall');
lcAssert(lcProfileRightCount() > 0, 'ProfileRights do plugin devem existir antes do uninstall');

// =============================================================================
// D) UNINSTALL — limpeza COMPLETA.
// =============================================================================
plugin_glpichat_uninstall();
$DB->clearSchemaCache(); // DROP TABLE invalidou o cache de schema deste processo.

// D.1 — Tabelas removidas.
foreach (glpichat_table_names() as $table) {
    lcAssert(!$DB->tableExists($table), "Uninstall deve remover a tabela {$table}");
}
lcPass('D.1 Uninstall remove todas as tabelas do plugin');

// D.2 — ProfileRights (atuais + legados) removidos.
lcAssert(lcProfileRightCount() === 0, 'Uninstall deve remover os ProfileRights (atuais + legados)');
lcPass('D.2 Uninstall remove ProfileRights atuais e legados');

// D.3 — Configs do plugin removidas.
lcAssert(
    (string) Config::getConfigurationValue('plugin:glpichat', 'rights_model_version') === '',
    'Uninstall deve remover as configs do plugin (rights_model_version)'
);
lcAssert(
    (string) Config::getConfigurationValue('plugin:glpichat', 'invite_ttl_seconds') === '',
    'Uninstall deve remover as configs do plugin (invite_ttl_seconds)'
);
lcPass('D.3 Uninstall remove as configs do plugin');

// D.4 — RequestType "GLPI Chat" removido.
lcAssert(lcRequestTypeCount() === 0, 'Uninstall deve remover o RequestType "GLPI Chat"');
lcPass('D.4 Uninstall remove o RequestType "GLPI Chat"');

// D.5 — Crontasks removidas.
lcAssert(lcCrontaskCount('CleanEvents') === 0, 'Uninstall deve remover a crontask CleanEvents');
lcAssert(lcCrontaskCount('CleanMessages') === 0, 'Uninstall deve remover a crontask CleanMessages');
lcAssert(lcCrontaskCount('CleanAuditlogs') === 0, 'Uninstall deve remover a crontask CleanAuditlogs');
lcPass('D.5 Uninstall remove as crontasks do plugin');

// D.6 — Notificacoes: nenhum template/definicao/translation/target remanescente
//       NEM orfaos (valida o fix glpichat_uninstall_default_notifications).
lcAssert(lcTemplatesByName() === 0, 'Uninstall NAO deve deixar templates "GLPI CHAT - %"');
lcAssert(lcDefinitionsByEvent() === 0, 'Uninstall NAO deve deixar definicoes com event glpichat_*');
lcAssert(
    lcTranslationsFor($pre_uninstall_tpl_ids) === 0,
    'Uninstall NAO deve deixar translations orfas dos templates GLPI CHAT'
);
lcAssert(
    lcTargetsFor($pre_uninstall_def_ids) === 0,
    'Uninstall NAO deve deixar targets orfaos das notificacoes GLPI CHAT'
);
lcPass('D.6 Uninstall remove notificacoes sem deixar translations/targets orfaos');

// =============================================================================
// E) TEARDOWN — reinstala o schema e restaura o snapshot de configs original.
//    (tambem garantido via register_shutdown_function, caso algo acima falhe).
// =============================================================================
$DB->clearSchemaCache();
plugin_glpichat_install();
if ($config_backup !== []) {
    Config::setConfigurationValues('plugin:glpichat', $config_backup);
}
lcAssert($DB->tableExists('glpi_plugin_glpichat_rooms'), 'Reinstalacao final deve recriar as tabelas do plugin');
lcAssert(
    ($external_followup_backup === null)
        || ((string) Config::getConfigurationValue('plugin:glpichat', 'external_followup_user_id') === $external_followup_backup),
    'Teardown deve restaurar external_followup_user_id ao valor original capturado'
);
lcPass('E Teardown reinstala o schema e restaura as configs originais');

fwrite(STDOUT, "[PASS] Lifecycle install/uninstall concluido\n");
