<?php

declare(strict_types=1);

use GlpiPlugin\Glpichat\Controller\GuestController;
use GlpiPlugin\Glpichat\Controller\SendController;
use GlpiPlugin\Glpichat\Controller\UploadController;
use GlpiPlugin\Glpichat\HookHandler;
use Glpi\Plugin\Hooks;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

$loader = require dirname(__DIR__, 5) . '/vendor/autoload.php';
$loader->addPsr4('GlpiPlugin\\Glpichat\\', dirname(__DIR__, 3) . '/src/');

const TASK3_SMOKE_GROUP_NAME = 'test';

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
function task3Fail(string $message): void
{
    throw new RuntimeException($message);
}

function task3Pass(string $message): void
{
    fwrite(STDOUT, "[PASS] {$message}\n");
}

function task3Assert(bool $condition, string $message): void
{
    if (!$condition) {
        task3Fail($message);
    }
}

function task3AssertThrows(callable $callback, string $expectedFragment, string $message): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        task3Assert(
            str_contains($error->getMessage(), $expectedFragment),
            $message . ' - unexpected error: ' . $error->getMessage()
        );
        task3Pass($message);
        return;
    }

    task3Fail($message . ' - no exception thrown');
}

function task3Json(Response $response): array
{
    $content = $response->getContent();
    if (!is_string($content) || $content === '') {
        task3Fail('Resposta vazia do controller');
    }

    return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
}

function task3AssertJsonError(Response $response, string $expectedFragment, string $message): void
{
    task3Assert($response->getStatusCode() === 400, $message . ' - status inesperado: ' . $response->getStatusCode());
    $json = task3Json($response);
    task3Assert(
        str_contains((string) ($json['error'] ?? ''), $expectedFragment),
        $message . ' - body inesperado: ' . json_encode($json, JSON_THROW_ON_ERROR)
    );
    task3Pass($message);
}

function task3MaxEventId(): int
{
    global $DB;

    $row = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_events',
        'ORDER' => ['id DESC'],
        'LIMIT' => 1,
    ])->current();

    return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
}

function task3SmokeTicketId(): int
{
    return (int) (getenv('GLPICHAT_TICKET_ID') ?: 1);
}

function task3TicketSnapshot(int $tickets_id): array
{
    global $DB;

    $row = $DB->request([
        'SELECT' => ['id', 'status', 'priority'],
        'FROM' => 'glpi_tickets',
        'WHERE' => ['id' => $tickets_id],
        'LIMIT' => 1,
    ])->current();

    task3Assert(is_array($row), 'Nao foi possivel carregar snapshot do ticket');
    return $row;
}

function task3SeedValidSession(): void
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

    task3Assert(is_array($row), 'Sessao de teste precisa de um usuario GLPI ativo');

    $_SESSION['valid_id'] = session_id();
    $_SESSION['glpiname'] = (string) $row['user_name'];
    $_SESSION['glpiID'] = (int) $row['users_id'];
    $_SESSION['glpiactiveprofile'] = [
        'id'                 => (int) $row['profiles_id'],
        'last_rights_update' => $row['last_rights_update'],
    ];
    $_SESSION['glpiactive_entity'] = (int) ($row['entities_id'] ?? 0);
    $_SESSION['glpigroups'] = [];
}

function task3FindUnassignedUser(int $tickets_id): int
{
    global $DB;

    $assigned = [];
    $rows = $DB->request([
        'SELECT' => ['users_id'],
        'FROM' => 'glpi_tickets_users',
        'WHERE' => [
            'tickets_id' => $tickets_id,
            'type'       => CommonITILActor::ASSIGN,
        ],
    ]);
    foreach ($rows as $row) {
        $assigned[] = (int) ($row['users_id'] ?? 0);
    }

    $where = [
        'is_active'  => 1,
        'is_deleted' => 0,
        'id'         => ['>', 0],
    ];
    if (count($assigned) > 0) {
        $where[] = [
            'NOT' => [
                'id' => $assigned,
            ],
        ];
    }

    $row = $DB->request([
        'SELECT' => ['id'],
        'FROM' => 'glpi_users',
        'WHERE' => $where,
        'ORDER' => ['id ASC'],
        'LIMIT' => 1,
    ])->current();

    return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
}

