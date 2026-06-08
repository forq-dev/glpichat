<?php

define('GLPI_ROOT', dirname(__DIR__, 3));
include (GLPI_ROOT . "/inc/includes.php");

Session::checkRight("plugin_glpichat_admin", READ);

Html::header("GLPI Chat", $_SERVER['PHP_SELF'], "config", "plugins");

if (isset($_POST['update'])) {
    Session::checkRight("plugin_glpichat_admin", UPDATE);

    $valid_positions = ['bottom-right', 'bottom-left', 'top-left', 'top-right'];

    Config::setConfigurationValues('plugin:glpichat', [
        // Bloco A — Polling & Sincronização
        'poll_focused_ms'              => (string) max(1000, min(30000, (int)$_POST['poll_focused_ms'])),
        'poll_idle_ms'                 => (string) max(3000, min(60000, (int)$_POST['poll_idle_ms'])),
        'poll_bg_ms'                   => (string) max(5000, min(120000, (int)$_POST['poll_bg_ms'])),
        'guest_poll_ms'                => (string) max(2000, min(30000, (int)$_POST['guest_poll_ms'])),
        'meta_sync_ms'                 => (string) max(5000, min(120000, (int)$_POST['meta_sync_ms'])),
        'invite_poll_ms'               => (string) max(3000, min(60000, (int)$_POST['invite_poll_ms'])),
        'poll_max_channels'            => (string) max(1, min(50, (int)$_POST['poll_max_channels'])),
        'user_inactivity_timeout_s'    => (string) max(30, min(600, (int)$_POST['user_inactivity_timeout_s'])),
        // Bloco B — Sessão & Segurança
        'guest_session_ttl'            => (string) max(60, min(86400, (int)$_POST['guest_session_ttl'])),
        'invite_ttl_seconds'           => (string) max(300, min(259200, (int)$_POST['invite_ttl_seconds'])),
        'guest_send_throttle_s'        => (string) max(1, min(30, (int)$_POST['guest_send_throttle_s'])),
        'accept_rate_limit_window_s'   => (string) max(30, min(300, (int)$_POST['accept_rate_limit_window_s'])),
        'accept_rate_limit_max_attempts' => (string) max(3, min(100, (int)$_POST['accept_rate_limit_max_attempts'])),
        'max_invites_per_ticket'       => (string) max(1, min(50, (int)$_POST['max_invites_per_ticket'])),
        // Bloco C — Interface & UX
        'desktop_max_open'             => (string) max(1, min(10, (int)$_POST['desktop_max_open'])),
        'widget_default_position'      => in_array($_POST['widget_default_position'] ?? '', $valid_positions, true) ? $_POST['widget_default_position'] : 'bottom-right',
        'typing_indicator_timeout_ms'  => (string) max(1000, min(10000, (int)$_POST['typing_indicator_timeout_ms'])),
        'auto_open_ticket_chat'        => (string)(int)(bool)($_POST['auto_open_ticket_chat'] ?? '0'),
        'auto_scroll_threshold_px'     => (string) max(50, min(500, (int)$_POST['auto_scroll_threshold_px'])),
        'unread_badge_threshold'       => (string) max(1, min(10, (int)$_POST['unread_badge_threshold'])),
        // Bloco D — Comportamento do Chat
        'history_page_default'         => (string) max(10, min(100, (int)$_POST['history_page_default'])),
        'history_page_max'             => (string) max(10, min(200, (int)$_POST['history_page_max'])),
        // Bloco E — Limpeza & Retenção
        'events_retention_days'        => (string) max(7, min(365, (int)$_POST['events_retention_days'])),
        'messages_retention_days'      => (string) max(30, min(1825, (int)$_POST['messages_retention_days'])),
        'auditlogs_retention_days'     => (string) max(30, min(730, (int)$_POST['auditlogs_retention_days'])),
        // Outros
        'external_followup_user_id'    => (string)(int)$_POST['external_followup_user_id'],
    ]);
    Html::back();
}

// Helper to read config with default
$cfg = static function (string $key, mixed $default): mixed {
    $v = Config::getConfigurationValue('plugin:glpichat', $key);
    if ($v === null || $v === '' || $v === false) {
        return $default;
    }
    return $v;
};

