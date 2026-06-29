<?php

declare(strict_types=1);

// Smoke (redline de seguranca): HookHandler::filterInternalMessageTargets
//
// Garante que, no evento glpichat_internal_message, um destinatario que NAO
// possui plugin_glpichat_internal READ e removido da lista $target->target
// (evitando vazamento de mensagem interna por e-mail). Destinatario COM o
// direito permanece. Eventos != interno e recipients que nao sejam User sao
// no-op.
//
// Usa Profile::haveUserRight (consulta o banco) com usuarios REAIS: 'glpi'
// (perfil admin-like -> tem o direito) e 'self' (perfil helpdesk -> NAO tem o
// direito interno, por glpichat_default_right_mask). NAO e destrutivo: nao
// escreve no banco; apenas le direitos e manipula um objeto em memoria.

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
function tgtFail(string $message): void
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function tgtPass(string $message): void
{
    fwrite(STDOUT, "[PASS] {$message}\n");
}

function tgtNote(string $message): void
{
    fwrite(STDOUT, "[NOTE] {$message}\n");
}

function tgtAssert(bool $condition, string $message): void
{
    if (!$condition) {
        tgtFail($message);
    }
}

function tgtUserId(string $name): int
{
    global $DB;
    $row = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => 'glpi_users',
        'WHERE'  => ['name' => $name, 'is_deleted' => 0],
        'LIMIT'  => 1,
    ])->current();
    return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
}

/**
 * Monta um NotificationTarget minimo para exercitar o filtro. Define entidade,
 * evento, recipient corrente e a lista de alvos (chaveada por e-mail).
 *
 * @param array<string, array<string, mixed>> $target_list
 * @param array<string, mixed> $recipient
 */
function tgtMakeTarget(string $event, array $recipient, array $target_list, int $entity_id): \NotificationTargetTicket
{
    $target = new \NotificationTargetTicket();
    $target->raiseevent = $event;
    $target->recipient_data = $recipient;
    $target->target = $target_list;
    // getEntity() retorna $this->entity; fixamos na entidade raiz (rights
    // recursivos do perfil admin cobrem a raiz).
    $target->entity = $entity_id;
    return $target;
}

global $DB;

$entity_id = 0;
$glpi_id = tgtUserId('glpi');
$self_id = tgtUserId('self');

tgtAssert($glpi_id > 0, "Usuario 'glpi' deve existir para o teste de filtro de destinatarios");
tgtAssert($self_id > 0, "Usuario 'self' deve existir para o teste de filtro de destinatarios");

// Pre-condicao: confirma a matriz de direitos real no banco.
$glpi_has_internal = Profile::haveUserRight($glpi_id, 'plugin_glpichat_internal', READ, $entity_id);
$self_has_internal = Profile::haveUserRight($self_id, 'plugin_glpichat_internal', READ, $entity_id);
tgtAssert($glpi_has_internal, "Pre-condicao: 'glpi' deve possuir plugin_glpichat_internal READ");
tgtAssert(!$self_has_internal, "Pre-condicao: 'self' NAO deve possuir plugin_glpichat_internal READ");
tgtPass('Matriz de direitos real confirmada (glpi=interno, self=sem interno)');

$self_email = 'self@example.test';
$glpi_email = 'glpi@example.test';

// -----------------------------------------------------------------
// 1) Evento interno + recipient 'self' (sem direito) -> self REMOVIDO,
//    glpi PERMANECE.
// -----------------------------------------------------------------
$target = tgtMakeTarget(
    'glpichat_internal_message',
    ['itemtype' => \User::class, 'items_id' => $self_id],
    [
        $self_email => ['users_id' => $self_id, 'email' => $self_email],
        $glpi_email => ['users_id' => $glpi_id, 'email' => $glpi_email],
    ],
    $entity_id
);
HookHandler::filterInternalMessageTargets($target);
tgtAssert(!isset($target->target[$self_email]), "Destinatario sem direito interno deve ser REMOVIDO do target");
tgtAssert(isset($target->target[$glpi_email]), "Destinatario com direito interno NAO deve ser removido");
tgtPass('Evento interno: destinatario sem plugin_glpichat_internal READ e removido; com direito permanece');

// -----------------------------------------------------------------
// 2) Evento interno + recipient 'glpi' (com direito) -> nada removido.
// -----------------------------------------------------------------
$target = tgtMakeTarget(
    'glpichat_internal_message',
    ['itemtype' => \User::class, 'items_id' => $glpi_id],
    [
        $glpi_email => ['users_id' => $glpi_id, 'email' => $glpi_email],
    ],
    $entity_id
);
HookHandler::filterInternalMessageTargets($target);
tgtAssert(isset($target->target[$glpi_email]), "Destinatario com direito interno deve permanecer no target");
tgtPass('Evento interno: destinatario com direito interno permanece intacto');

// -----------------------------------------------------------------
// 3) Evento != interno (ex.: publico) -> no-op mesmo para 'self'.
// -----------------------------------------------------------------
$target = tgtMakeTarget(
    'glpichat_public_message',
    ['itemtype' => \User::class, 'items_id' => $self_id],
    [
        $self_email => ['users_id' => $self_id, 'email' => $self_email],
    ],
    $entity_id
);
HookHandler::filterInternalMessageTargets($target);
tgtAssert(isset($target->target[$self_email]), "Em evento nao-interno, nenhum destinatario deve ser removido");
tgtPass('Evento nao-interno: filtro e no-op (mensagem publica nao filtra destinatarios)');

// -----------------------------------------------------------------
// 4) Recipient que nao e User (ex.: Group) -> no-op.
// -----------------------------------------------------------------
$target = tgtMakeTarget(
    'glpichat_internal_message',
    ['itemtype' => 'Group', 'items_id' => 5],
    [
        $self_email => ['users_id' => $self_id, 'email' => $self_email],
    ],
    $entity_id
);
HookHandler::filterInternalMessageTargets($target);
tgtAssert(isset($target->target[$self_email]), "Recipient nao-User nao deve disparar remocao");
tgtPass('Recipient nao-User: filtro e no-op');

fwrite(STDOUT, "[PASS] Smoke de filterInternalMessageTargets concluido\n");