function task3FindUnassignedGroup(int $tickets_id): int
{
    global $DB;

    $assigned = [];
    $rows = $DB->request([
        'SELECT' => ['groups_id'],
        'FROM' => 'glpi_groups_tickets',
        'WHERE' => [
            'tickets_id' => $tickets_id,
            'type'       => CommonITILActor::ASSIGN,
        ],
    ]);
    foreach ($rows as $row) {
        $assigned[] = (int) ($row['groups_id'] ?? 0);
    }

    $where = [
        'id' => ['>', 0],
    ];
    if (count($assigned) > 0) {
        $where[] = [
            'NOT' => [
                'id' => $assigned,
            ],
        ];
    }

    $row = $DB->request([
        'SELECT' => ['id'],
        'FROM' => 'glpi_groups',
        'WHERE' => $where,
        'ORDER' => ['id ASC'],
        'LIMIT' => 1,
    ])->current();

    return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
}

function task3GroupIdByName(string $name): int
{
    global $DB;

    $row = $DB->request([
        'SELECT' => ['id'],
        'FROM' => 'glpi_groups',
        'WHERE' => ['name' => $name],
        'LIMIT' => 1,
    ])->current();

    return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
}

function task3TicketHasAssignedGroup(int $tickets_id, int $groups_id): bool
{
    global $DB;

    $row = $DB->request([
        'SELECT' => ['groups_id'],
        'FROM' => 'glpi_groups_tickets',
        'WHERE' => [
            'tickets_id' => $tickets_id,
            'groups_id'  => $groups_id,
            'type'       => CommonITILActor::ASSIGN,
        ],
        'LIMIT' => 1,
    ])->current();

    return is_array($row);
}

function task3FirstAssignedUser(int $tickets_id): int
{
    global $DB;

    $row = $DB->request([
        'SELECT' => ['users_id'],
        'FROM' => 'glpi_tickets_users',
        'WHERE' => [
            'tickets_id' => $tickets_id,
            'type'       => CommonITILActor::ASSIGN,
        ],
        'ORDER' => ['users_id ASC'],
        'LIMIT' => 1,
    ])->current();

    return is_array($row) ? (int) ($row['users_id'] ?? 0) : 0;
}

function task3FirstAssignedGroup(int $tickets_id): int
{
    global $DB;

    $row = $DB->request([
        'SELECT' => ['groups_id'],
        'FROM' => 'glpi_groups_tickets',
        'WHERE' => [
            'tickets_id' => $tickets_id,
            'type'       => CommonITILActor::ASSIGN,
        ],
        'ORDER' => ['groups_id ASC'],
        'LIMIT' => 1,
    ])->current();

    return is_array($row) ? (int) ($row['groups_id'] ?? 0) : 0;
}

function task3EventsAfter(int $baselineId, int $tickets_id): array
{
    global $DB;

    $rows = [];
    $iterator = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_events',
        'WHERE' => [
            'tickets_id' => $tickets_id,
            ['id' => ['>', $baselineId]],
        ],
        'ORDER' => ['id ASC'],
    ]);

    foreach ($iterator as $row) {
        $row['payload'] = json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $rows[] = $row;
    }

    return $rows;
}

function task3GuestCookie(string $token): array
{
    return [
        GLPICHAT_GUEST_COOKIE => $token,
    ];
}

task3SeedValidSession();

global $PLUGIN_HOOKS, $DB;

task3Assert(
    isset($PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['glpichat']),
    'Hook PRE_ITEM_UPDATE deve estar registrado'
);
task3Assert(
    isset($PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['glpichat']),
    'Hook ITEM_UPDATE deve estar registrado'
);
task3Pass('Hooks de ciclo de vida do ticket estao registrados');