// Bloco A
$poll_focused_ms           = max(1000, min(30000,   (int)$cfg('poll_focused_ms', 2500)));
$poll_idle_ms              = max(3000, min(60000,   (int)$cfg('poll_idle_ms', 9000)));
$poll_bg_ms                = max(5000, min(120000,  (int)$cfg('poll_bg_ms', 18000)));
$guest_poll_ms             = max(2000, min(30000,   (int)$cfg('guest_poll_ms', 5000)));
$meta_sync_ms              = max(5000, min(120000,  (int)$cfg('meta_sync_ms', 15000)));
$invite_poll_ms            = max(3000, min(60000,   (int)$cfg('invite_poll_ms', 10000)));
$poll_max_channels         = max(1,    min(50,      (int)$cfg('poll_max_channels', 20)));
$user_inactivity_timeout_s = max(30,   min(600,     (int)$cfg('user_inactivity_timeout_s', 120)));

// Bloco B
$guest_session_ttl              = max(60,  min(86400,   (int)$cfg('guest_session_ttl', 3600)));
$invite_ttl_seconds             = max(300, min(259200,  (int)$cfg('invite_ttl_seconds', 3600)));
$guest_send_throttle_s          = max(1,   min(30,      (int)$cfg('guest_send_throttle_s', 2)));
$accept_rate_limit_window_s     = max(30,  min(300,     (int)$cfg('accept_rate_limit_window_s', 60)));
$accept_rate_limit_max_attempts = max(3,   min(100,     (int)$cfg('accept_rate_limit_max_attempts', 10)));
$max_invites_per_ticket         = max(1,   min(50,      (int)$cfg('max_invites_per_ticket', 10)));

// Bloco C
$valid_positions         = ['bottom-right', 'bottom-left', 'top-left', 'top-right'];
$raw_pos                 = (string)$cfg('widget_default_position', 'bottom-right');
$widget_default_position = in_array($raw_pos, $valid_positions, true) ? $raw_pos : 'bottom-right';
$desktop_max_open           = max(1,    min(10,     (int)$cfg('desktop_max_open', 3)));
$typing_indicator_timeout_ms = max(1000, min(10000, (int)$cfg('typing_indicator_timeout_ms', 3000)));
$auto_open_ticket_chat       = (bool)(int)$cfg('auto_open_ticket_chat', '1');
$auto_scroll_threshold_px    = max(50,  min(500,    (int)$cfg('auto_scroll_threshold_px', 160)));
$unread_badge_threshold      = max(1,   min(10,     (int)$cfg('unread_badge_threshold', 2)));

// Bloco D
$history_page_default = max(10, min(100,  (int)$cfg('history_page_default', 30)));
$history_page_max     = max(10, min(200,  (int)$cfg('history_page_max', 50)));

// Bloco E
$events_retention_days   = max(7,  min(365,  (int)$cfg('events_retention_days', 30)));
$messages_retention_days = max(30, min(1825, (int)$cfg('messages_retention_days', 365)));
$auditlogs_retention_days = max(30, min(730, (int)$cfg('auditlogs_retention_days', 180)));

// Outros
$external_followup_user_id = (int)$cfg('external_followup_user_id', 0);

echo "<div class='container' style='max-width: 900px; margin-top: 20px;'>";
echo "<form method='post' action='" . htmlspecialchars($_SERVER['PHP_SELF']) . "'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

// ── Bloco A — Polling & Sincronização ──────────────────────────────────────
echo "<div class='card mb-4'>";
echo "  <div class='card-header'><strong>" . __("Bloco A — Polling & Sincronização", "glpichat") . "</strong></div>";
echo "  <div class='card-body'>";

