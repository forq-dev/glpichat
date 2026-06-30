<?php

declare(strict_types=1);

use GlpiPlugin\Glpichat\Controller\WidgetController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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

function taskPass(string $message): void
{
    fwrite(STDOUT, "[PASS] {$message}\n");
}

function taskAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

global $DB;

/**
 * @return array<int, array<string, mixed>>
 */
function userPresenceHistoryEvents(WidgetController $widget_controller, int $rooms_id): array
{
    $request = Request::create(
        '/plugins/glpichat/Widget/History',
        'GET',
        [
            'rooms_id' => $rooms_id,
            'before_id' => 0,
            'limit' => 30,
        ]
    );

    $response = $widget_controller->history($request);
    taskAssert($response->getStatusCode() === 200, 'Widget history deve retornar 200 OK');

    $payload = json_decode($response->getContent(), true);
    taskAssert(is_array($payload), 'Widget history deve retornar JSON valido');

    $events = $payload['events'] ?? null;
    taskAssert(is_array($events), 'Widget history deve retornar a lista de eventos');

    return $events;
}

/**
 * @param array<int, array<string, mixed>> $events
 */
function userPresenceFindEvent(array $events, string $event_type, int $users_id): ?array
{
    foreach ($events as $event) {
        if (
            is_array($event)
            && (string) ($event['event_type'] ?? '') === $event_type
            && (int) ($event['users_id'] ?? 0) === $users_id
        ) {
            return $event;
        }
    }

    return null;
}