$tickets_id = task3SmokeTicketId();
$public_room_id = glpichat_room_id($tickets_id, GLPICHAT_ROOM_PUBLIC);
$internal_room_id = glpichat_room_id($tickets_id, GLPICHAT_ROOM_INTERNAL);
$original_ticket = task3TicketSnapshot($tickets_id);
$baseline_event_id = task3MaxEventId();

$added_user_id = 0;
$added_group_id = 0;
$created_group_id = 0;  // grupo criado pelo smoke (precisa de cleanup)
$user_change_mode = 'add';
$group_change_mode = 'add';
$should_test_user_assignment = true;
$guest_participant_id = 0;
$guest_session_token = bin2hex(random_bytes(16));
$event_ids_to_cleanup = [];

try {
    $candidate_user_id = task3FindUnassignedUser($tickets_id);
    $candidate_group_id = task3GroupIdByName(TASK3_SMOKE_GROUP_NAME);
    if ($candidate_user_id <= 0) {
        $candidate_user_id = task3FirstAssignedUser($tickets_id);
        $user_change_mode = 'remove';
    }
    // Se o grupo de teste nao existe, criá-lo agora (instalacoes limpas nao tem grupos pre-criados).
    if ($candidate_group_id <= 0) {
        $group = new Group();
        $candidate_group_id = (int) $group->add(['name' => TASK3_SMOKE_GROUP_NAME, 'entities_id' => 0]);
        task3Assert($candidate_group_id > 0, sprintf('Nao foi possivel criar grupo de teste "%s"', TASK3_SMOKE_GROUP_NAME));
        $created_group_id = $candidate_group_id;
    }
    task3Assert($candidate_group_id > 0, sprintf('Grupo de teste "%s" deve existir ou ter sido criado', TASK3_SMOKE_GROUP_NAME));
    if (task3TicketHasAssignedGroup($tickets_id, $candidate_group_id)) {
        $group_change_mode = 'remove';
    }

    if ($candidate_user_id <= 0) {
        $should_test_user_assignment = false;
    }

    $before_update_ticket = new Ticket();
    task3Assert($before_update_ticket->getFromDB($tickets_id), 'Nao foi possivel carregar ticket para snapshot do hook');
    HookHandler::preItemUpdate($before_update_ticket);

    if ($should_test_user_assignment && $user_change_mode === 'add') {
        $DB->insert('glpi_tickets_users', [
            'tickets_id' => $tickets_id,
            'users_id'   => $candidate_user_id,
            'type'       => CommonITILActor::ASSIGN,
        ]);
    } elseif ($should_test_user_assignment) {
        $DB->delete('glpi_tickets_users', [
            'tickets_id' => $tickets_id,
            'users_id'   => $candidate_user_id,
            'type'       => CommonITILActor::ASSIGN,
        ]);
    }
    $added_user_id = $candidate_user_id;

    if ($group_change_mode === 'add') {
        $DB->insert('glpi_groups_tickets', [
            'tickets_id' => $tickets_id,
            'groups_id'  => $candidate_group_id,
            'type'       => CommonITILActor::ASSIGN,
        ]);
    } else {
        $DB->delete('glpi_groups_tickets', [
            'tickets_id' => $tickets_id,
            'groups_id'  => $candidate_group_id,
            'type'       => CommonITILActor::ASSIGN,
        ]);
    }
    $added_group_id = $candidate_group_id;

    $new_priority = (int) $original_ticket['priority'] === 5 ? 4 : 5;
    $simulated_ticket = new Ticket();
    task3Assert($simulated_ticket->getFromDB($tickets_id), 'Nao foi possivel recarregar ticket para itemUpdate');
    $simulated_ticket->updates = ['status', 'priority'];
    $simulated_ticket->oldvalues = [
        'status'   => (int) $original_ticket['status'],
        'priority' => (int) $original_ticket['priority'],
    ];
    $simulated_ticket->fields['status'] = CommonITILObject::SOLVED;
    $simulated_ticket->fields['priority'] = $new_priority;
    HookHandler::itemUpdate($simulated_ticket);

    $recorded_events = task3EventsAfter($baseline_event_id, $tickets_id);
    foreach ($recorded_events as $event_row) {
        $event_ids_to_cleanup[] = (int) $event_row['id'];
    }

    $expected_types = [
        'ticket_status_changed' => 2,
        'ticket_priority_changed' => 2,
        'ticket_assigned_groups_changed' => 2,
    ];
    if ($should_test_user_assignment) {
        $expected_types['ticket_assigned_users_changed'] = 2;
    }
    foreach ($expected_types as $event_type => $expected_count) {
        $matches = array_values(array_filter(
            $recorded_events,
            static fn(array $row): bool => (string) ($row['event_type'] ?? '') === $event_type
        ));
        task3Assert(
            count($matches) === $expected_count,
            sprintf('Evento %s deve ser registrado %d vezes (public + internal)', $event_type, $expected_count)
        );
        $rooms = array_map(static fn(array $row): int => (int) ($row['rooms_id'] ?? 0), $matches);
        sort($rooms);
        task3Assert(
            $rooms === [$public_room_id, $internal_room_id],
            sprintf('Evento %s deve existir nos canais publico e interno', $event_type)
        );
    }

    $status_payload = null;
    foreach ($recorded_events as $event_row) {
        if ((string) ($event_row['event_type'] ?? '') === 'ticket_status_changed') {
            $status_payload = $event_row['payload'];
            break;
        }
    }
    task3Assert(is_array($status_payload), 'Evento de status deve carregar payload');
    task3Assert(
        (int) ($status_payload['new_status'] ?? 0) === CommonITILObject::SOLVED
        && ($status_payload['is_terminal'] ?? false) === true,
        'Payload de status deve marcar ticket como terminal'
    );
    task3Pass('Hook item_update gera eventos operacionais nos dois canais');

    $DB->update('glpi_tickets', [
        'status' => CommonITILObject::SOLVED,
    ], [
        'id' => $tickets_id,
    ]);

    $csrf = Session::getNewCSRFToken();
    $send_response = (new SendController())->__invoke(Request::create(
        '/plugins/glpichat/Send',
        'POST',
        [
            '_glpi_csrf_token' => $csrf,
            'tickets_id'       => (string) $tickets_id,
            'room_type'        => GLPICHAT_ROOM_PUBLIC,
            'content'          => 'task3 archived send',
        ]
    ));
    task3AssertJsonError($send_response, 'O chat está arquivado', 'SendController bloqueia ticket solucionado');

    $upload_csrf = Session::getNewCSRFToken();
    $upload_file = new UploadedFile(
        dirname(__DIR__, 2) . '/fixtures/allowed_upload.txt',
        'allowed_upload.txt',
        'text/plain',
        null,
        true
    );
    $upload_response = (new UploadController())->__invoke(Request::create(
        '/plugins/glpichat/Upload',
        'POST',
        [
            '_glpi_csrf_token' => $upload_csrf,
            'tickets_id'       => (string) $tickets_id,
            'room_type'        => GLPICHAT_ROOM_PUBLIC,
        ],
        [],
        ['file' => $upload_file]
    ));
    task3AssertJsonError($upload_response, 'O chat está arquivado', 'UploadController bloqueia ticket solucionado');

    $DB->insert('glpi_plugin_glpichat_participants', [
        'rooms_id'            => $public_room_id,
        'users_id'            => 0,
        'guest_name'          => 'Task 3 Smoke Guest',
        'guest_email'         => 'task3-smoke@example.com',
        'participant_type'    => GLPICHAT_ACTOR_GUEST,
        'session_token_hash'  => hash('sha256', $guest_session_token),
        'joined_at'           => date('Y-m-d H:i:s'),
    ]);
    $guest_participant_id = (int) $DB->insertId();

    $guest_controller = new GuestController();
    $guest_sync_response = $guest_controller->sync(Request::create(
        '/plugins/glpichat/Guest/Sync',
        'GET',
        [
            'last_message_id' => '0',
            'last_event_id'   => '0',
        ],
        task3GuestCookie($guest_session_token)
    ));
    $guest_sync_json = task3Json($guest_sync_response);
    task3Assert(
        (int) ($guest_sync_json['ticket_status'] ?? 0) === CommonITILObject::SOLVED,
        'Guest/Sync deve expor ticket_status terminal'
    );
    task3Pass('Guest/Sync expõe status do ticket para modo somente leitura');

    $guest_send_response = $guest_controller->send(Request::create(
        '/plugins/glpichat/Guest/Send',
        'POST',
        [
            'content' => 'task3 guest archived send',
        ],
        task3GuestCookie($guest_session_token)
    ));
    task3AssertJsonError($guest_send_response, 'O chat está arquivado', 'GuestController bloqueia envio em ticket solucionado');

    $guest_upload_file = new UploadedFile(
        dirname(__DIR__, 2) . '/fixtures/allowed_upload.txt',
        'allowed_upload.txt',
        'text/plain',
        null,
        true
    );
    $guest_upload_response = $guest_controller->upload(Request::create(
        '/plugins/glpichat/Guest/Upload',
        'POST',
        [],
        task3GuestCookie($guest_session_token),
        ['file' => $guest_upload_file]
    ));
    task3AssertJsonError($guest_upload_response, 'O chat está arquivado', 'GuestController bloqueia upload em ticket solucionado');

    $DB->update('glpi_tickets', [
        'status' => CommonITILObject::CLOSED,
    ], [
        'id' => $tickets_id,
    ]);
    task3AssertThrows(
        static function () use ($tickets_id): void {
            glpichat_assert_ticket_open_for_chat($tickets_id);
        },
        'O chat está arquivado',
        'Helper bloqueia ticket fechado'
    );

    fwrite(STDOUT, "[PASS] Smoke da task 3 concluido\n");
} catch (RuntimeException $error) {
    fwrite(STDERR, "[FAIL] {$error->getMessage()}\n");
    exit(1);
} finally {
    if ($guest_participant_id > 0) {
        $DB->delete('glpi_plugin_glpichat_participants', ['id' => $guest_participant_id]);
    }

    if ($added_user_id > 0 && $user_change_mode === 'add') {
        $DB->delete('glpi_tickets_users', [
            'tickets_id' => $tickets_id,
            'users_id'   => $added_user_id,
            'type'       => CommonITILActor::ASSIGN,
        ]);
    } elseif ($added_user_id > 0) {
        $DB->insert('glpi_tickets_users', [
            'tickets_id' => $tickets_id,
            'users_id'   => $added_user_id,
            'type'       => CommonITILActor::ASSIGN,
        ]);
    }

    if ($added_group_id > 0 && $group_change_mode === 'add') {
        $DB->delete('glpi_groups_tickets', [
            'tickets_id' => $tickets_id,
            'groups_id'  => $added_group_id,
            'type'       => CommonITILActor::ASSIGN,
        ]);
    } elseif ($added_group_id > 0) {
        $DB->insert('glpi_groups_tickets', [
            'tickets_id' => $tickets_id,
            'groups_id'  => $added_group_id,
            'type'       => CommonITILActor::ASSIGN,
        ]);
    }

    if (count($event_ids_to_cleanup) > 0) {
        $DB->delete('glpi_plugin_glpichat_events', [
            'id' => $event_ids_to_cleanup,
        ]);
    }

    // Remove o grupo criado pelo smoke (se foi criado aqui, não existia antes).
    if ($created_group_id > 0) {
        $DB->delete('glpi_groups', ['id' => $created_group_id]);
    }

    $DB->update('glpi_tickets', [
        'status' => (int) $original_ticket['status'],
    ], [
        'id' => $tickets_id,
    ]);
}
