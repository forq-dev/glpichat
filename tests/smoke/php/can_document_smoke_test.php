<?php

declare(strict_types=1);

// Smoke (redline de seguranca): HookHandler::canDocument
//
// Garante que o download de um documento anexado ao CANAL INTERNO so e
// permitido para quem possui plugin_glpichat_internal READ. Documentos do
// canal publico e documentos NAO vinculados ao chat ficam intactos. Verificacoes
// que nao sejam READ (UPDATE/DELETE/PURGE) nao sao interferidas.
//
// NAO e destrutivo: insere mensagens sinteticas vinculando documents_id e limpa
// via baseline de ids no finally. Nao cria documentos reais (canDocument so
// consulta a tabela de mensagens; o objeto Document e montado em memoria).

use GlpiPlugin\Glpichat\HookHandler;

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

/**
 * @return never
 */
function docFail(string $message): void
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function docPass(string $message): void
{
    fwrite(STDOUT, "[PASS] {$message}\n");
}

function docAssert(bool $condition, string $message): void
{
    if (!$condition) {
        docFail($message);
    }
}

function docMaxId(string $table): int
{
    global $DB;
    $row = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => $table,
        'ORDER'  => ['id DESC'],
        'LIMIT'  => 1,
    ])->current();
    return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
}

function docSeedSession(): int
{
    global $DB;

    $row = $DB->request([
        'SELECT' => [
            'glpi_users.id AS users_id',
            'glpi_users.name AS user_name',
            'glpi_profiles_users.profiles_id',
            'glpi_profiles_users.entities_id',
        ],
        'FROM' => 'glpi_users',
        'LEFT JOIN' => [
            'glpi_profiles_users' => [
                'FKEY' => [
                    'glpi_profiles_users' => 'users_id',
                    'glpi_users'          => 'id',
                ],
            ],
        ],
        'WHERE' => [
            'glpi_users.is_active'            => 1,
            'glpi_users.is_deleted'           => 0,
            'glpi_profiles_users.profiles_id' => ['>', 0],
        ],
        'LIMIT' => 1,
    ])->current();

    docAssert(is_array($row), 'Sessao de teste precisa de um usuario GLPI ativo');

    $users_id = (int) $row['users_id'];
    $_SESSION['valid_id'] = session_id();
    $_SESSION['glpiname'] = (string) $row['user_name'];
    $_SESSION['glpiID'] = $users_id;
    Session::initEntityProfiles($users_id);
    Session::changeProfile((int) $row['profiles_id']);
    $_SESSION['glpiactive_entity'] = (int) ($row['entities_id'] ?? 0);
    $_SESSION['glpigroups'] = [];

    return $users_id;
}

/**
 * Monta um objeto Document em memoria com o id e o right informados.
 * canDocument so usa $item->fields['id'] e $item->right.
 */
function docMakeDocument(int $documents_id, int $right): \Document
{
    $document = new \Document();
    $document->fields['id'] = $documents_id;
    $document->right = $right;
    return $document;
}

global $DB;

$tickets_id = (int) (getenv('GLPICHAT_TICKET_ID') ?: 1);
docSeedSession();

$internal_rooms_id = glpichat_room_id($tickets_id, GLPICHAT_ROOM_INTERNAL);
$public_rooms_id = glpichat_room_id($tickets_id, GLPICHAT_ROOM_PUBLIC);

// documents_id sinteticos (nao precisam existir em glpi_documents: canDocument
// apenas consulta a tabela de mensagens por documents_id + room_type).
$doc_internal = 990000001;
$doc_public   = 990000002;
$doc_unlinked = 990000003;

$baseline_messages = docMaxId('glpi_plugin_glpichat_messages');