$fields_a = [
    ['poll_focused_ms',        $poll_focused_ms,           __("Poll em foco (ms)", "glpichat"),                    __("Intervalo de polling quando a aba está ativa e em foco.", "glpichat"),            1000, 30000],
    ['poll_idle_ms',           $poll_idle_ms,              __("Poll em idle (ms)", "glpichat"),                    __("Intervalo de polling quando a aba está visível mas sem foco.", "glpichat"),       3000, 60000],
    ['poll_bg_ms',             $poll_bg_ms,                __("Poll em background (ms)", "glpichat"),              __("Intervalo de polling quando a aba está em background/oculta.", "glpichat"),      5000, 120000],
    ['guest_poll_ms',          $guest_poll_ms,             __("Poll de convidados (ms)", "glpichat"),              __("Intervalo de polling para a interface do convidado externo.", "glpichat"),       2000, 30000],
    ['meta_sync_ms',           $meta_sync_ms,              __("Sincronização de metadados (ms)", "glpichat"),      __("Intervalo de sincronização de metadados do widget.", "glpichat"),               5000, 120000],
    ['invite_poll_ms',         $invite_poll_ms,            __("Poll de gerenciamento de convites (ms)", "glpichat"), __("Intervalo de atualização da tabela de gestão de convites.", "glpichat"),     3000, 60000],
    ['poll_max_channels',      $poll_max_channels,         __("Máximo de canais por poll", "glpichat"),            __("Limite de canais processados por requisição de polling.", "glpichat"),          1, 50],
    ['user_inactivity_timeout_s', $user_inactivity_timeout_s, __("Timeout de inatividade de usuário (s)", "glpichat"), __("Segundos sem atividade antes de remover participante da sala.", "glpichat"), 30, 600],
];
foreach ($fields_a as [$name, $value, $label, $help, $min, $max]) {
    echo "    <div class='mb-3'>";
    echo "      <label class='form-label' for='" . htmlspecialchars($name) . "'>" . htmlspecialchars($label) . "</label>";
    echo "      <input type='number' min='{$min}' max='{$max}' name='" . htmlspecialchars($name) . "' id='" . htmlspecialchars($name) . "' class='form-control' value='" . (int)$value . "' required>";
    echo "      <div class='form-text text-muted'>" . htmlspecialchars($help) . "</div>";
    echo "    </div>";
}

echo "  </div>";
echo "</div>";

// ── Bloco B — Sessão & Segurança ──────────────────────────────────────────
echo "<div class='card mb-4'>";
echo "  <div class='card-header'><strong>" . __("Bloco B — Sessão & Segurança", "glpichat") . "</strong></div>";
echo "  <div class='card-body'>";

$fields_b = [
    ['guest_session_ttl',              $guest_session_ttl,              __("TTL da sessão de convidados (s)", "glpichat"),           __("Tempo que a sessão do convidado externo permanece ativa sem interações.", "glpichat"),    60, 86400],
    ['invite_ttl_seconds',             $invite_ttl_seconds,             __("TTL do convite (s)", "glpichat"),                        __("Validade do link de convite gerado.", "glpichat"),                                         300, 259200],
    ['guest_send_throttle_s',          $guest_send_throttle_s,          __("Throttle de envio por convidado (s)", "glpichat"),       __("Intervalo mínimo entre mensagens enviadas por um convidado.", "glpichat"),                 1, 30],
    ['accept_rate_limit_window_s',     $accept_rate_limit_window_s,     __("Janela do rate limit de aceitação (s)", "glpichat"),     __("Janela de tempo para contagem de tentativas de aceitar convite.", "glpichat"),             30, 300],
    ['accept_rate_limit_max_attempts', $accept_rate_limit_max_attempts, __("Máximo de tentativas de aceitação", "glpichat"),        __("Número máximo de tentativas de aceitar convite na janela configurada.", "glpichat"),       3, 100],
    ['max_invites_per_ticket',         $max_invites_per_ticket,         __("Máximo de convites ativos por chamado", "glpichat"),     __("Limite de convites ativos simultâneos por chamado.", "glpichat"),                          1, 50],
];
foreach ($fields_b as [$name, $value, $label, $help, $min, $max]) {
    echo "    <div class='mb-3'>";
    echo "      <label class='form-label' for='" . htmlspecialchars($name) . "'>" . htmlspecialchars($label) . "</label>";
    echo "      <input type='number' min='{$min}' max='{$max}' name='" . htmlspecialchars($name) . "' id='" . htmlspecialchars($name) . "' class='form-control' value='" . (int)$value . "' required>";
    echo "      <div class='form-text text-muted'>" . htmlspecialchars($help) . "</div>";
    echo "    </div>";
}

