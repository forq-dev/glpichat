<?php

declare(strict_types=1);

// Smoke (redline de seguranca): validacao de upload (convidado + autenticado) e
// evento attachment_uploaded do hook de anexo de ticket.
//
// Cobre:
//   - glpichat_validate_upload_input rejeita file ausente/invalido (PT real);
//   - UploadController autenticado: parametros invalidos -> 400; file ausente
//     com parametros validos -> 400 (mensagem de upload PT real);
//   - HookHandler::itemAdd para Document_Item de Ticket gera o evento
//     attachment_uploaded no canal publico + mensagem com documents_id.
//
// NAO e destrutivo: cria UM glpi_documents minimo (removido no finally) e usa
// baseline de ids para limpar messages/events. Sessao semeada e desfeita.

use GlpiPlugin\Glpichat\Controller\UploadController;
use GlpiPlugin\Glpichat\HookHandler;
use Symfony\Component\HttpFoundation\Request;

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
function upFail(string $message): void
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function upPass(string $message): void
{
    fwrite(STDOUT, "[PASS] {$message}\n");
}

function upAssert(bool $condition, string $message): void
{
    if (!$condition) {
        upFail($message);
    }
}

function upJson(\Symfony\Component\HttpFoundation\Response $response): array
{
    $content = $response->getContent();
    upAssert(is_string($content) && $content !== '', 'Resposta vazia do controller');
    return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
}

function upMaxId(string $table): int
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

function upSeedSession(): int
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
            'glpi_users.name'                 => 'glpi',
            'glpi_users.is_active'            => 1,
            'glpi_users.is_deleted'           => 0,
            'glpi_profiles_users.profiles_id' => ['>', 0],
        ],
        'LIMIT' => 1,
    ])->current();

    upAssert(is_array($row), "Sessao de teste precisa do usuario 'glpi'");

    $users_id = (int) $row['users_id'];
    $_SESSION['valid_id'] = session_id();
    $_SESSION['glpiname'] = (string) $row['user_name'];
    $_SESSION['glpiID'] = $users_id;
    $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
    Session::initEntityProfiles($users_id);
    Session::changeProfile((int) $row['profiles_id']);
    $_SESSION['glpiactiveprofile']['plugin_glpichat_public'] = READ | CREATE;
    $_SESSION['glpiactiveprofile']['plugin_glpichat_internal'] = READ | CREATE;
    $_SESSION['glpiactive_entity'] = (int) ($row['entities_id'] ?? 0);
    $_SESSION['glpigroups'] = [];
    $_SESSION['glpicsrftokens'] = [];

    return $users_id;
}

global $DB;

$tickets_id = (int) (getenv('GLPICHAT_TICKET_ID') ?: 1);
$users_id = upSeedSession();
$public_rooms_id = glpichat_room_id($tickets_id, GLPICHAT_ROOM_PUBLIC);

$baseline = [
    'glpi_plugin_glpichat_messages' => upMaxId('glpi_plugin_glpichat_messages'),
    'glpi_plugin_glpichat_events'   => upMaxId('glpi_plugin_glpichat_events'),
];

// Documento minimo para o teste do hook de anexo.
$DB->insert('glpi_documents', [
    'name'          => 'anexo-smoke.txt',
    'filename'      => 'anexo-smoke.txt',
    'entities_id'   => (int) $_SESSION['glpiactive_entity'],
    'users_id'      => $users_id,
    'date_creation' => date('Y-m-d H:i:s'),
]);
$documents_id = (int) $DB->insertId();

