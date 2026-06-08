<?php

declare(strict_types=1);

use GlpiPlugin\Glpichat\Controller\GuestController;
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

/**
 * @return never
 */
function task2Fail(string $message): void
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function task2Pass(string $message): void
{
    fwrite(STDOUT, "[PASS] {$message}\n");
}

function task2Assert(bool $condition, string $message): void
{
    if (!$condition) {
        task2Fail($message);
    }
}

function task2Json(Response $response): array
{
    $content = $response->getContent();
    if (!is_string($content) || $content === '') {
        task2Fail('Resposta vazia do controller');
    }

    return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
}

global $DB;

$tickets_id = 1; // Chamado de teste padrão do smoke
$public_room_id = glpichat_room_id($tickets_id, GLPICHAT_ROOM_PUBLIC);

$guest_session_token = bin2hex(random_bytes(16));
$guest_participant_id = 0;
$guest_participant_id2 = 0;
$guest_participant_id3 = 0;

try {
    // 1. Criar participante Guest ativo (sem tickets_id, que não existe na tabela)
    $now = date('Y-m-d H:i:s');
    $DB->insert('glpi_plugin_glpichat_participants', [
        'rooms_id'            => $public_room_id,
        'users_id'            => 0,
        'guest_name'          => 'Task 2 Smoke Guest',
        'guest_email'         => 'task2-smoke@example.com',
        'participant_type'    => GLPICHAT_ACTOR_GUEST,
        'session_token_hash'  => hash('sha256', $guest_session_token),
        'joined_at'           => $now,
        'last_activity'       => $now,
    ]);
    $guest_participant_id = (int) $DB->insertId();

    task2Assert($guest_participant_id > 0, 'Participante guest deve ser inserido no banco');

    // 2. Chamar Guest/Sync e validar session_remaining_seconds e atualização de activity
    $guest_controller = new GuestController();
    
    // Aguardar 1 segundo para a última atividade diferir se houver atualização
    sleep(1);

    $sync_response = $guest_controller->sync(Request::create(
        '/plugins/glpichat/Guest/Sync',
        'GET',
        [
            'last_message_id' => '0',
            'last_event_id'   => '0',
            'is_typing'       => '1',
        ],
        [
            GLPICHAT_GUEST_COOKIE => $guest_session_token,
        ]
    ));

    task2Assert($sync_response->getStatusCode() === 200, 'Sync deve retornar 200 OK para participante ativo');
    $sync_json = task2Json($sync_response);
    
    task2Assert(
        isset($sync_json['session_remaining_seconds']) && $sync_json['session_remaining_seconds'] > 0,
        'Payload do sync deve retornar session_remaining_seconds positivo'
    );

    // Verificar se last_activity foi atualizado no banco
    $participant_row = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_participants',
        'WHERE' => ['id' => $guest_participant_id],
        'LIMIT' => 1,
    ])->current();
    
    task2Assert(
        strtotime($participant_row['last_activity']) > strtotime($now),
        'last_activity do participante deve ser atualizado na sincronização'
    );
    task2Pass('Sincronização de participante ativo atualiza atividade e retorna TTL');

    // 3. Forçar expiração alterando last_activity para 2 horas atrás
    $expired_time = date('Y-m-d H:i:s', time() - 7200);
    $DB->update('glpi_plugin_glpichat_participants', [
        'last_activity' => $expired_time,
        'joined_at'     => $expired_time,
    ], [
        'id' => $guest_participant_id,
    ]);

    // 4. Chamar Guest/Sync e validar que retorna 400 por inatividade
    $expired_sync_response = $guest_controller->sync(Request::create(
        '/plugins/glpichat/Guest/Sync',
        'GET',
        [
            'last_message_id' => '0',
            'last_event_id'   => '0',
        ],
        [
            GLPICHAT_GUEST_COOKIE => $guest_session_token,
        ]
    ));

    task2Assert($expired_sync_response->getStatusCode() === 400, 'Sync deve retornar 400 Bad Request para participante expirado');
    $expired_sync_json = task2Json($expired_sync_response);
    task2Assert(
        str_contains((string) ($expired_sync_json['error'] ?? ''), 'Guest session is invalid.'),
        'Mensagem de erro deve indicar expiração por inatividade'
    );

    // 5. Verificar no banco se o participante foi marcado como esgotado (left_at preenchido e hash nulo)
    $expired_participant_row = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_participants',
        'WHERE' => ['id' => $guest_participant_id],
        'LIMIT' => 1,
    ])->current();

    task2Assert($expired_participant_row['left_at'] !== null, 'left_at deve ser preenchido ao expirar');
    task2Assert($expired_participant_row['session_token_hash'] === null, 'session_token_hash deve ser limpo ao expirar');
    task2Pass('Sessão expirada é encerrada e bloqueada logicamente no banco');

    // 6. Testar desconexão manual via glpichat_disconnect_participant()
    $guest_session_token2 = bin2hex(random_bytes(16));
    $DB->insert('glpi_plugin_glpichat_participants', [
        'rooms_id'            => $public_room_id,
        'users_id'            => 0,
        'guest_name'          => 'Task 2 Smoke Guest 2',
        'guest_email'         => 'task2-smoke2@example.com',
        'participant_type'    => GLPICHAT_ACTOR_GUEST,
        'session_token_hash'  => hash('sha256', $guest_session_token2),
        'joined_at'           => $now,
        'last_activity'       => $now,
    ]);
    $guest_participant_id2 = (int) $DB->insertId();

    glpichat_disconnect_participant($guest_participant_id2, date('Y-m-d H:i:s'));

    $disconnected_participant_row = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_participants',
        'WHERE' => ['id' => $guest_participant_id2],
        'LIMIT' => 1,
    ])->current();

    task2Assert($disconnected_participant_row['left_at'] !== null, 'Desconexão manual deve definir left_at');
    task2Assert($disconnected_participant_row['session_token_hash'] === null, 'Desconexão manual deve limpar session_token_hash');
    task2Pass('Desconexão manual do participante é processada com sucesso no banco');

    // 7. Testar saída explícita do guest ao fechar a aba
    $guest_session_token3 = bin2hex(random_bytes(16));
    $DB->insert('glpi_plugin_glpichat_participants', [
        'rooms_id'            => $public_room_id,
        'users_id'            => 0,
        'guest_name'          => 'Task 2 Smoke Guest 3',
        'guest_email'         => 'task2-smoke3@example.com',
        'participant_type'    => GLPICHAT_ACTOR_GUEST,
        'session_token_hash'  => hash('sha256', $guest_session_token3),
        'joined_at'           => $now,
        'last_activity'       => $now,
    ]);
    $guest_participant_id3 = (int) $DB->insertId();

    $leave_response = $guest_controller->leave(Request::create(
        '/plugins/glpichat/Guest/Leave',
        'POST',
        [],
        [
            GLPICHAT_GUEST_COOKIE => $guest_session_token3,
        ]
    ));

    task2Assert($leave_response->getStatusCode() === 204, 'Guest leave deve retornar 204');

    $left_participant_row = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_participants',
        'WHERE' => ['id' => $guest_participant_id3],
        'LIMIT' => 1,
    ])->current();

    task2Assert($left_participant_row['left_at'] !== null, 'Guest leave deve definir left_at');
    task2Assert($left_participant_row['session_token_hash'] === null, 'Guest leave deve limpar session_token_hash');
    task2Pass('Saída explícita do guest encerra a sessão no fechamento da aba');

    fwrite(STDOUT, "[PASS] Smoke de controle de sessao de convidados concluido\n");
} finally {
    // 8. Cleanup
    if ($guest_participant_id > 0) {
        $DB->delete('glpi_plugin_glpichat_participants', ['id' => $guest_participant_id]);
    }
    if ($guest_participant_id2 > 0) {
        $DB->delete('glpi_plugin_glpichat_participants', ['id' => $guest_participant_id2]);
    }
    if (($guest_participant_id3 ?? 0) > 0) {
        $DB->delete('glpi_plugin_glpichat_participants', ['id' => $guest_participant_id3]);
    }
}