echo "  </div>";
echo "</div>";

// ── Bloco C — Interface & UX ──────────────────────────────────────────────
echo "<div class='card mb-4'>";
echo "  <div class='card-header'><strong>" . __("Bloco C — Interface & UX", "glpichat") . "</strong></div>";
echo "  <div class='card-body'>";

echo "    <div class='mb-3'>";
echo "      <label class='form-label' for='desktop_max_open'>" . __("Máximo de chats abertos no desktop", "glpichat") . "</label>";
echo "      <input type='number' min='1' max='10' name='desktop_max_open' id='desktop_max_open' class='form-control' value='" . (int)$desktop_max_open . "' required>";
echo "      <div class='form-text text-muted'>" . __("Número máximo de janelas de chat abertas simultaneamente no widget desktop.", "glpichat") . "</div>";
echo "    </div>";

echo "    <div class='mb-3'>";
echo "      <label class='form-label' for='widget_default_position'>" . __("Posição padrão do widget", "glpichat") . "</label>";
echo "      <select name='widget_default_position' id='widget_default_position' class='form-control'>";
foreach ($valid_positions as $pos) {
    $selected = $widget_default_position === $pos ? " selected" : "";
    echo "        <option value='" . htmlspecialchars($pos) . "'{$selected}>" . htmlspecialchars($pos) . "</option>";
}
echo "      </select>";
echo "      <div class='form-text text-muted'>" . __("Canto onde o widget de chat será posicionado por padrão.", "glpichat") . "</div>";
echo "    </div>";

echo "    <div class='mb-3'>";
echo "      <label class='form-label' for='typing_indicator_timeout_ms'>" . __("Timeout do indicador de digitação (ms)", "glpichat") . "</label>";
echo "      <input type='number' min='1000' max='10000' name='typing_indicator_timeout_ms' id='typing_indicator_timeout_ms' class='form-control' value='" . (int)$typing_indicator_timeout_ms . "' required>";
echo "      <div class='form-text text-muted'>" . __("Tempo que o indicador 'está digitando...' permanece visível após o último evento de digitação.", "glpichat") . "</div>";
echo "    </div>";

echo "    <div class='mb-3'>";
echo "      <div class='form-check'>";
echo "        <input type='checkbox' name='auto_open_ticket_chat' id='auto_open_ticket_chat' class='form-check-input' value='1'" . ($auto_open_ticket_chat ? " checked" : "") . ">";
echo "        <label class='form-check-label' for='auto_open_ticket_chat'>" . __("Abrir chat automaticamente ao entrar no chamado", "glpichat") . "</label>";
echo "      </div>";
echo "      <div class='form-text text-muted'>" . __("Quando ativado, o painel de chat é aberto automaticamente ao visualizar um chamado.", "glpichat") . "</div>";
echo "    </div>";

echo "    <div class='mb-3'>";
echo "      <label class='form-label' for='auto_scroll_threshold_px'>" . __("Threshold de auto-scroll (px)", "glpichat") . "</label>";
echo "      <input type='number' min='50' max='500' name='auto_scroll_threshold_px' id='auto_scroll_threshold_px' class='form-control' value='" . (int)$auto_scroll_threshold_px . "' required>";
echo "      <div class='form-text text-muted'>" . __("Distância em pixels do fim da lista de mensagens para acionar o scroll automático.", "glpichat") . "</div>";
echo "    </div>";

echo "    <div class='mb-3'>";
echo "      <label class='form-label' for='unread_badge_threshold'>" . __("Threshold do badge de não lidos", "glpichat") . "</label>";
echo "      <input type='number' min='1' max='10' name='unread_badge_threshold' id='unread_badge_threshold' class='form-control' value='" . (int)$unread_badge_threshold . "' required>";
echo "      <div class='form-text text-muted'>" . __("Número mínimo de mensagens não lidas para exibir o badge de contagem.", "glpichat") . "</div>";
echo "    </div>";

