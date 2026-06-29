<?php

declare(strict_types=1);

// Smoke: valida o formulario/fluxo de convite (invite) "campo a campo" e os
// bloqueios de permissao, exercitando os CONTROLLERS REAIS (InviteController,
// InviteManageController) e os use-cases de dominio com sessoes semeadas.
//
// Cobre:
//   - guest_name obrigatorio (controller + use-case, mensagens PT reais);
//   - guest_email opcional (vazio aceito) + validacao de formato no controller;
//   - max_invites_per_ticket (bloqueio com mensagem PT);
//   - permissao de criacao (glpichat_can_create_invites / assert);
//   - listagem (/Invite/List exige can_view_participants);
//   - revoke/reissue (bloqueios de UPDATE + estados invalidos);
//   - estados derivados do convite (ativo -> revogado / aceito) a nivel de dados.
//
// NAO e destrutivo: usa baseline de ids + cleanup no finally e restaura as
// configs alteradas. Reaproveita o padrao de seed de sessao dos demais smokes.

use GlpiPlugin\Glpichat\Controller\InviteController;
use GlpiPlugin\Glpichat\Controller\InviteManageController;
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
function invFail(string $message): void
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function invPass(string $message): void
{
    fwrite(STDOUT, "[PASS] {$message}\n");
}

function invNote(string $message): void
{
    fwrite(STDOUT, "[NOTE] {$message}\n");
}

function invAssert(bool $condition, string $message): void
{
    if (!$condition) {
        invFail($message);
    }
}

function invJson(\Symfony\Component\HttpFoundation\Response $response): array
{
    $content = $response->getContent();
    invAssert(is_string($content) && $content !== '', 'Resposta vazia do controller');
    return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
}

function invMaxId(string $table): int
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

/**
 * Semeia a sessao com o usuario "glpi" (tecnico atribuido ao ticket da esteira)
 * para garantir leitura real do chamado; fallback para qualquer usuario ativo.
 * Os direitos do plugin sao concedidos por padrao e podem ser ajustados depois
 * via invSetInviteRight()/$_SESSION.
 */
function invSeedSession(): int
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

    if (!is_array($row)) {
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
    }

    invAssert(is_array($row), 'Sessao de teste precisa de um usuario GLPI ativo');

    $users_id = (int) $row['users_id'];
    $_SESSION['valid_id'] = session_id();
    $_SESSION['glpiname'] = (string) $row['user_name'];
    $_SESSION['glpiID'] = $users_id;
    $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
    Session::initEntityProfiles($users_id);
    Session::changeProfile((int) $row['profiles_id']);
    $_SESSION['glpiactiveprofile']['plugin_glpichat_public'] = READ | CREATE;
    $_SESSION['glpiactiveprofile']['plugin_glpichat_internal'] = READ | CREATE;
    $_SESSION['glpiactiveprofile']['plugin_glpichat_invite'] = READ | CREATE | UPDATE;
    $_SESSION['glpiactive_entity'] = (int) ($row['entities_id'] ?? 0);
    $_SESSION['glpigroups'] = [];
    $_SESSION['glpicsrftokens'] = [];

    return $users_id;
}

/**
 * Ajusta o direito plugin_glpichat_invite na sessao (mascara de bits ou 0).
 */
function invSetInviteRight(int $mask): void
{
    $_SESSION['glpiactiveprofile']['plugin_glpichat_invite'] = $mask;
    glpichat_clear_static_caches();
}

global $DB;

$tickets_id = (int) (getenv('GLPICHAT_TICKET_ID') ?: 1);
$users_id = invSeedSession();
$public_rooms_id = glpichat_room_id($tickets_id, GLPICHAT_ROOM_PUBLIC);

// Insere um convite diretamente, util para os testes de revoke/reissue/estados.
$insert_invite_row = static function (string $name, string $email, ?string $accepted_at, ?string $revoked_at, int $ttl = 3600) use ($tickets_id, $public_rooms_id, $users_id): int {
    global $DB;
    $DB->insert('glpi_plugin_glpichat_invites', [
        'rooms_id'      => $public_rooms_id,
        'tickets_id'    => $tickets_id,
        'token_hash'    => hash('sha256', bin2hex(random_bytes(16))),
        'created_by'    => $users_id,
        'guest_name'    => $name,
        'guest_email'   => $email,
        'expires_at'    => date('Y-m-d H:i:s', time() + $ttl),
        'date_creation' => date('Y-m-d H:i:s'),
        'accepted_at'   => $accepted_at,
        'revoked_at'    => $revoked_at,
    ]);
    return (int) $DB->insertId();
};

