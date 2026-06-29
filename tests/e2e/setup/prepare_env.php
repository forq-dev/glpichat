<?php

declare(strict_types=1);

/**
 * Esteira glpichat - preparacao de ambiente.
 *
 * Abre UM ticket dedicado a suite completa, com:
 *  - solicitante  = usuario "self"  (REQUESTER / type 1)
 *  - tecnico       = usuario "glpi"  (ASSIGN    / type 2)
 *  - status        = 2 (em atendimento / assigned), nao-terminal
 *
 * Garante as duas salas do plugin (public + internal) e imprime, em stdout,
 * linhas parseaveis com os IDs resolvidos para que a esteira exporte para os
 * demais passos:
 *   GLPICHAT_TICKET_ID=<id>
 *   GLPICHAT_SELF_ID=<id>
 *   GLPICHAT_GLPI_ID=<id>
 *
 * Rodado no container via:
 *   docker exec glpi php /var/www/glpi/plugins/glpichat/tests/e2e/setup/prepare_env.php
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

/**
 * @return never
 */
function prepFail(string $message): void
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function prepInfo(string $message): void
{
    fwrite(STDOUT, "[INFO] {$message}\n");
}

function prepPass(string $message): void
{
    fwrite(STDOUT, "[PASS] {$message}\n");
}

/**
 * Resolve o id de um usuario pelo name (login).
 */
function prepUserId(string $name): int
{
    global $DB;

    $row = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => 'glpi_users',
        'WHERE'  => ['name' => $name],
        'LIMIT'  => 1,
    ])->current();

    return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
}

/**
 * Semeia uma sessao de admin valida usando o usuario "glpi" (ou, em fallback,
 * qualquer usuario ativo com profile) para que o Ticket::add funcione com
 * direitos reais. Espelha task3SeedValidSession / userPresenceSeedValidSession.
 */
function prepSeedAdminSession(): void
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
            'glpi_users.name'                 => 'glpi',
            'glpi_users.is_active'            => 1,
            'glpi_users.is_deleted'           => 0,
            'glpi_profiles_users.profiles_id' => ['>', 0],
        ],
        'LIMIT' => 1,
    ])->current();

    if (!is_array($row)) {
        // Fallback: qualquer usuario ativo com profile.
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
    }

    if (!is_array($row)) {
        prepFail('Sessao de admin precisa de um usuario GLPI ativo com profile');
    }

    $_SESSION['valid_id'] = session_id();
    $_SESSION['glpiname'] = (string) $row['user_name'];
    $_SESSION['glpiID']   = (int) $row['users_id'];

    // Direitos reais para conseguir criar Ticket.
    Session::initEntityProfiles((int) $row['users_id']);
    Session::changeProfile((int) $row['profiles_id']);

    $_SESSION['glpiactive_entity'] = (int) ($row['entities_id'] ?? 0);
    if (!isset($_SESSION['glpiactiveprofile']) || !is_array($_SESSION['glpiactiveprofile'])) {
        $_SESSION['glpiactiveprofile'] = [
            'id'                 => (int) $row['profiles_id'],
            'last_rights_update' => $row['last_rights_update'],
        ];
    }
    $_SESSION['glpigroups'] = $_SESSION['glpigroups'] ?? [];
    $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
}

/**
 * Confere se ja existe um vinculo (tickets_users) com o type informado.
 */
function prepTicketHasActor(int $tickets_id, int $users_id, int $type): bool
{
    global $DB;

    $row = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => 'glpi_tickets_users',
        'WHERE'  => [
            'tickets_id' => $tickets_id,
            'users_id'   => $users_id,
            'type'       => $type,
        ],
        'LIMIT'  => 1,
    ])->current();

    return is_array($row);
}

global $DB;

prepSeedAdminSession();

$self_id = prepUserId('self');
$glpi_id = prepUserId('glpi');

if ($self_id <= 0) {
    prepFail('Usuario "self" (solicitante) nao encontrado em glpi_users');
}
if ($glpi_id <= 0) {
    prepFail('Usuario "glpi" (tecnico) nao encontrado em glpi_users');
}
prepPass("IDs resolvidos: self={$self_id} glpi={$glpi_id}");