function userPresenceSeedValidSession(): int
{
    global $DB;

    $row = $DB->request([
        'SELECT' => [
            'glpi_users.id AS users_id',
            'glpi_users.name AS user_name',
            'glpi_profiles_users.profiles_id',
            'glpi_profiles_users.entities_id',
            'glpi_profiles.last_rights_update',
        ],
        'FROM' => 'glpi_users',
        'LEFT JOIN' => [
            'glpi_profiles_users' => [
                'FKEY' => [
                    'glpi_profiles_users' => 'users_id',
                    'glpi_users'          => 'id',
                ],
            ],
            'glpi_profiles' => [
                'FKEY' => [
                    'glpi_profiles_users' => 'profiles_id',
                    'glpi_profiles'       => 'id',
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

    taskAssert(is_array($row), 'Sessao de teste precisa de um usuario GLPI ativo');

    $_SESSION['valid_id'] = session_id();
    $_SESSION['glpiname'] = (string) $row['user_name'];
    $_SESSION['glpiID'] = (int) $row['users_id'];
    // Inicializa os perfis e muda para o perfil correto para que os direitos
    // do plugin sejam carregados — necessario em instalacoes limpas onde
    // glpiactiveprofile nao esta pre-carregado.
    Session::initEntityProfiles((int) $row['users_id']);
    Session::changeProfile((int) $row['profiles_id']);
    // Garante que o direito do canal publico esta disponivel na sessao.
    if (!isset($_SESSION['glpiactiveprofile']['plugin_glpichat_public'])
        || $_SESSION['glpiactiveprofile']['plugin_glpichat_public'] === 0) {
        $_SESSION['glpiactiveprofile']['plugin_glpichat_public'] = 1 | 4; // READ | CREATE
    }
    $_SESSION['glpiactive_entity'] = (int) ($row['entities_id'] ?? 0);
    $_SESSION['glpigroups'] = [];

    return (int) $row['users_id'];
}

$tickets_id = (int) (getenv('GLPICHAT_TICKET_ID') ?: 1);
$users_id = userPresenceSeedValidSession();
$public_rooms_id = glpichat_room_id($tickets_id, GLPICHAT_ROOM_PUBLIC);
$internal_rooms_id = glpichat_room_id($tickets_id, GLPICHAT_ROOM_INTERNAL);

// Limpar resíduos antigos de testes
$DB->delete('glpi_plugin_glpichat_participants', [
    'rooms_id' => [$public_rooms_id, $internal_rooms_id],
    'users_id' => $users_id,
]);
$DB->delete('glpi_plugin_glpichat_events', [
    'rooms_id' => [$public_rooms_id, $internal_rooms_id],
    'users_id' => $users_id,
]);

try {
    $widget_controller = new WidgetController();

    $channels_request = Request::create(
        '/plugins/glpichat/Widget/Channels',
        'GET',
        [
            'tickets_id' => $tickets_id,
        ]
    );
    $channels_response = $widget_controller->channels($channels_request);
    taskAssert($channels_response->getStatusCode() === 200, 'Widget channels deve retornar 200 OK');

    $participants_after_channels = iterator_to_array($DB->request([
        'FROM' => 'glpi_plugin_glpichat_participants',
        'WHERE' => [
            'rooms_id' => [$public_rooms_id, $internal_rooms_id],
            'users_id' => $users_id,
            'participant_type' => GLPICHAT_ACTOR_USER,
            'left_at' => null,
        ],
    ]));
    taskAssert(count($participants_after_channels) === 0, 'Widget channels nao deve entrar automaticamente nos canais public e internal');
    taskPass('Widget channels nao cria participantes automaticamente nos dois canais');

    // 1. Entrar apenas no canal publico carregando o historico dele
    $history_request = Request::create(
        '/plugins/glpichat/Widget/History',
        'GET',
        [
            'rooms_id' => $public_rooms_id,
            'before_id' => 0,
            'limit' => 30,
        ]
    );
    $history_response = $widget_controller->history($history_request);
    taskAssert($history_response->getStatusCode() === 200, 'Widget history do canal publico deve retornar 200 OK');

    $participants_id = (int) ($DB->request([
        'SELECT' => ['id'],
        'FROM' => 'glpi_plugin_glpichat_participants',
        'WHERE' => [
            'rooms_id' => $public_rooms_id,
            'users_id' => $users_id,
            'participant_type' => GLPICHAT_ACTOR_USER,
            'left_at' => null,
        ],
        'LIMIT' => 1,
    ])->current()['id'] ?? 0);
    taskAssert($participants_id > 0, 'Entrar no historico do canal publico deve criar participante apenas nesse canal');

    // Verificar se registrou user_joined
    $joined_event = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_events',
        'WHERE' => [
            'rooms_id' => $public_rooms_id,
            'users_id' => $users_id,
            'event_type' => 'user_joined',
        ],
        'LIMIT' => 1,
    ])->current();

    taskAssert(is_array($joined_event), 'Deve registrar o evento user_joined no banco');
    taskPass('Registro automático do evento user_joined na entrada do usuário logado no canal publico');

    $internal_joined_event = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_events',
        'WHERE' => [
            'rooms_id' => $internal_rooms_id,
            'users_id' => $users_id,
            'event_type' => 'user_joined',
        ],
        'LIMIT' => 1,
    ])->current();
    taskAssert(!is_array($internal_joined_event), 'Entrar no canal publico nao deve registrar user_joined no canal interno');
    taskPass('Entrar no canal publico nao gera user_joined no canal interno');

    $history_events_after_join = userPresenceHistoryEvents($widget_controller, $public_rooms_id);
    $joined_history_event = userPresenceFindEvent($history_events_after_join, 'user_joined', $users_id);
    taskAssert(is_array($joined_history_event), 'Widget history deve expor o evento user_joined na timeline');
    taskPass('Widget history expõe user_joined na timeline autenticada');

    // 2. Simular inatividade e expiração por inatividade
    // Forçar last_activity para 3 minutos atrás
    $expired_time = date('Y-m-d H:i:s', time() - 180);
    $DB->update('glpi_plugin_glpichat_participants', [
        'last_activity' => $expired_time,
    ], [
        'id' => $participants_id,
    ]);

    glpichat_expire_inactive_user_participants();

    $expired_participant = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_participants',
        'WHERE' => ['id' => $participants_id],
        'LIMIT' => 1,
    ])->current();

    taskAssert($expired_participant['left_at'] !== null, 'Timeout de inatividade deve marcar left_at');

    $left_event = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_events',
        'WHERE' => [
            'rooms_id' => $public_rooms_id,
            'users_id' => $users_id,
            'event_type' => 'user_left',
        ],
        'LIMIT' => 1,
    ])->current();

    taskAssert(is_array($left_event), 'Timeout de inatividade deve registrar o evento user_left');
    taskPass('Expiração por inatividade curta de usuários logados e registro de user_left');

    $history_events_after_timeout = userPresenceHistoryEvents($widget_controller, $public_rooms_id);
    $timeout_history_event = userPresenceFindEvent($history_events_after_timeout, 'user_left', $users_id);
    taskAssert(is_array($timeout_history_event), 'Widget history deve expor o evento user_left por timeout');
    taskPass('Widget history expõe user_left após expiração por inatividade');

    // 3. Simular entrada novamente e saída explícita via Controller
    // Re-garantir sessão ativa do usuário com direitos operacionais
    $_SESSION['glpiID'] = $users_id;
    $_SESSION['glpiactiveprofile']['plugin_glpichat_public'] = 1 | 4; // READ | CREATE

    $history_response_reenter = $widget_controller->history($history_request);
    taskAssert($history_response_reenter->getStatusCode() === 200, 'Reentrando no historico do canal publico deve retornar 200 OK');

    $participants_id2 = (int) ($DB->request([
        'SELECT' => ['id'],
        'FROM' => 'glpi_plugin_glpichat_participants',
        'WHERE' => [
            'rooms_id' => $public_rooms_id,
            'users_id' => $users_id,
            'participant_type' => GLPICHAT_ACTOR_USER,
            'left_at' => null,
        ],
        'LIMIT' => 1,
    ])->current()['id'] ?? 0);
    taskAssert($participants_id2 > 0, 'Reentrando no canal publico deve gerar novo participante');

    // Mockar CSRF validation pois estamos simulando a chamada
    $request = Request::create(
        '/plugins/glpichat/Widget/Leave',
        'POST',
        [
            'rooms_id' => $public_rooms_id,
            '_glpi_csrf_token' => 'mock-token',
        ]
    );
    // Ignorar validação CSRF simulada se necessário ou usar o token da sessão
    $_SESSION['glpicrsftokens'] = ['mock-token' => true];

    $response = $widget_controller->leave($request);
    taskAssert($response->getStatusCode() === 200, 'Endpoint leave deve retornar 200 OK');
    
    $response_json = json_decode($response->getContent(), true);
    taskAssert(isset($response_json['ok']) && $response_json['ok'] === true, 'Response JSON de saída explícita deve ser ok');

    $left_participant_explicit = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_participants',
        'WHERE' => ['id' => $participants_id2],
        'LIMIT' => 1,
    ])->current();

    taskAssert($left_participant_explicit['left_at'] !== null, 'Saída explícita via controller deve marcar left_at');
    taskPass('Saída explícita via endpoint Widget/Leave desconecta o usuário e registra o evento');

    $history_events_after_leave = userPresenceHistoryEvents($widget_controller, $public_rooms_id);
    $explicit_leave_events = array_values(array_filter(
        $history_events_after_leave,
        static fn(mixed $event): bool => is_array($event)
            && (string) ($event['event_type'] ?? '') === 'user_left'
            && (int) ($event['users_id'] ?? 0) === $users_id
    ));
    taskAssert(count($explicit_leave_events) >= 2, 'Widget history deve expor o evento user_left da saída explícita');
    taskPass('Widget history expõe user_left após saída explícita via Widget/Leave');

    fwrite(STDOUT, "[PASS] Smoke de presenca de usuarios logados concluido\n");
} finally {
    // Cleanup
    if (isset($users_id) && isset($public_rooms_id) && isset($internal_rooms_id)) {
        $DB->delete('glpi_plugin_glpichat_participants', [
            'rooms_id' => [$public_rooms_id, $internal_rooms_id],
            'users_id' => $users_id,
        ]);
        $DB->delete('glpi_plugin_glpichat_events', [
            'rooms_id' => [$public_rooms_id, $internal_rooms_id],
            'users_id' => $users_id,
        ]);
        $DB->delete('glpi_plugin_glpichat_auditlogs', [
            'rooms_id' => [$public_rooms_id, $internal_rooms_id],
            'users_id' => $users_id,
        ]);
    }
    unset($_SESSION['valid_id']);
    unset($_SESSION['glpiname']);
    unset($_SESSION['glpiID']);
    unset($_SESSION['glpiactiveprofile']);
    unset($_SESSION['glpiactive_entity']);
    unset($_SESSION['glpigroups']);
    unset($_SESSION['glpicrsftokens']);
}