$baseline = [
    'glpi_plugin_glpichat_events'       => invMaxId('glpi_plugin_glpichat_events'),
    'glpi_plugin_glpichat_auditlogs'    => invMaxId('glpi_plugin_glpichat_auditlogs'),
    'glpi_plugin_glpichat_invites'      => invMaxId('glpi_plugin_glpichat_invites'),
    'glpi_plugin_glpichat_participants' => invMaxId('glpi_plugin_glpichat_participants'),
    'glpi_plugin_glpichat_messages'     => invMaxId('glpi_plugin_glpichat_messages'),
];

$config_keys = ['max_invites_per_ticket', 'invite_ttl_seconds'];
$config_snapshot = [];
foreach ($config_keys as $key) {
    $config_snapshot[$key] = (string) Config::getConfigurationValue('plugin:glpichat', $key);
}

try {
    $invite_ctrl = new InviteController();
    $manage_ctrl = new InviteManageController();

    $build_create = static function (array $params): Request {
        $params['_glpi_csrf_token'] = Session::getNewCSRFToken();
        return Request::create('/plugins/glpichat/Invite', 'POST', $params);
    };

    // -----------------------------------------------------------------
    // 1) guest_name obrigatorio
    //    a) use-case (glpichat_validate_invite_input): 'Parâmetros de convite inválidos.'
    //    b) controller (InviteController): 'O nome do convidado é obrigatório.'
    // -----------------------------------------------------------------
    $name_threw = false;
    try {
        glpichat_validate_invite_input($tickets_id, '   ', '');
    } catch (RuntimeException $e) {
        $name_threw = true;
        invAssert(
            str_contains($e->getMessage(), 'Parâmetros de convite inválidos.'),
            'validate_invite_input com nome vazio deve usar a mensagem PT real - veio: ' . $e->getMessage()
        );
    }
    invAssert($name_threw, 'glpichat_validate_invite_input deve rejeitar guest_name vazio');

    $resp = $invite_ctrl->__invoke($build_create([
        'tickets_id' => $tickets_id,
        'guest_name' => '   ',
        'guest_email' => '',
    ]));
    invAssert($resp->getStatusCode() === 400, 'InviteController com nome vazio deve retornar 400');
    $json = invJson($resp);
    invAssert(
        isset($json['error']) && str_contains((string) $json['error'], 'O nome do convidado é obrigatório.'),
        'InviteController com nome vazio deve usar a mensagem PT real - veio: ' . ($json['error'] ?? '(sem error)')
    );
    invPass('guest_name obrigatorio: bloqueado no use-case e no controller (mensagens PT reais)');

    // -----------------------------------------------------------------
    // 2) guest_email
    //    a) vazio e aceito (opcional): cria convite SEM email.
    //    b) formato invalido: o CONTROLLER valida server-side ('Formato de e-mail inválido.').
    //       NOTA: a validacao de formato existe APENAS na camada do controller
    //       (InviteController); o use-case/glpichat_validate_invite_input NAO
    //       valida formato de email (so presenca de nome + ticket).
    // -----------------------------------------------------------------
    $resp = $invite_ctrl->__invoke($build_create([
        'tickets_id' => $tickets_id,
        'guest_name' => 'Convidado Sem Email',
        'guest_email' => '',
    ]));
    invAssert($resp->getStatusCode() === 200, 'Criar convite com email vazio deve retornar 200');
    $json = invJson($resp);
    invAssert(isset($json['id']) && (int) $json['id'] > 0, 'Convite sem email deve ser criado com id valido');
    $created_no_email = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_invites',
        'WHERE' => ['id' => (int) $json['id']],
        'LIMIT' => 1,
    ])->current();
    invAssert(is_array($created_no_email), 'Convite sem email deve existir no banco');
    invAssert((string) ($created_no_email['guest_email'] ?? 'x') === '', 'Convite sem email deve gravar guest_email vazio');
    invPass('guest_email vazio e aceito (campo opcional) e o convite e criado sem email');

    $resp = $invite_ctrl->__invoke($build_create([
        'tickets_id' => $tickets_id,
        'guest_name' => 'Convidado Email Ruim',
        'guest_email' => 'nao-e-email',
    ]));
    invAssert($resp->getStatusCode() === 400, 'Email com formato invalido deve retornar 400');
    $json = invJson($resp);
    invAssert(
        isset($json['error']) && str_contains((string) $json['error'], 'Formato de e-mail inválido.'),
        'Email invalido deve usar a mensagem PT real - veio: ' . ($json['error'] ?? '(sem error)')
    );
    invNote('guest_email TEM validacao de formato server-side, mas SO no InviteController; o use-case glpichat_validate_invite_input nao valida formato de email.');
    invPass('guest_email com formato invalido e bloqueado pelo controller (mensagem PT real)');

    // -----------------------------------------------------------------
    // 3) max_invites_per_ticket -> bloqueia alem do limite (mensagem PT)
    // -----------------------------------------------------------------
    // Conta ativos atuais (inclui o convite sem-email criado acima).
    $active_row = $DB->request([
        'COUNT' => 'cpt',
        'FROM'  => 'glpi_plugin_glpichat_invites',
        'WHERE' => [
            'tickets_id'  => $tickets_id,
            'accepted_at' => null,
            'revoked_at'  => null,
            ['expires_at' => ['>', new \Glpi\DBAL\QueryExpression('NOW()')]],
        ],
    ])->current();
    $active_now = (int) ($active_row['cpt'] ?? 0);
    Config::setConfigurationValues('plugin:glpichat', ['max_invites_per_ticket' => (string) ($active_now + 1)]);

    // Um a mais cabe.
    $resp_ok = $invite_ctrl->__invoke($build_create([
        'tickets_id' => $tickets_id,
        'guest_name' => 'Convidado No Limite',
        'guest_email' => '',
    ]));
    invAssert($resp_ok->getStatusCode() === 200, 'Convite dentro do limite deve ser criado (200)');

    // O proximo estoura o limite.
    $resp_over = $invite_ctrl->__invoke($build_create([
        'tickets_id' => $tickets_id,
        'guest_name' => 'Convidado Acima Do Limite',
        'guest_email' => '',
    ]));
    invAssert($resp_over->getStatusCode() === 400, 'Convite acima do limite deve retornar 400');
    $json = invJson($resp_over);
    invAssert(
        isset($json['error']) && str_contains((string) $json['error'], 'Limite máximo de convites ativos por chamado atingido.'),
        'max_invites_per_ticket deve usar a mensagem PT real - veio: ' . ($json['error'] ?? '(sem error)')
    );
    invPass('max_invites_per_ticket bloqueia convites alem do limite (mensagem PT real)');

    // Restaura limite alto para os testes seguintes.
    Config::setConfigurationValues('plugin:glpichat', ['max_invites_per_ticket' => '100']);

    // -----------------------------------------------------------------
    // 4) Permissao de criacao (glpichat_can_create_invites / assert)
    //    Sem CREATE em plugin_glpichat_invite -> bloqueado.
    //    Com CREATE -> permitido.
    // -----------------------------------------------------------------
    invSetInviteRight(READ); // sem CREATE
    invAssert(glpichat_can_create_invites($tickets_id) === false, 'Sem CREATE de invite, can_create_invites deve ser false');
    $create_blocked = false;
    try {
        glpichat_assert_can_create_invites($tickets_id);
    } catch (RuntimeException $e) {
        $create_blocked = true;
        invAssert(
            str_contains($e->getMessage(), 'Você não tem permissão para convidar externos.'),
            'assert_can_create_invites deve usar a mensagem PT real - veio: ' . $e->getMessage()
        );
    }
    invAssert($create_blocked, 'Sem CREATE de invite, a criacao deve ser bloqueada');

    // Via controller real: sem permissao -> 400 com a mesma mensagem PT.
    $resp = $invite_ctrl->__invoke($build_create([
        'tickets_id' => $tickets_id,
        'guest_name' => 'Convidado Sem Permissao',
        'guest_email' => '',
    ]));
    invAssert($resp->getStatusCode() === 400, 'InviteController sem permissao deve retornar 400');
    $json = invJson($resp);
    invAssert(
        isset($json['error']) && str_contains((string) $json['error'], 'Você não tem permissão para convidar externos.'),
        'InviteController sem permissao deve usar a mensagem PT real - veio: ' . ($json['error'] ?? '(sem error)')
    );

    invSetInviteRight(READ | CREATE | UPDATE); // restaura
    invAssert(glpichat_can_create_invites($tickets_id) === true, 'Com CREATE de invite, can_create_invites deve ser true');
    invPass('Permissao de criacao: bloqueada sem CREATE, permitida com CREATE (mensagem PT real)');

    // -----------------------------------------------------------------
    // 5) Listagem (/Invite/List exige can_view_participants)
    //    can_view_participants = read_room(public) AND invite READ.
    // -----------------------------------------------------------------
    invSetInviteRight(CREATE); // sem READ -> sem can_view_participants
    invAssert(glpichat_can_view_participants($tickets_id) === false, 'Sem READ de invite, can_view_participants deve ser false');
    $resp = $manage_ctrl->list(Request::create('/plugins/glpichat/Invite/List', 'GET', ['tickets_id' => $tickets_id]));
    invAssert($resp->getStatusCode() === 400, 'Invite/List sem can_view_participants deve retornar 400');
    $json = invJson($resp);
    invAssert(
        isset($json['error']) && str_contains((string) $json['error'], 'Você não tem permissão para visualizar os participantes do chat.'),
        'Invite/List sem permissao deve usar a mensagem PT real - veio: ' . ($json['error'] ?? '(sem error)')
    );

    invSetInviteRight(READ | CREATE | UPDATE); // restaura
    invAssert(glpichat_can_view_participants($tickets_id) === true, 'Com READ de invite, can_view_participants deve ser true');
    $resp = $manage_ctrl->list(Request::create('/plugins/glpichat/Invite/List', 'GET', ['tickets_id' => $tickets_id]));
    invAssert($resp->getStatusCode() === 200, 'Invite/List com permissao deve retornar 200');
    $json = invJson($resp);
    invAssert(array_key_exists('invites', $json) && is_array($json['invites']), 'Invite/List deve retornar a lista de invites');
    invAssert(count($json['invites']) >= 1, 'Invite/List deve listar os convites criados neste teste');
    invPass('Listagem: bloqueada sem can_view_participants, retorna a lista com a permissao');

    // -----------------------------------------------------------------
    // 6) Revoke / Reissue (bloqueios de UPDATE + estados invalidos)
    // -----------------------------------------------------------------
    // 6a) Sem UPDATE -> revoke bloqueado.
    $active_invite_id = $insert_invite_row('Convidado Revogavel', '', null, null);
    invSetInviteRight(READ | CREATE); // sem UPDATE
    invAssert(glpichat_can_update_invites($tickets_id) === false, 'Sem UPDATE de invite, can_update_invites deve ser false');
    $resp = $manage_ctrl->revoke(Request::create('/plugins/glpichat/Invite/Revoke', 'POST', [
        '_glpi_csrf_token' => Session::getNewCSRFToken(),
        'invite_id' => $active_invite_id,
    ]));
    invAssert($resp->getStatusCode() === 400, 'Revoke sem UPDATE deve retornar 400');
    $json = invJson($resp);
    invAssert(
        isset($json['error']) && str_contains((string) $json['error'], 'Você não tem permissão para gerenciar convites.'),
        'Revoke sem UPDATE deve usar a mensagem PT real - veio: ' . ($json['error'] ?? '(sem error)')
    );

    // 6b) Com UPDATE -> revoke permitido e marca revoked_at.
    invSetInviteRight(READ | CREATE | UPDATE);
    invAssert(glpichat_can_update_invites($tickets_id) === true, 'Com UPDATE de invite, can_update_invites deve ser true');
    $resp = $manage_ctrl->revoke(Request::create('/plugins/glpichat/Invite/Revoke', 'POST', [
        '_glpi_csrf_token' => Session::getNewCSRFToken(),
        'invite_id' => $active_invite_id,
    ]));
    invAssert($resp->getStatusCode() === 200, 'Revoke com UPDATE deve retornar 200');
    $json = invJson($resp);
    invAssert(($json['ok'] ?? false) === true, 'Revoke deve retornar ok=true');
    $revoked_row = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_invites',
        'WHERE' => ['id' => $active_invite_id],
        'LIMIT' => 1,
    ])->current();
    invAssert(is_array($revoked_row) && !empty($revoked_row['revoked_at']), 'Revoke deve marcar revoked_at no convite');
    invPass('Revoke: bloqueado sem UPDATE, permitido com UPDATE e marca revoked_at');

    // 6c) Reissue de convite revogado -> 'Convite revogado não pode ser reemitido.'
    $resp = $manage_ctrl->reissue(Request::create('/plugins/glpichat/Invite/Reissue', 'POST', [
        '_glpi_csrf_token' => Session::getNewCSRFToken(),
        'invite_id' => $active_invite_id,
    ]));
    invAssert($resp->getStatusCode() === 400, 'Reissue de convite revogado deve retornar 400');
    $json = invJson($resp);
    invAssert(
        isset($json['error']) && str_contains((string) $json['error'], 'Convite revogado não pode ser reemitido.'),
        'Reissue de revogado deve usar a mensagem PT real - veio: ' . ($json['error'] ?? '(sem error)')
    );

    // 6d) Reissue de convite aceito -> 'Convite já utilizado não pode ser reemitido.'
    $accepted_invite_id = $insert_invite_row('Convidado Aceito', '', date('Y-m-d H:i:s'), null);
    $resp = $manage_ctrl->reissue(Request::create('/plugins/glpichat/Invite/Reissue', 'POST', [
        '_glpi_csrf_token' => Session::getNewCSRFToken(),
        'invite_id' => $accepted_invite_id,
    ]));
    invAssert($resp->getStatusCode() === 400, 'Reissue de convite aceito deve retornar 400');
    $json = invJson($resp);
    invAssert(
        isset($json['error']) && str_contains((string) $json['error'], 'Convite já utilizado não pode ser reemitido.'),
        'Reissue de aceito deve usar a mensagem PT real - veio: ' . ($json['error'] ?? '(sem error)')
    );

    // 6e) Revoke de convite inexistente -> 'Convite não encontrado.'
    $resp = $manage_ctrl->revoke(Request::create('/plugins/glpichat/Invite/Revoke', 'POST', [
        '_glpi_csrf_token' => Session::getNewCSRFToken(),
        'invite_id' => 999999999,
    ]));
    invAssert($resp->getStatusCode() === 400, 'Revoke de convite inexistente deve retornar 400');
    $json = invJson($resp);
    invAssert(
        isset($json['error']) && str_contains((string) $json['error'], 'Convite não encontrado.'),
        'Revoke de inexistente deve usar a mensagem PT real - veio: ' . ($json['error'] ?? '(sem error)')
    );

    // 6f) Reissue de convite ativo (valido) -> sucesso (novo token + expires_at).
    $reissuable_id = $insert_invite_row('Convidado Reemitivel', '', null, null);
    $resp = $manage_ctrl->reissue(Request::create('/plugins/glpichat/Invite/Reissue', 'POST', [
        '_glpi_csrf_token' => Session::getNewCSRFToken(),
        'invite_id' => $reissuable_id,
    ]));
    invAssert($resp->getStatusCode() === 200, 'Reissue de convite ativo deve retornar 200');
    $json = invJson($resp);
    invAssert(isset($json['url']) && str_contains((string) $json['url'], '/plugins/glpichat/Accept/'), 'Reissue deve retornar a URL de aceite');
    invPass('Reissue: bloqueia revogado/aceito/inexistente e reemite convite ativo (mensagens PT reais)');

    // -----------------------------------------------------------------
    // 7) Estados derivados (glpichat_invite_status): ativo -> revogado / aceito
    // -----------------------------------------------------------------
    $now = time();
    $state_active_id = $insert_invite_row('Estado Ativo', '', null, null);
    $state_row = glpichat_find_invite_by_id($state_active_id);
    invAssert(glpichat_invite_status($state_row, $now) === 'active', 'Convite recem-criado deve ter status derivado "active"');

    // ativo -> revogado
    glpichat_mark_invite_revoked($state_active_id, date('Y-m-d H:i:s'));
    $state_row = glpichat_find_invite_by_id($state_active_id);
    invAssert(glpichat_invite_status($state_row, $now) === 'revoked', 'Apos revoke, status derivado deve ser "revoked"');

    // ativo -> aceito
    $state_accepted_id = $insert_invite_row('Estado Aceito', '', date('Y-m-d H:i:s'), null);
    $state_row = glpichat_find_invite_by_id($state_accepted_id);
    invAssert(glpichat_invite_status($state_row, $now) === 'accepted', 'Convite com accepted_at deve ter status derivado "accepted"');

    // expirado (expires_at no passado, sem accept/revoke)
    $state_expired_id = $insert_invite_row('Estado Expirado', '', null, null, -3600);
    $state_row = glpichat_find_invite_by_id($state_expired_id);
    invAssert(glpichat_invite_status($state_row, $now) === 'expired', 'Convite com expires_at no passado deve ter status derivado "expired"');
    invPass('Estados derivados do convite (active/revoked/accepted/expired) corretos a nivel de dados');

    fwrite(STDOUT, "[PASS] Smoke do formulario/fluxo de invite concluido\n");
} finally {
    if (isset($config_snapshot) && is_array($config_snapshot)) {
        Config::setConfigurationValues('plugin:glpichat', $config_snapshot);
    }

    foreach ($baseline as $table => $max_id) {
        $DB->delete($table, [['id' => ['>', $max_id]]]);
    }

    glpichat_clear_static_caches();

    unset($_SESSION['valid_id'], $_SESSION['glpiname'], $_SESSION['glpiID'], $_SESSION['glpi_currenttime']);
    unset($_SESSION['glpiactiveprofile'], $_SESSION['glpiactive_entity'], $_SESSION['glpigroups']);
    unset($_SESSION['glpicsrftokens']);
}
