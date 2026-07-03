<?php

declare(strict_types=1);

// Smoke: exercita os controllers/rotas do widget via Request::create com uma
// sessao de tecnico semeada. Cobre Widget/Channels, Widget/Config,
// Widget/History (paginacao), Widget/Poll (poll_max_channels), Widget/MarkRead
// e Widget/Leave. NAO e destrutivo; limpa as linhas criadas via baseline de ids
// e restaura as configs alteradas no finally.

use GlpiPlugin\Glpichat\Controller\WidgetController;
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
 * IMPORTANTE: lanca excecao em vez de exit() para que o bloco `finally` do
 * script (restauracao de config + limpeza de linhas criadas) SEMPRE seja
 * executado, mesmo quando uma asserção falha. `exit()` termina o processo
 * imediatamente e pula qualquer `finally` pendente, o que deixava configs
 * (ex.: poll_max_channels, history_page_default/max) e linhas orfãs no banco
 * sempre que uma asserção falhava aqui - contaminando os demais smokes que
 * rodam na mesma esteira/ticket (causa raiz de falhas em cascata).
 *
 * @return never
 */
function routesFail(string $message): void
{
    throw new RuntimeException("[FAIL] {$message}");
}

function routesPass(string $message): void
{
    fwrite(STDOUT, "[PASS] {$message}\n");
}

function routesAssert(bool $condition, string $message): void
{
    if (!$condition) {
        routesFail($message);
    }
}

function routesJson(\Symfony\Component\HttpFoundation\Response $response): array
{
    $content = $response->getContent();
    routesAssert(is_string($content) && $content !== '', 'Resposta vazia do controller');
    return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
}

function routesMaxId(string $table): int
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

function routesSeedSession(): int
{
    global $DB;

    // Preferencial: usa o ator "glpi" (assign) do ticket dedicado da esteira,
    // ja resolvido e exportado por tests/e2e/setup/prepare_env.php via
    // GLPICHAT_GLPI_ID (ver tests/run_full_suite.sh). Isso garante um usuario
    // com direito de leitura REAL sobre o ticket de teste, evitando a antiga
    // query solta com LIMIT 1 sem ORDER BY: nao-deterministica entre motores
    // (MySQL vs MariaDB podiam devolver planos/linhas diferentes para o mesmo
    // WHERE, fazendo o "tecnico" as vezes cair no usuario post-only/Self-Service,
    // que nao tem READ sobre um ticket onde nao e o solicitante).
    $where = [
        'glpi_users.is_active'            => 1,
        'glpi_users.is_deleted'           => 0,
        'glpi_profiles_users.profiles_id' => ['>', 0],
    ];
    $glpi_env_id = (int) (getenv('GLPICHAT_GLPI_ID') ?: 0);
    if ($glpi_env_id > 0) {
        $where['glpi_users.id'] = $glpi_env_id;
    }

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
        'WHERE' => $where,
        // Fallback deterministico (sem GLPICHAT_GLPI_ID definido): prioriza o
        // usuario "glpi" pelo nome antes de qualquer outro, e depois o menor id.
        'ORDER' => [
            new \Glpi\DBAL\QueryExpression("(glpi_users.name = 'glpi') DESC"),
            'glpi_users.id ASC',
        ],
        'LIMIT' => 1,
    ])->current();

    routesAssert(is_array($row), 'Sessao de teste precisa de um usuario GLPI ativo');

    $users_id = (int) $row['users_id'];
    $_SESSION['valid_id'] = session_id();
    $_SESSION['glpiname'] = (string) $row['user_name'];
    $_SESSION['glpiID'] = $users_id;
    Session::initEntityProfiles($users_id);
    Session::changeProfile((int) $row['profiles_id']);
    $_SESSION['glpiactiveprofile']['plugin_glpichat_public'] = READ | CREATE;
    $_SESSION['glpiactiveprofile']['plugin_glpichat_internal'] = READ | CREATE;
    $_SESSION['glpiactive_entity'] = (int) ($row['entities_id'] ?? 0);
    $_SESSION['glpigroups'] = [];
    // Token CSRF semeado na sessao (os POST do widget exigem usuario logado).
    $_SESSION['glpicsrftokens'] = [];

    return $users_id;
}

global $DB;

$tickets_id = (int) (getenv('GLPICHAT_TICKET_ID') ?: 1);
$users_id = routesSeedSession();
$public_rooms_id = glpichat_room_id($tickets_id, GLPICHAT_ROOM_PUBLIC);
$internal_rooms_id = glpichat_room_id($tickets_id, GLPICHAT_ROOM_INTERNAL);

$baseline = [
    'glpi_plugin_glpichat_events'       => routesMaxId('glpi_plugin_glpichat_events'),
    'glpi_plugin_glpichat_auditlogs'    => routesMaxId('glpi_plugin_glpichat_auditlogs'),
    'glpi_plugin_glpichat_messages'     => routesMaxId('glpi_plugin_glpichat_messages'),
    'glpi_plugin_glpichat_participants' => routesMaxId('glpi_plugin_glpichat_participants'),
    'glpi_plugin_glpichat_readstates'   => routesMaxId('glpi_plugin_glpichat_readstates'),
];