// O fluxo do convidado (Accept/Send) cria followups no chamado usando o "usuario de
// followup externo" configurado. Se essa config nao apontar para um usuario valido,
// glpichat_external_followup_user_id() lanca excecao e o Accept falha com 400 (sessao
// de convidado nunca e criada). A preparacao do ambiente garante um usuario valido
// (o proprio tecnico glpi) para que o fluxo do convidado funcione de ponta a ponta.
$current_followup = (int) Config::getConfigurationValue('plugin:glpichat', 'external_followup_user_id');
if ($current_followup <= 0 || !glpichat_is_valid_external_followup_user_id($current_followup)) {
    Config::setConfigurationValues('plugin:glpichat', [
        'external_followup_user_id' => (string) $glpi_id,
    ]);
    prepInfo("external_followup_user_id configurado para glpi={$glpi_id} (necessario p/ fluxo do convidado)");
} else {
    prepInfo("external_followup_user_id ja configurado (={$current_followup})");
}

$timestamp   = date('Y-m-d H:i:s');
$ticket_name = 'GLPICHAT SUITE ' . $timestamp;

$ticket = new Ticket();
$tickets_id = (int) $ticket->add([
    'name'                => $ticket_name,
    'content'             => 'Ticket dedicado a esteira automatizada do plugin glpichat. Criado em ' . $timestamp . '. Sera removido no teardown.',
    'status'              => 2, // assigned / em atendimento (nao-terminal)
    '_users_id_requester' => $self_id,
    '_users_id_assign'    => $glpi_id,
    'entities_id'         => (int) ($_SESSION['glpiactive_entity'] ?? 0),
]);

if ($tickets_id <= 0) {
    prepFail('Ticket::add nao retornou um id valido');
}
prepPass("Ticket criado: id={$tickets_id}");

// Fallback de atores: se o add nao vinculou os actors, inserir manualmente.
// REQUESTER=1, ASSIGN=2 conforme CommonITILActor.
$REQUESTER = class_exists('CommonITILActor') ? CommonITILActor::REQUESTER : 1;
$ASSIGN    = class_exists('CommonITILActor') ? CommonITILActor::ASSIGN : 2;

if (!prepTicketHasActor($tickets_id, $self_id, $REQUESTER)) {
    $DB->insert('glpi_tickets_users', [
        'tickets_id' => $tickets_id,
        'users_id'   => $self_id,
        'type'       => $REQUESTER,
    ]);
    prepInfo("Fallback: vinculo REQUESTER (self={$self_id}) inserido manualmente");
}
if (!prepTicketHasActor($tickets_id, $glpi_id, $ASSIGN)) {
    $DB->insert('glpi_tickets_users', [
        'tickets_id' => $tickets_id,
        'users_id'   => $glpi_id,
        'type'       => $ASSIGN,
    ]);
    prepInfo("Fallback: vinculo ASSIGN (glpi={$glpi_id}) inserido manualmente");
}

// Garantir status=2 (add do GLPI pode normalizar o status conforme atores).
$DB->update('glpi_tickets', ['status' => 2], ['id' => $tickets_id]);

// Confirmacao final.
$snapshot = $DB->request([
    'SELECT' => ['id', 'status'],
    'FROM'   => 'glpi_tickets',
    'WHERE'  => ['id' => $tickets_id],
    'LIMIT'  => 1,
])->current();

if (!is_array($snapshot)) {
    prepFail("Ticket {$tickets_id} nao encontrado apos criacao");
}
if ((int) $snapshot['status'] !== 2) {
    prepFail("Ticket {$tickets_id} nao esta em status=2 (status atual=" . (int) $snapshot['status'] . ')');
}

if (!prepTicketHasActor($tickets_id, $self_id, $REQUESTER)) {
    prepFail("Ticket {$tickets_id} nao possui requester=self");
}
if (!prepTicketHasActor($tickets_id, $glpi_id, $ASSIGN)) {
    prepFail("Ticket {$tickets_id} nao possui assign=glpi");
}
prepPass("Ticket {$tickets_id} confirmado: status=2, requester=self, assign=glpi");

// Garantir as duas salas do plugin.
$public_room_id   = glpichat_room_id($tickets_id, GLPICHAT_ROOM_PUBLIC);
$internal_room_id = glpichat_room_id($tickets_id, GLPICHAT_ROOM_INTERNAL);

if ($public_room_id <= 0 || $internal_room_id <= 0) {
    prepFail("Falha ao garantir as salas do plugin (public={$public_room_id} internal={$internal_room_id})");
}
prepPass("Salas garantidas: public={$public_room_id} internal={$internal_room_id}");

// Linhas parseaveis para a esteira.
fwrite(STDOUT, "GLPICHAT_TICKET_ID={$tickets_id}\n");
fwrite(STDOUT, "GLPICHAT_SELF_ID={$self_id}\n");
fwrite(STDOUT, "GLPICHAT_GLPI_ID={$glpi_id}\n");

fwrite(STDOUT, "[PASS] Ambiente da esteira preparado\n");
exit(0);