$DB->insert('glpi_plugin_glpichat_messages', [
    'rooms_id'         => $internal_rooms_id,
    'tickets_id'       => $tickets_id,
    'participant_type' => GLPICHAT_ACTOR_USER,
    'users_id'         => $_SESSION['glpiID'],
    'participants_id'  => 0,
    'message_type'     => 'attachment',
    'content'          => 'anexo interno (canDocument smoke)',
    'documents_id'     => $doc_internal,
    'date_creation'    => date('Y-m-d H:i:s'),
]);
$DB->insert('glpi_plugin_glpichat_messages', [
    'rooms_id'         => $public_rooms_id,
    'tickets_id'       => $tickets_id,
    'participant_type' => GLPICHAT_ACTOR_USER,
    'users_id'         => $_SESSION['glpiID'],
    'participants_id'  => 0,
    'message_type'     => 'attachment',
    'content'          => 'anexo publico (canDocument smoke)',
    'documents_id'     => $doc_public,
    'date_creation'    => date('Y-m-d H:i:s'),
]);

try {
    // -----------------------------------------------------------------
    // 1) Documento do canal INTERNO + sessao SEM plugin_glpichat_internal READ
    //    -> bloqueado (right vira null).
    // -----------------------------------------------------------------
    $_SESSION['glpiactiveprofile']['plugin_glpichat_internal'] = 0;
    $doc = docMakeDocument($doc_internal, READ);
    HookHandler::canDocument($doc);
    docAssert($doc->right === null, 'Documento do canal interno SEM direito interno deve ser bloqueado (right=null)');
    docPass('canDocument bloqueia download de documento do canal interno sem plugin_glpichat_internal READ');

    // -----------------------------------------------------------------
    // 2) Documento do canal INTERNO + sessao COM plugin_glpichat_internal READ
    //    -> permitido (right inalterado).
    // -----------------------------------------------------------------
    $_SESSION['glpiactiveprofile']['plugin_glpichat_internal'] = READ;
    $doc = docMakeDocument($doc_internal, READ);
    HookHandler::canDocument($doc);
    docAssert($doc->right === READ, 'Documento do canal interno COM direito interno deve permanecer acessivel (right inalterado)');
    docPass('canDocument permite download de documento interno quando ha plugin_glpichat_internal READ');

    // -----------------------------------------------------------------
    // 3) Documento do canal PUBLICO + sessao SEM direito interno -> intacto.
    // -----------------------------------------------------------------
    $_SESSION['glpiactiveprofile']['plugin_glpichat_internal'] = 0;
    $doc = docMakeDocument($doc_public, READ);
    HookHandler::canDocument($doc);
    docAssert($doc->right === READ, 'Documento do canal publico nao deve ser restringido pelo direito interno');
    docPass('canDocument nao restringe documentos do canal publico');

    // -----------------------------------------------------------------
    // 4) Documento NAO vinculado a nenhuma mensagem do chat -> intacto.
    // -----------------------------------------------------------------
    $doc = docMakeDocument($doc_unlinked, READ);
    HookHandler::canDocument($doc);
    docAssert($doc->right === READ, 'Documento nao vinculado ao chat nao deve ser restringido');
    docPass('canDocument nao restringe documentos nao vinculados ao chat');

    // -----------------------------------------------------------------
    // 5) Verificacao que NAO e READ (ex.: UPDATE) nao deve ser interferida,
    //    mesmo para documento interno sem direito.
    // -----------------------------------------------------------------
    $_SESSION['glpiactiveprofile']['plugin_glpichat_internal'] = 0;
    $doc = docMakeDocument($doc_internal, UPDATE);
    HookHandler::canDocument($doc);
    docAssert($doc->right === UPDATE, 'canDocument nao deve interferir em verificacoes != READ (UPDATE preservado)');
    docPass('canDocument nao interfere em checagens de direito diferentes de READ');

    fwrite(STDOUT, "[PASS] Smoke de canDocument concluido\n");
} finally {
    $DB->delete('glpi_plugin_glpichat_messages', [['id' => ['>', $baseline_messages]]]);
    glpichat_clear_static_caches();
    unset($_SESSION['valid_id'], $_SESSION['glpiname'], $_SESSION['glpiID']);
    unset($_SESSION['glpiactiveprofile'], $_SESSION['glpiactive_entity'], $_SESSION['glpigroups']);
}