$config_keys = ['poll_max_channels', 'history_page_default', 'history_page_max'];
$config_snapshot = [];
foreach ($config_keys as $key) {
    $config_snapshot[$key] = (string) Config::getConfigurationValue('plugin:glpichat', $key);
}

try {
try {
    $widget = new WidgetController();

    // -----------------------------------------------------------------
    // Widget/Channels (GET) -> 200 + _glpi_csrf_token + current_user_id
    // -----------------------------------------------------------------
    $channels_resp = $widget->channels(Request::create(
        '/plugins/glpichat/Widget/Channels',
        'GET',
        ['tickets_id' => $tickets_id]
    ));
    routesAssert($channels_resp->getStatusCode() === 200, 'Widget/Channels deve retornar 200');
    $channels_json = routesJson($channels_resp);
    routesAssert(isset($channels_json['_glpi_csrf_token']) && $channels_json['_glpi_csrf_token'] !== '', 'Widget/Channels deve retornar _glpi_csrf_token');
    routesAssert((int) ($channels_json['current_user_id'] ?? 0) === $users_id, 'Widget/Channels deve retornar current_user_id da sessao');
    routesAssert(array_key_exists('channels', $channels_json), 'Widget/Channels deve retornar a lista de canais');
    routesPass('Widget/Channels retorna 200 com token CSRF e current_user_id');

    // -----------------------------------------------------------------
    // Widget/Config (GET) -> 200 com as chaves de configuracao
    // -----------------------------------------------------------------
    $config_resp = $widget->config(Request::create('/plugins/glpichat/Widget/Config', 'GET'));
    routesAssert($config_resp->getStatusCode() === 200, 'Widget/Config deve retornar 200');
    $config_json = routesJson($config_resp);
    foreach (['poll_focused_ms', 'poll_idle_ms', 'poll_bg_ms', 'meta_sync_ms', 'invite_poll_ms', 'desktop_max_open', 'widget_default_position', 'typing_indicator_timeout_ms', 'auto_open_ticket_chat', 'auto_scroll_threshold_px', 'history_page_default', 'history_page_max', '_glpi_csrf_token'] as $expected_key) {
        routesAssert(array_key_exists($expected_key, $config_json), "Widget/Config deve expor a chave {$expected_key}");
    }
    routesPass('Widget/Config retorna 200 com as chaves de configuracao do frontend');

    // -----------------------------------------------------------------
    // Widget/History (GET) -> 200 + paginacao (limit default/cap)
    // -----------------------------------------------------------------
    for ($i = 0; $i < 6; $i++) {
        $DB->insert('glpi_plugin_glpichat_messages', [
            'rooms_id'         => $public_rooms_id,
            'tickets_id'       => $tickets_id,
            'participant_type' => GLPICHAT_ACTOR_USER,
            'users_id'         => $users_id,
            'participants_id'  => 0,
            'message_type'     => 'text',
            'content'          => 'routes history msg ' . $i,
            'date_creation'    => date('Y-m-d H:i:s'),
        ]);
    }
    Config::setConfigurationValues('plugin:glpichat', [
        'history_page_default' => '4',
        'history_page_max'     => '5',
    ]);

    $hist_default = $widget->history(Request::create(
        '/plugins/glpichat/Widget/History',
        'GET',
        ['rooms_id' => $public_rooms_id, 'before_id' => 0]
    ));
    routesAssert($hist_default->getStatusCode() === 200, 'Widget/History deve retornar 200');
    $hist_default_json = routesJson($hist_default);
    routesAssert(array_key_exists('messages', $hist_default_json) && array_key_exists('events', $hist_default_json), 'Widget/History deve retornar messages e events');
    routesAssert(count($hist_default_json['messages']) === 4, 'Widget/History sem limit deve usar o default (4)');

    $hist_cap = $widget->history(Request::create(
        '/plugins/glpichat/Widget/History',
        'GET',
        ['rooms_id' => $public_rooms_id, 'before_id' => 0, 'limit' => 100]
    ));
    $hist_cap_json = routesJson($hist_cap);
    routesAssert(count($hist_cap_json['messages']) === 5, 'Widget/History com limit alto deve respeitar o cap (5)');
    routesPass('Widget/History retorna 200 e respeita paginacao default/cap');

    // -----------------------------------------------------------------
    // Widget/Poll (POST) -> 200 e respeita poll_max_channels
    // -----------------------------------------------------------------
    Config::setConfigurationValues('plugin:glpichat', ['poll_max_channels' => '1']);
    $poll_resp = $widget->poll(Request::create(
        '/plugins/glpichat/Widget/Poll',
        'POST',
        [
            '_glpi_csrf_token' => Session::getNewCSRFToken(),
            'channels' => [
                ['tickets_id' => (string) $tickets_id, 'room_type' => 'public', 'after_id' => '0'],
                ['tickets_id' => (string) $tickets_id, 'room_type' => 'internal', 'after_id' => '0'],
            ],
        ]
    ));
    routesAssert($poll_resp->getStatusCode() === 200, 'Widget/Poll deve retornar 200');
    $poll_json = routesJson($poll_resp);
    routesAssert(array_key_exists('channels', $poll_json), 'Widget/Poll deve retornar channels');
    routesAssert(count($poll_json['channels']) === 1, 'Widget/Poll deve respeitar poll_max_channels=1');
    routesPass('Widget/Poll retorna 200 e respeita poll_max_channels');

    // -----------------------------------------------------------------
    // Widget/MarkRead (POST) -> atualiza readstate (last_read_messages_id)
    // -----------------------------------------------------------------
    $DB->insert('glpi_plugin_glpichat_messages', [
        'rooms_id'         => $public_rooms_id,
        'tickets_id'       => $tickets_id,
        'participant_type' => GLPICHAT_ACTOR_USER,
        'users_id'         => $users_id,
        'participants_id'  => 0,
        'message_type'     => 'text',
        'content'          => 'routes markread msg',
        'date_creation'    => date('Y-m-d H:i:s'),
    ]);
    $mark_message_id = (int) $DB->insertId();

    $markread_resp = $widget->markRead(Request::create(
        '/plugins/glpichat/Widget/MarkRead',
        'POST',
        [
            '_glpi_csrf_token'     => Session::getNewCSRFToken(),
            'rooms_id'             => $public_rooms_id,
            'last_read_message_id' => $mark_message_id,
        ]
    ));
    routesAssert($markread_resp->getStatusCode() === 200, 'Widget/MarkRead deve retornar 200');
    $markread_json = routesJson($markread_resp);
    routesAssert(($markread_json['ok'] ?? false) === true, 'Widget/MarkRead deve retornar ok=true');
    routesAssert((int) ($markread_json['last_read_message_id'] ?? 0) === $mark_message_id, 'Widget/MarkRead deve devolver o last_read_message_id persistido');

    $participants_id = glpichat_active_participant_id_for_user($public_rooms_id, $users_id);
    routesAssert($participants_id > 0, 'MarkRead deve ter criado o participante do usuario');
    $readstate = $DB->request([
        'FROM'  => 'glpi_plugin_glpichat_readstates',
        'WHERE' => ['rooms_id' => $public_rooms_id, 'participants_id' => $participants_id],
        'LIMIT' => 1,
    ])->current();
    routesAssert(is_array($readstate), 'MarkRead deve gravar uma linha de readstate');
    routesAssert((int) $readstate['last_read_messages_id'] === $mark_message_id, 'readstate.last_read_messages_id deve refletir a mensagem marcada');
    routesPass('Widget/MarkRead atualiza o readstate do participante');

    // -----------------------------------------------------------------
    // Widget/Leave (POST) -> marca left_at e gera evento user_left
    // -----------------------------------------------------------------
    $active_before_leave = glpichat_active_participant_id_for_user($public_rooms_id, $users_id);
    routesAssert($active_before_leave > 0, 'Deve haver um participante ativo antes do Leave');

    $leave_resp = $widget->leave(Request::create(
        '/plugins/glpichat/Widget/Leave',
        'POST',
        [
            '_glpi_csrf_token' => Session::getNewCSRFToken(),
            'rooms_id'         => $public_rooms_id,
        ]
    ));
    routesAssert($leave_resp->getStatusCode() === 200, 'Widget/Leave deve retornar 200');
    $leave_json = routesJson($leave_resp);
    routesAssert(($leave_json['ok'] ?? false) === true, 'Widget/Leave deve retornar ok=true');

    $left_participant = $DB->request([
        'FROM'  => 'glpi_plugin_glpichat_participants',
        'WHERE' => ['id' => $active_before_leave],
        'LIMIT' => 1,
    ])->current();
    routesAssert($left_participant['left_at'] !== null, 'Widget/Leave deve marcar left_at no participante');

    $left_event = $DB->request([
        'FROM'  => 'glpi_plugin_glpichat_events',
        'WHERE' => [
            'rooms_id'   => $public_rooms_id,
            'users_id'   => $users_id,
            'event_type' => 'user_left',
        ],
        'LIMIT' => 1,
    ])->current();
    routesAssert(is_array($left_event), 'Widget/Leave deve registrar o evento user_left');
    routesPass('Widget/Leave desconecta o participante e registra user_left');

    fwrite(STDOUT, "[PASS] Smoke de rotas do widget concluido\n");
} finally {
    if (isset($config_snapshot) && is_array($config_snapshot)) {
        Config::setConfigurationValues('plugin:glpichat', $config_snapshot);
    }

    foreach ($baseline as $table => $max_id) {
        $DB->delete($table, [['id' => ['>', $max_id]]]);
    }

    glpichat_clear_static_caches();

    unset($_SESSION['valid_id'], $_SESSION['glpiname'], $_SESSION['glpiID']);
    unset($_SESSION['glpiactiveprofile'], $_SESSION['glpiactive_entity'], $_SESSION['glpigroups']);
    unset($_SESSION['glpicsrftokens']);
}
} catch (\Throwable $e) {
    // O finally acima ja restaurou config e limpou o banco antes de chegarmos
    // aqui - agora reportamos a falha e encerramos com codigo de erro.
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