echo "  </div>";
echo "</div>";

// ── Bloco D — Comportamento do Chat ──────────────────────────────────────
echo "<div class='card mb-4'>";
echo "  <div class='card-header'><strong>" . __("Bloco D — Comportamento do Chat", "glpichat") . "</strong></div>";
echo "  <div class='card-body'>";

echo "    <div class='mb-3'>";
echo "      <label class='form-label' for='history_page_default'>" . __("Mensagens por página (padrão)", "glpichat") . "</label>";
echo "      <input type='number' min='10' max='100' name='history_page_default' id='history_page_default' class='form-control' value='" . (int)$history_page_default . "' required>";
echo "      <div class='form-text text-muted'>" . __("Quantidade padrão de mensagens carregadas ao abrir o histórico.", "glpichat") . "</div>";
echo "    </div>";

echo "    <div class='mb-3'>";
echo "      <label class='form-label' for='history_page_max'>" . __("Mensagens por página (máximo)", "glpichat") . "</label>";
echo "      <input type='number' min='10' max='200' name='history_page_max' id='history_page_max' class='form-control' value='" . (int)$history_page_max . "' required>";
echo "      <div class='form-text text-muted'>" . __("Limite máximo de mensagens que podem ser solicitadas por página.", "glpichat") . "</div>";
echo "    </div>";

echo "  </div>";
echo "</div>";

// ── Bloco E — Limpeza & Retenção ─────────────────────────────────────────
echo "<div class='card mb-4'>";
echo "  <div class='card-header'><strong>" . __("Bloco E — Limpeza & Retenção", "glpichat") . "</strong></div>";
echo "  <div class='card-body'>";

$fields_e = [
    ['events_retention_days',   $events_retention_days,   __("Retenção de eventos (dias)", "glpichat"),    __("Eventos de chat mais antigos que este valor serão removidos pelo cron.", "glpichat"),     7, 365],
    ['messages_retention_days', $messages_retention_days, __("Retenção de mensagens (dias)", "glpichat"),  __("Mensagens mais antigas que este valor serão removidas pelo cron.", "glpichat"),          30, 1825],
    ['auditlogs_retention_days',$auditlogs_retention_days,__("Retenção de auditoria (dias)", "glpichat"),  __("Registros de auditoria mais antigos que este valor serão removidos pelo cron.", "glpichat"), 30, 730],
];
foreach ($fields_e as [$name, $value, $label, $help, $min, $max]) {
    echo "    <div class='mb-3'>";
    echo "      <label class='form-label' for='" . htmlspecialchars($name) . "'>" . htmlspecialchars($label) . "</label>";
    echo "      <input type='number' min='{$min}' max='{$max}' name='" . htmlspecialchars($name) . "' id='" . htmlspecialchars($name) . "' class='form-control' value='" . (int)$value . "' required>";
    echo "      <div class='form-text text-muted'>" . htmlspecialchars($help) . "</div>";
    echo "    </div>";
}

echo "  </div>";
echo "</div>";

// ── Outros ────────────────────────────────────────────────────────────────
echo "<div class='card mb-4'>";
echo "  <div class='card-header'><strong>" . __("Outros", "glpichat") . "</strong></div>";
echo "  <div class='card-body'>";

echo "    <div class='mb-3'>";
echo "      <label class='form-label' for='dropdown_external_followup_user_id'>" . __("Usuário do GLPI associado a mensagens de convidados externos", "glpichat") . "</label>";
User::dropdown([
    'name' => 'external_followup_user_id',
    'value' => $external_followup_user_id,
    'width' => '100%',
]);
echo "      <div class='form-text text-muted'>" . __("As ações no chat e followups inseridos por convidados externos serão associadas a este usuário do GLPI.", "glpichat") . "</div>";
echo "    </div>";

echo "  </div>";
echo "  <div class='card-footer text-end'>";
if (Session::haveRight("plugin_glpichat_admin", UPDATE)) {
    echo "    <input type='submit' name='update' class='btn btn-primary' value='" . __("Salvar") . "'>";
}
echo "  </div>";
echo "</div>";

echo "</form>";
echo "</div>"; // container

Html::footer();