try {
    // -----------------------------------------------------------------
    // 1) glpichat_validate_upload_input rejeita file ausente/invalido.
    // -----------------------------------------------------------------
    $threw = false;
    try {
        glpichat_validate_upload_input($tickets_id, GLPICHAT_ROOM_PUBLIC, null);
    } catch (RuntimeException $e) {
        $threw = true;
        upAssert(
            str_contains($e->getMessage(), 'Parâmetros de upload inválidos.'),
            'validate_upload_input deve usar a mensagem PT real - veio: ' . $e->getMessage()
        );
    }
    upAssert($threw, 'validate_upload_input deve rejeitar file ausente');
    upPass('Validacao de upload rejeita file ausente (mensagem PT real)');

    // -----------------------------------------------------------------
    // 2) UploadController autenticado: parametros invalidos -> 400.
    // -----------------------------------------------------------------
    $controller = new UploadController();
    $resp = $controller->__invoke(Request::create(
        '/plugins/glpichat/Upload',
        'POST',
        ['_glpi_csrf_token' => Session::getNewCSRFToken()] // sem tickets_id/room_type
    ));
    upAssert($resp->getStatusCode() === 400, 'Upload autenticado com parametros invalidos deve retornar 400');
    $json = upJson($resp);
    upAssert(
        isset($json['error']) && str_contains((string) $json['error'], 'Parâmetros inválidos.'),
        'Upload com parametros invalidos deve usar a mensagem PT real - veio: ' . ($json['error'] ?? '(sem error)')
    );
    upPass('Upload autenticado bloqueia parametros invalidos (mensagem PT real)');

    // -----------------------------------------------------------------
    // 3) UploadController autenticado: parametros validos mas file ausente
    //    -> 400 com a mensagem de upload PT real (use-case valida o arquivo).
    // -----------------------------------------------------------------
    $resp = $controller->__invoke(Request::create(
        '/plugins/glpichat/Upload',
        'POST',
        [
            '_glpi_csrf_token' => Session::getNewCSRFToken(),
            'tickets_id'       => $tickets_id,
            'room_type'        => 'public',
        ]
    ));
    upAssert($resp->getStatusCode() === 400, 'Upload sem file deve retornar 400');
    $json = upJson($resp);
    upAssert(
        isset($json['error']) && str_contains((string) $json['error'], 'Parâmetros de upload inválidos.'),
        'Upload sem file deve usar a mensagem de upload PT real - veio: ' . ($json['error'] ?? '(sem error)')
    );
    upPass('Upload autenticado bloqueia arquivo ausente (mensagem PT real)');

    // -----------------------------------------------------------------
    // 4) HookHandler::itemAdd para Document_Item de Ticket gera o evento
    //    attachment_uploaded no canal publico + mensagem com documents_id.
    //    (Caminho de anexo nativo do ticket; o upload do chat suprime o hook.)
    // -----------------------------------------------------------------
    unset($GLOBALS['GLPICHAT_SUPPRESS_DOCUMENT_HOOK']);

    $document_item = new \Document_Item();
    $document_item->fields = [
        'itemtype'     => \Ticket::class,
        'items_id'     => $tickets_id,
        'documents_id' => $documents_id,
    ];
    HookHandler::itemAdd($document_item);

    $event = $DB->request([
        'FROM'  => 'glpi_plugin_glpichat_events',
        'WHERE' => [
            'rooms_id'   => $public_rooms_id,
            'tickets_id' => $tickets_id,
            'event_type' => 'attachment_uploaded',
            ['id'        => ['>', $baseline['glpi_plugin_glpichat_events']]],
        ],
        'ORDER' => ['id DESC'],
        'LIMIT' => 1,
    ])->current();
    upAssert(is_array($event), 'itemAdd deve registrar o evento attachment_uploaded no canal publico');
    $payload = json_decode((string) ($event['payload_json'] ?? '{}'), true);
    upAssert((int) ($payload['documents_id'] ?? 0) === $documents_id, 'O evento attachment_uploaded deve referenciar o documents_id anexado');

    $message = $DB->request([
        'FROM'  => 'glpi_plugin_glpichat_messages',
        'WHERE' => [
            'rooms_id'     => $public_rooms_id,
            'documents_id' => $documents_id,
            ['id'          => ['>', $baseline['glpi_plugin_glpichat_messages']]],
        ],
        'LIMIT' => 1,
    ])->current();
    upAssert(is_array($message), 'itemAdd deve criar a mensagem publica do anexo com o documents_id');
    upPass('itemAdd (anexo de ticket) gera attachment_uploaded + mensagem publica com documents_id');

    fwrite(STDOUT, "[PASS] Smoke de upload/attachment concluido\n");
} finally {
    foreach ($baseline as $table => $max_id) {
        $DB->delete($table, [['id' => ['>', $max_id]]]);
    }
    $DB->delete('glpi_documents', ['id' => $documents_id]);

    glpichat_clear_static_caches();

    unset($_SESSION['valid_id'], $_SESSION['glpiname'], $_SESSION['glpiID'], $_SESSION['glpi_currenttime']);
    unset($_SESSION['glpiactiveprofile'], $_SESSION['glpiactive_entity'], $_SESSION['glpigroups']);
    unset($_SESSION['glpicsrftokens']);
}
