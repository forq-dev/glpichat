<?php

define('GLPI_ROOT', dirname(__DIR__, 3));
include (GLPI_ROOT . "/inc/includes.php");

Session::checkRight("plugin_glpichat_admin", READ);

Html::header("GLPI Chat", $_SERVER['PHP_SELF'], "config", "plugins");

if (isset($_POST['update'])) {
    Session::checkRight("plugin_glpichat_admin", UPDATE);

    $valid_positions = ['bottom-right', 'bottom-left', 'top-left', 'top-right'];

    // Validate the external followup user: 0/empty means "not set" (allowed and
    // stored as 0); any other value must reference an existing, non-deleted user.
    $external_followup_user_id = (int) ($_POST['external_followup_user_id'] ?? 0);
    if (!glpichat_is_valid_external_followup_user_id($external_followup_user_id)) {
        Session::addMessageAfterRedirect(
            __('O usuário selecionado para mensagens de convidados externos não existe.', 'glpichat'),
            false,
            ERROR
        );
        Html::back();
    }

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
        'external_followup_user_id'    => (string) $external_followup_user_id,
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

echo "<div class='container-xl py-3'>";
// $_SERVER['PHP_SELF'] resolves to '/index.php' under GLPI's Symfony-based legacy
// file loader (the actual script path is only known to the routing layer, not to
// this file's own runtime scope), so it cannot be used to build the form action.
// REQUEST_URI reflects the URL the browser actually requested and is safe here.
$form_action = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
echo "<form method='post' action='" . htmlspecialchars($form_action) . "' id='glpichat-config-form'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
// Garante que 'update' esteja sempre presente no POST, mesmo quando o submit
// e disparado via JS (form.requestSubmit() sem um submitter nao inclui o
// name=value do botao que originou a interceptacao, ex.: confirmacao do modal
// de reducao de retencao).
echo Html::hidden('update', ['value' => '1']);

// ── Nav Tabs ──────────────────────────────────────────────────────────────
echo "<ul class='nav nav-tabs mb-3' id='glpichat-config-tabs' role='tablist'>";

$tabs = [
    ['id' => 'tab-sync',       'label' => __('Sincronização', 'glpichat'),  'icon' => 'ti ti-refresh'],
    ['id' => 'tab-seguranca',  'label' => __('Segurança', 'glpichat'),      'icon' => 'ti ti-shield-lock'],
    ['id' => 'tab-interface',  'label' => __('Interface', 'glpichat'),      'icon' => 'ti ti-adjustments'],
    ['id' => 'tab-chat',       'label' => __('Chat', 'glpichat'),           'icon' => 'ti ti-message-circle'],
    ['id' => 'tab-retencao',   'label' => __('Retenção', 'glpichat'),       'icon' => 'ti ti-trash'],
    ['id' => 'tab-integracao', 'label' => __('Integração', 'glpichat'),     'icon' => 'ti ti-plug'],
];

foreach ($tabs as $i => $tab) {
    $active_class = $i === 0 ? ' active' : '';
    echo "<li class='nav-item' role='presentation'>";
    echo "  <a class='nav-link{$active_class}' id='{$tab['id']}-link' data-bs-toggle='tab' href='#{$tab['id']}' role='tab' aria-controls='{$tab['id']}' aria-selected='" . ($i === 0 ? 'true' : 'false') . "'>";
    echo "    <i class='{$tab['icon']} me-1'></i>" . htmlspecialchars($tab['label']);
    echo "  </a>";
    echo "</li>";
}

echo "</ul>";

// ── Tab Content ───────────────────────────────────────────────────────────
echo "<div class='tab-content' id='glpichat-config-tabs-content'>";

// ── Tab: Sincronização ────────────────────────────────────────────────────
echo "<div class='tab-pane fade show active' id='tab-sync' role='tabpanel' aria-labelledby='tab-sync-link'>";
echo "  <div class='row g-3'>";

$sync_col1 = [
    ['poll_focused_ms', $poll_focused_ms, __('Poll em foco (ms)', 'glpichat'), __('Intervalo em ms entre polls quando o chat está aberto e visível. Valores menores = mensagens mais rápidas, porém mais carga no servidor. Recomendado: 2500ms para instâncias médias.', 'glpichat'), 1000, 30000],
    ['poll_idle_ms',    $poll_idle_ms,    __('Poll em idle (ms)', 'glpichat'),  __('Intervalo em ms quando a janela do GLPI está visível mas nenhum chat está aberto. Aumentar reduz carga sem impactar a experiência.', 'glpichat'), 3000, 60000],
    ['poll_bg_ms',      $poll_bg_ms,      __('Poll em background (ms)', 'glpichat'), __('Intervalo em ms quando a aba do GLPI está em background (usuário em outra aba). Mínimo recomendado: 15000ms.', 'glpichat'), 5000, 120000],
    ['guest_poll_ms',   $guest_poll_ms,   __('Poll de convidados (ms)', 'glpichat'), __('Intervalo em ms entre sincronizações do convidado externo. Cada guest ativo com esse intervalo gera carga contínua no servidor.', 'glpichat'), 2000, 30000],
];
$sync_col2 = [
    ['meta_sync_ms',              $meta_sync_ms,              __('Sincronização de metadados (ms)', 'glpichat'),      __('Intervalo em ms para atualizar metadados de canais (não lidos, participantes). Menos crítico que o poll principal.', 'glpichat'), 5000, 120000],
    ['invite_poll_ms',            $invite_poll_ms,            __('Poll de convites (ms)', 'glpichat'),                __('Intervalo em ms para auto-atualizar a lista de convites na aba de gestão de chamados.', 'glpichat'), 3000, 60000],
    ['poll_max_channels',         $poll_max_channels,         __('Máximo de canais por poll', 'glpichat'),            __('Máximo de canais processados por chamada de poll. Limita o pior caso de queries por request (aprox. 4-5 queries por canal).', 'glpichat'), 1, 50],
    ['user_inactivity_timeout_s', $user_inactivity_timeout_s, __('Timeout de inatividade (s)', 'glpichat'),           __('Segundos sem atividade para considerar um usuário como desconectado da sala e gerar evento de saída.', 'glpichat'), 30, 600],
];

echo "    <div class='col-md-6'>";
foreach ($sync_col1 as [$name, $value, $label, $help, $min, $max]) {
    echo "      <div class='mb-3'>";
    echo "        <label class='form-label fw-semibold' for='" . htmlspecialchars($name) . "'>" . htmlspecialchars($label) . "</label>";
    echo "        <input type='number' min='{$min}' max='{$max}' name='" . htmlspecialchars($name) . "' id='" . htmlspecialchars($name) . "' class='form-control' value='" . (int)$value . "' required>";
    echo "        <div class='form-text'>" . htmlspecialchars($help) . "</div>";
    echo "      </div>";
}
echo "    </div>";

echo "    <div class='col-md-6'>";
foreach ($sync_col2 as [$name, $value, $label, $help, $min, $max]) {
    echo "      <div class='mb-3'>";
    echo "        <label class='form-label fw-semibold' for='" . htmlspecialchars($name) . "'>" . htmlspecialchars($label) . "</label>";
    echo "        <input type='number' min='{$min}' max='{$max}' name='" . htmlspecialchars($name) . "' id='" . htmlspecialchars($name) . "' class='form-control' value='" . (int)$value . "' required>";
    echo "        <div class='form-text'>" . htmlspecialchars($help) . "</div>";
    echo "      </div>";
}
echo "    </div>";

echo "  </div>";
if (Session::haveRight("plugin_glpichat_admin", UPDATE)) {
    echo "  <div class='mt-2 text-end'>";
    echo "    <button type='submit' name='update' class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>" . __('Salvar', 'glpichat') . "</button>";
    echo "  </div>";
}
echo "</div>";

// ── Tab: Segurança ────────────────────────────────────────────────────────
echo "<div class='tab-pane fade' id='tab-seguranca' role='tabpanel' aria-labelledby='tab-seguranca-link'>";
echo "  <div class='row g-3'>";

$seg_col1 = [
    ['guest_session_ttl',          $guest_session_ttl,          __('TTL da sessão de convidados (s)', 'glpichat'),        __('Tempo máximo em segundos que um convidado externo permanece ativo sem enviar mensagens ou heartbeat.', 'glpichat'), 60, 86400],
    ['invite_ttl_seconds',         $invite_ttl_seconds,         __('Validade do convite (s)', 'glpichat'),                __('Validade do link de convite enviado por e-mail. Após este prazo o link expira e um novo convite deve ser gerado.', 'glpichat'), 300, 259200],
    ['guest_send_throttle_s',      $guest_send_throttle_s,      __('Throttle de envio por convidado (s)', 'glpichat'),    __('Intervalo mínimo em segundos entre mensagens consecutivas de um mesmo convidado. Previne flooding.', 'glpichat'), 1, 30],
];
$seg_col2 = [
    ['accept_rate_limit_window_s',     $accept_rate_limit_window_s,     __('Janela do rate limit de aceitação (s)', 'glpichat'), __('Janela de tempo em segundos para contagem de tentativas de aceitação de convite por IP.', 'glpichat'), 30, 300],
    ['accept_rate_limit_max_attempts', $accept_rate_limit_max_attempts, __('Máximo de tentativas de aceitação', 'glpichat'),     __('Máximo de tentativas de aceitação de convite por IP dentro da janela configurada acima.', 'glpichat'), 3, 100],
    ['max_invites_per_ticket',         $max_invites_per_ticket,         __('Máximo de convites por chamado', 'glpichat'),        __('Limite de convites ativos simultâneos por chamado. Previne acúmulo de links válidos pendentes.', 'glpichat'), 1, 50],
];

echo "    <div class='col-md-6'>";
foreach ($seg_col1 as [$name, $value, $label, $help, $min, $max]) {
    echo "      <div class='mb-3'>";
    echo "        <label class='form-label fw-semibold' for='" . htmlspecialchars($name) . "'>" . htmlspecialchars($label) . "</label>";
    echo "        <input type='number' min='{$min}' max='{$max}' name='" . htmlspecialchars($name) . "' id='" . htmlspecialchars($name) . "' class='form-control' value='" . (int)$value . "' required>";
    echo "        <div class='form-text'>" . htmlspecialchars($help) . "</div>";
    echo "      </div>";
}
echo "    </div>";

echo "    <div class='col-md-6'>";
foreach ($seg_col2 as [$name, $value, $label, $help, $min, $max]) {
    echo "      <div class='mb-3'>";
    echo "        <label class='form-label fw-semibold' for='" . htmlspecialchars($name) . "'>" . htmlspecialchars($label) . "</label>";
    echo "        <input type='number' min='{$min}' max='{$max}' name='" . htmlspecialchars($name) . "' id='" . htmlspecialchars($name) . "' class='form-control' value='" . (int)$value . "' required>";
    echo "        <div class='form-text'>" . htmlspecialchars($help) . "</div>";
    echo "      </div>";
}
echo "    </div>";

echo "  </div>";
if (Session::haveRight("plugin_glpichat_admin", UPDATE)) {
    echo "  <div class='mt-2 text-end'>";
    echo "    <button type='submit' name='update' class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>" . __('Salvar', 'glpichat') . "</button>";
    echo "  </div>";
}
echo "</div>";

// ── Tab: Interface ────────────────────────────────────────────────────────
echo "<div class='tab-pane fade' id='tab-interface' role='tabpanel' aria-labelledby='tab-interface-link'>";

echo "  <div class='mb-3'>";
echo "    <label class='form-label fw-semibold' for='desktop_max_open'>" . __('Máximo de chats abertos no desktop', 'glpichat') . "</label>";
echo "    <input type='number' min='1' max='10' name='desktop_max_open' id='desktop_max_open' class='form-control' value='" . (int)$desktop_max_open . "' required>";
echo "    <div class='form-text'>" . __('Máximo de janelas de chat abertas ao mesmo tempo no widget desktop. Cada janela adicional = mais canais no poll.', 'glpichat') . "</div>";
echo "  </div>";

echo "  <div class='mb-3'>";
echo "    <label class='form-label fw-semibold' for='widget_default_position'>" . __('Posição padrão do widget', 'glpichat') . "</label>";
echo "    <select name='widget_default_position' id='widget_default_position' class='form-select'>";
$position_labels = [
    'bottom-right' => __('Inferior direito', 'glpichat'),
    'bottom-left'  => __('Inferior esquerdo', 'glpichat'),
    'top-right'    => __('Superior direito', 'glpichat'),
    'top-left'     => __('Superior esquerdo', 'glpichat'),
];
foreach ($valid_positions as $pos) {
    $selected = $widget_default_position === $pos ? ' selected' : '';
    $pos_label = $position_labels[$pos] ?? htmlspecialchars($pos);
    echo "      <option value='" . htmlspecialchars($pos) . "'{$selected}>{$pos_label}</option>";
}
echo "    </select>";
echo "    <div class='form-text'>" . __('Canto da tela onde o widget de chat aparecerá por padrão para todos os usuários.', 'glpichat') . "</div>";
echo "  </div>";

echo "  <div class='mb-3'>";
echo "    <label class='form-label fw-semibold' for='typing_indicator_timeout_ms'>" . __('Timeout do indicador de digitação (ms)', 'glpichat') . "</label>";
echo "    <input type='number' min='1000' max='10000' name='typing_indicator_timeout_ms' id='typing_indicator_timeout_ms' class='form-control' value='" . (int)$typing_indicator_timeout_ms . "' required>";
echo "    <div class='form-text'>" . __("Tempo em ms que o indicador 'está digitando...' permanece visível após a última tecla pressionada.", 'glpichat') . "</div>";
echo "  </div>";

echo "  <div class='mb-3'>";
echo "    <div class='form-check'>";
echo "      <input type='checkbox' name='auto_open_ticket_chat' id='auto_open_ticket_chat' class='form-check-input' value='1'" . ($auto_open_ticket_chat ? ' checked' : '') . ">";
echo "      <label class='form-check-label fw-semibold' for='auto_open_ticket_chat'>" . __('Abrir chat automaticamente ao entrar no chamado', 'glpichat') . "</label>";
echo "    </div>";
echo "    <div class='form-text'>" . __('Quando ativo, o chat abre automaticamente ao visualizar um chamado. Gera carga extra no carregamento inicial de tickets.', 'glpichat') . "</div>";
echo "  </div>";

echo "  <div class='mb-3'>";
echo "    <label class='form-label fw-semibold' for='auto_scroll_threshold_px'>" . __('Threshold de auto-scroll (px)', 'glpichat') . "</label>";
echo "    <input type='number' min='50' max='500' name='auto_scroll_threshold_px' id='auto_scroll_threshold_px' class='form-control' value='" . (int)$auto_scroll_threshold_px . "' required>";
echo "    <div class='form-text'>" . __('Distância em pixels do final da lista de mensagens para ativar o scroll automático ao chegar nova mensagem.', 'glpichat') . "</div>";
echo "  </div>";

echo "  <div class='mb-3'>";
echo "    <label class='form-label fw-semibold' for='unread_badge_threshold'>" . __('Threshold do badge de não lidos', 'glpichat') . "</label>";
echo "    <input type='number' min='1' max='10' name='unread_badge_threshold' id='unread_badge_threshold' class='form-control' value='" . (int)$unread_badge_threshold . "' required>";
echo "    <div class='form-text'>" . __('A partir de quantas mensagens não lidas o badge de contagem aparece no launcher do chat.', 'glpichat') . "</div>";
echo "  </div>";

if (Session::haveRight("plugin_glpichat_admin", UPDATE)) {
    echo "  <div class='mt-2 text-end'>";
    echo "    <button type='submit' name='update' class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>" . __('Salvar', 'glpichat') . "</button>";
    echo "  </div>";
}
echo "</div>";

// ── Tab: Chat ─────────────────────────────────────────────────────────────
echo "<div class='tab-pane fade' id='tab-chat' role='tabpanel' aria-labelledby='tab-chat-link'>";

echo "  <div class='mb-3'>";
echo "    <label class='form-label fw-semibold' for='history_page_default'>" . __('Mensagens por página (padrão)', 'glpichat') . "</label>";
echo "    <input type='number' min='10' max='100' name='history_page_default' id='history_page_default' class='form-control' value='" . (int)$history_page_default . "' required>";
echo "    <div class='form-text'>" . __('Quantas mensagens são carregadas ao abrir um canal pela primeira vez.', 'glpichat') . "</div>";
echo "  </div>";

echo "  <div class='mb-3'>";
echo "    <label class='form-label fw-semibold' for='history_page_max'>" . __('Mensagens por página (máximo)', 'glpichat') . "</label>";
echo "    <input type='number' min='10' max='200' name='history_page_max' id='history_page_max' class='form-control' value='" . (int)$history_page_max . "' required>";
echo "    <div class='form-text'>" . __('Limite máximo de mensagens que o frontend pode solicitar em uma única requisição de histórico.', 'glpichat') . "</div>";
echo "  </div>";

if (Session::haveRight("plugin_glpichat_admin", UPDATE)) {
    echo "  <div class='mt-2 text-end'>";
    echo "    <button type='submit' name='update' class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>" . __('Salvar', 'glpichat') . "</button>";
    echo "  </div>";
}
echo "</div>";

// ── Tab: Retenção ─────────────────────────────────────────────────────────
echo "<div class='tab-pane fade' id='tab-retencao' role='tabpanel' aria-labelledby='tab-retencao-link'>";

echo "  <div class='alert alert-warning mb-3' role='alert'>";
echo "    <div class='d-flex gap-2 align-items-center'>";
echo "      <i class='ti ti-alert-triangle'></i>";
echo "      <div>" . __('Reduzir os valores abaixo resulta em exclusão permanente de dados no próximo CronTask. Esta ação não pode ser desfeita.', 'glpichat') . "</div>";
echo "    </div>";
echo "  </div>";

$retention_fields = [
    [
        'name'  => 'events_retention_days',
        'value' => $events_retention_days,
        'label' => __('Retenção de eventos (dias)', 'glpichat'),
        'help'  => __('Eventos de sincronização (typing, presença, deltas) mais antigos que este número de dias são deletados pelo CronTask. Com volume médio: ~100K eventos/dia.', 'glpichat'),
        'min'   => 7,
        'max'   => 365,
    ],
    [
        'name'  => 'messages_retention_days',
        'value' => $messages_retention_days,
        'label' => __('Retenção de mensagens (dias)', 'glpichat'),
        'help'  => __("Mensagens do chat mais antigas que este número de dias são expurgadas. Um evento 'mensagens anteriores expurgadas' é inserido na timeline. CUIDADO: reduzir este valor deleta mensagens permanentemente no próximo CronTask.", 'glpichat'),
        'min'   => 30,
        'max'   => 1825,
    ],
    [
        'name'  => 'auditlogs_retention_days',
        'value' => $auditlogs_retention_days,
        'label' => __('Retenção de auditoria (dias)', 'glpichat'),
        'help'  => __('Registros de auditoria de acesso (tentativas de login de convidados, falhas de autenticação) mais antigos que este número de dias são deletados.', 'glpichat'),
        'min'   => 30,
        'max'   => 730,
    ],
];

foreach ($retention_fields as $field) {
    echo "  <div class='mb-3'>";
    echo "    <label class='form-label fw-semibold' for='" . htmlspecialchars($field['name']) . "'>";
    echo       htmlspecialchars($field['label']);
    echo "      <span class='badge bg-danger-lt text-danger ms-1'>" . __('Alto impacto', 'glpichat') . "</span>";
    echo "    </label>";
    echo "    <input type='number' min='{$field['min']}' max='{$field['max']}' name='" . htmlspecialchars($field['name']) . "' id='" . htmlspecialchars($field['name']) . "' class='form-control glpichat-retention-field' value='" . (int)$field['value'] . "' required>";
    echo "    <div class='form-text'>" . htmlspecialchars($field['help']) . "</div>";
    echo "  </div>";
}

if (Session::haveRight("plugin_glpichat_admin", UPDATE)) {
    echo "  <div class='mt-2 text-end'>";
    echo "    <button type='submit' name='update' class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>" . __('Salvar', 'glpichat') . "</button>";
    echo "  </div>";
}
echo "</div>";

// ── Tab: Integração ───────────────────────────────────────────────────────
echo "<div class='tab-pane fade' id='tab-integracao' role='tabpanel' aria-labelledby='tab-integracao-link'>";

echo "  <div class='mb-3'>";
echo "    <label class='form-label fw-semibold' for='dropdown_external_followup_user_id'>" . __('Usuário associado a mensagens de convidados externos', 'glpichat') . "</label>";
User::dropdown([
    'name'  => 'external_followup_user_id',
    'value' => $external_followup_user_id,
    'width' => '100%',
    // Sem 'right' o GLPI usa o default 'id', que lista apenas o usuario logado.
    // 'all' lista todos os usuarios para associar aos followups de convidados.
    'right' => 'all',
]);
echo "    <div class='form-text'>" . __('As ações no chat e followups inseridos por convidados externos serão associadas a este usuário do GLPI.', 'glpichat') . "</div>";
echo "  </div>";

if (Session::haveRight("plugin_glpichat_admin", UPDATE)) {
    echo "  <div class='mt-2 text-end'>";
    echo "    <button type='submit' name='update' class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>" . __('Salvar', 'glpichat') . "</button>";
    echo "  </div>";
}
echo "</div>";

echo "</div>"; // tab-content
echo "</form>";

// ── Modal de confirmação ──────────────────────────────────────────────────
echo "<div class='modal fade' id='glpichat-confirm-modal' tabindex='-1' role='dialog' aria-labelledby='glpichat-confirm-modal-title' aria-hidden='true'>";
echo "  <div class='modal-dialog' role='document'>";
echo "    <div class='modal-content'>";
echo "      <div class='modal-header'>";
echo "        <h5 class='modal-title' id='glpichat-confirm-modal-title'><i class='ti ti-alert-triangle me-2 text-danger'></i>" . __('Confirmar redução de retenção', 'glpichat') . "</h5>";
echo "        <button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='" . __('Fechar', 'glpichat') . "'></button>";
echo "      </div>";
echo "      <div class='modal-body overflow-auto'>";
echo "        <p class='text-muted mb-2'>" . __('As seguintes alterações resultarão em exclusão permanente de dados no próximo CronTask:', 'glpichat') . "</p>";
echo "        <ul id='glpichat-confirm-changes-list' class='mb-0'></ul>";
echo "      </div>";
echo "      <div class='modal-footer'>";
echo "        <button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>" . __('Cancelar', 'glpichat') . "</button>";
echo "        <button type='button' class='btn btn-danger' id='glpichat-confirm-save-btn'><i class='ti ti-trash me-1'></i>" . __('Confirmar e Salvar', 'glpichat') . "</button>";
echo "      </div>";
echo "    </div>";
echo "  </div>";
echo "</div>";

echo "</div>"; // container-xl

// ── Script inline ─────────────────────────────────────────────────────────
$orig_events   = (int)$events_retention_days;
$orig_messages = (int)$messages_retention_days;
$orig_audit    = (int)$auditlogs_retention_days;

echo "<script>" . "\n";
echo "(function () {" . "\n";
echo "  'use strict';" . "\n";
echo "" . "\n";
echo "  // Valores originais de retenção (injetados pelo PHP)" . "\n";
echo "  var origRetention = {" . "\n";
echo "    events_retention_days:   {$orig_events}," . "\n";
echo "    messages_retention_days: {$orig_messages}," . "\n";
echo "    auditlogs_retention_days: {$orig_audit}" . "\n";
echo "  };" . "\n";
echo "" . "\n";
echo "  var retentionLabels = {" . "\n";
echo "    events_retention_days:    " . json_encode(__('Retenção de eventos', 'glpichat')) . "," . "\n";
echo "    messages_retention_days:  " . json_encode(__('Retenção de mensagens', 'glpichat')) . "," . "\n";
echo "    auditlogs_retention_days: " . json_encode(__('Retenção de auditoria', 'glpichat')) . "\n";
echo "  };" . "\n";
echo "" . "\n";
echo "  // Guard de alterações não salvas" . "\n";
echo "  var isDirty = false;" . "\n";
echo "  var form = document.getElementById('glpichat-config-form');" . "\n";
echo "" . "\n";
echo "  form.addEventListener('change', function () { isDirty = true; });" . "\n";
echo "  form.addEventListener('input',  function () { isDirty = true; });" . "\n";
echo "" . "\n";
echo "  window.addEventListener('beforeunload', function (e) {" . "\n";
echo "    if (isDirty) {" . "\n";
echo "      e.preventDefault();" . "\n";
echo "      e.returnValue = '';" . "\n";
echo "    }" . "\n";
echo "  });" . "\n";
echo "" . "\n";
echo "  // Restaurar tab ativa pelo hash da URL" . "\n";
echo "  var hash = window.location.hash;" . "\n";
echo "  if (hash && /^#[\w-]+$/.test(hash)) {" . "\n";
echo "    var tabLink = document.querySelector('a[href=\"' + hash + '\"]');" . "\n";
echo "    if (tabLink) {" . "\n";
echo "      new bootstrap.Tab(tabLink).show();" . "\n";
echo "    }" . "\n";
echo "  }" . "\n";
echo "" . "\n";
echo "  // Atualizar hash ao trocar de tab" . "\n";
echo "  document.querySelectorAll('[data-bs-toggle=\"tab\"]').forEach(function (tab) {" . "\n";
echo "    tab.addEventListener('shown.bs.tab', function (e) {" . "\n";
echo "      history.replaceState(null, null, e.target.getAttribute('href'));" . "\n";
echo "    });" . "\n";
echo "  });" . "\n";
echo "" . "\n";
echo "  // Interceptar submit para modal de confirmação de retenção" . "\n";
echo "  var confirmModal    = null;" . "\n";
echo "  var confirmBtn      = document.getElementById('glpichat-confirm-save-btn');" . "\n";
echo "  var retentionConfirmed = false;" . "\n";
echo "" . "\n";
echo "  form.addEventListener('submit', function (e) {" . "\n";
echo "    // Se o usuário já confirmou via modal, deixar submeter normalmente" . "\n";
echo "    if (retentionConfirmed) {" . "\n";
echo "      isDirty = false;" . "\n";
echo "      return;" . "\n";
echo "    }" . "\n";
echo "" . "\n";
echo "    var reductions = [];" . "\n";
echo "" . "\n";
echo "    Object.keys(origRetention).forEach(function (fieldName) {" . "\n";
echo "      var input = document.getElementById(fieldName);" . "\n";
echo "      if (!input) return;" . "\n";
echo "      var newVal = parseInt(input.value, 10);" . "\n";
echo "      var origVal = origRetention[fieldName];" . "\n";
echo "      if (!isNaN(newVal) && newVal < origVal) {" . "\n";
echo "        var cutoffDate = new Date(Date.now() - newVal * 86400000);" . "\n";
echo "        var dateStr = cutoffDate.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });" . "\n";
echo "        reductions.push({" . "\n";
echo "          label:   retentionLabels[fieldName]," . "\n";
echo "          origVal: origVal," . "\n";
echo "          newVal:  newVal," . "\n";
echo "          dateStr: dateStr" . "\n";
echo "        });" . "\n";
echo "      }" . "\n";
echo "    });" . "\n";
echo "" . "\n";
echo "    if (reductions.length > 0) {" . "\n";
echo "      e.preventDefault();" . "\n";
echo "      var list = document.getElementById('glpichat-confirm-changes-list');" . "\n";
echo "      list.innerHTML = '';" . "\n";
echo "      reductions.forEach(function (r) {" . "\n";
echo "        var li = document.createElement('li');" . "\n";
echo "        li.className = 'mb-1';" . "\n";
echo "        li.innerHTML = '<strong>' + r.label + '</strong>: ' + r.origVal + ' &rarr; ' + r.newVal + ' " . __('dias', 'glpichat') . " &mdash; " . __('dados anteriores a', 'glpichat') . " <strong>' + r.dateStr + '</strong> " . __('serão deletados permanentemente', 'glpichat') . ".';" . "\n";
echo "        list.appendChild(li);" . "\n";
echo "      });" . "\n";
echo "" . "\n";
echo "      if (!confirmModal) {" . "\n";
echo "        confirmModal = new bootstrap.Modal(document.getElementById('glpichat-confirm-modal'));" . "\n";
echo "      }" . "\n";
echo "      confirmModal.show();" . "\n";
echo "    } else {" . "\n";
echo "      isDirty = false;" . "\n";
echo "    }" . "\n";
echo "  });" . "\n";
echo "" . "\n";
echo "  confirmBtn.addEventListener('click', function () {" . "\n";
echo "    retentionConfirmed = true;" . "\n";
echo "    isDirty = false;" . "\n";
echo "    if (confirmModal) confirmModal.hide();" . "\n";
echo "    // Disparar submit novamente — agora retentionConfirmed=true, não será interceptado" . "\n";
echo "    form.requestSubmit ? form.requestSubmit() : form.submit();" . "\n";
echo "  });" . "\n";
echo "" . "\n";
echo "})();" . "\n";
echo "</script>" . "\n";

Html::footer();
