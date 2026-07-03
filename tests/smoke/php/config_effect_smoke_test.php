<?php

declare(strict_types=1);

// Smoke: cada chave de configuracao do plugin glpichat e LIDA do banco e tem
// EFEITO REAL no consumidor correspondente. Para cada chave: define um valor
// custom via Config::setConfigurationValues, exercita o consumidor e restaura o
// valor original no finally. NAO e destrutivo (nao dropa tabelas); limpa todas
// as linhas que cria via baseline de ids.

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
 * (ex.: max_invites_per_ticket, guest_send_throttle_s) e linhas orfãs no
 * banco sempre que uma asserção falhava aqui - contaminando os demais smokes
 * que rodam na mesma esteira/ticket (causa raiz de falhas em cascata).
 *
 * @return never
 */
function cfgFail(string $message): void
{
    throw new RuntimeException("[FAIL] {$message}");
}

function cfgPass(string $message): void
{
    fwrite(STDOUT, "[PASS] {$message}\n");
}

function cfgAssert(bool $condition, string $message): void
{
    if (!$condition) {
        cfgFail($message);
    }
}

function cfgGet(string $key): string
{
    return (string) Config::getConfigurationValue('plugin:glpichat', $key);
}

/**
 * @param array<string, string> $values
 */
function cfgSet(array $values): void
{
    Config::setConfigurationValues('plugin:glpichat', $values);
}

function cfgMaxId(string $table): int
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

function cfgActiveInviteCount(int $tickets_id): int
{
    global $DB;
    $row = $DB->request([
        'COUNT' => 'cpt',
        'FROM'  => 'glpi_plugin_glpichat_invites',
        'WHERE' => [
            'tickets_id'  => $tickets_id,
            'accepted_at' => null,
            'revoked_at'  => null,
            ['expires_at' => ['>', new \Glpi\DBAL\QueryExpression('NOW()')]],
        ],
    ])->current();
    return (int) ($row['cpt'] ?? 0);
}

function cfgSeedSession(): int
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

    cfgAssert(is_array($row), 'Sessao de teste precisa de um usuario GLPI ativo');

    $users_id = (int) $row['users_id'];
    $_SESSION['valid_id'] = session_id();
    $_SESSION['glpiname'] = (string) $row['user_name'];
    $_SESSION['glpiID'] = $users_id;
    Session::initEntityProfiles($users_id);
    Session::changeProfile((int) $row['profiles_id']);
    $_SESSION['glpiactiveprofile']['plugin_glpichat_public'] = READ | CREATE;
    $_SESSION['glpiactiveprofile']['plugin_glpichat_internal'] = READ | CREATE;
    $_SESSION['glpiactiveprofile']['plugin_glpichat_invite'] = READ | CREATE | UPDATE;
    $_SESSION['glpiactive_entity'] = (int) ($row['entities_id'] ?? 0);
    $_SESSION['glpigroups'] = [];

    return $users_id;
}

global $DB;

$tickets_id = (int) (getenv('GLPICHAT_TICKET_ID') ?: 1);
$users_id = cfgSeedSession();
$public_rooms_id = glpichat_room_id($tickets_id, GLPICHAT_ROOM_PUBLIC);
$internal_rooms_id = glpichat_room_id($tickets_id, GLPICHAT_ROOM_INTERNAL);

// Baselines: tudo com id > baseline criado por este processo e removido no teardown.
$baseline = [
    'glpi_plugin_glpichat_events'        => cfgMaxId('glpi_plugin_glpichat_events'),
    'glpi_plugin_glpichat_auditlogs'     => cfgMaxId('glpi_plugin_glpichat_auditlogs'),
    'glpi_plugin_glpichat_messages'      => cfgMaxId('glpi_plugin_glpichat_messages'),
    'glpi_plugin_glpichat_invites'       => cfgMaxId('glpi_plugin_glpichat_invites'),
    'glpi_plugin_glpichat_participants'  => cfgMaxId('glpi_plugin_glpichat_participants'),
    'glpi_plugin_glpichat_readstates'    => cfgMaxId('glpi_plugin_glpichat_readstates'),
];

// Snapshot das chaves que vamos alterar, para restaurar no finally.
$config_keys = [
    'invite_ttl_seconds',
    'max_invites_per_ticket',
    'unread_badge_threshold',
    'guest_send_throttle_s',
    'user_inactivity_timeout_s',
    'poll_max_channels',
    'history_page_default',
    'history_page_max',
    'poll_focused_ms',
    'poll_idle_ms',
    'poll_bg_ms',
    'meta_sync_ms',
    'invite_poll_ms',
    'desktop_max_open',
    'typing_indicator_timeout_ms',
    'auto_open_ticket_chat',
    'auto_scroll_threshold_px',
    'widget_default_position',
    'external_followup_user_id',
];
$config_snapshot = [];
foreach ($config_keys as $key) {
    $config_snapshot[$key] = cfgGet($key);
}

try {
try {
    // -----------------------------------------------------------------
    // invite_ttl_seconds -> expires_at do convite ~= now + ttl
    // -----------------------------------------------------------------
    $ttl = 7200;
    cfgSet(['invite_ttl_seconds' => (string) $ttl]);
    cfgAssert(glpichat_invite_ttl_seconds() === $ttl, 'invite_ttl_seconds deve ser lido do banco');

    $before_ts = time();
    $invite = glpichat_create_invite_use_case(
        $tickets_id,
        'Cfg TTL Guest',
        'cfg-ttl@example.com',
        'http://localhost:8080',
        static function (int $t): void {},
        'glpichat_room_id',
        static fn(): string => bin2hex(random_bytes(16)),
        static fn(): int => $users_id,
        'glpichat_insert_invite',
        'glpichat_record_event',
        glpichat_invite_ttl_seconds()
    );
    $after_ts = time();
    $expires_ts = strtotime((string) $invite['expires_at']);
    cfgAssert($expires_ts !== false, 'expires_at do convite deve ser uma data valida');
    cfgAssert(
        $expires_ts >= $before_ts + $ttl && $expires_ts <= $after_ts + $ttl,
        sprintf('expires_at (%s) deve ser ~= now + %ds', (string) $invite['expires_at'], $ttl)
    );
    cfgPass('invite_ttl_seconds controla o expires_at do convite');

    // -----------------------------------------------------------------
    // max_invites_per_ticket -> bloqueia ao atingir o limite (mensagem PT)
    // -----------------------------------------------------------------
    $current_active = cfgActiveInviteCount($tickets_id); // inclui o convite criado acima
    $limit = $current_active + 2;
    cfgSet(['max_invites_per_ticket' => (string) $limit]);

    $make_invite = static function (string $name) use ($tickets_id, $users_id): array {
        return glpichat_create_invite_use_case(
            $tickets_id,
            $name,
            'cfg-max@example.com',
            'http://localhost:8080',
            static function (int $t): void {},
            'glpichat_room_id',
            static fn(): string => bin2hex(random_bytes(16)),
            static fn(): int => $users_id,
            'glpichat_insert_invite',
            'glpichat_record_event',
            glpichat_invite_ttl_seconds()
        );
    };

    $make_invite('Cfg Max Guest 1'); // ativos = limit-1
    $make_invite('Cfg Max Guest 2'); // ativos = limit
    $threw = false;
    try {
        $make_invite('Cfg Max Guest 3'); // deve estourar
    } catch (RuntimeException $e) {
        $threw = true;
        cfgAssert(
            str_contains($e->getMessage(), 'Limite máximo de convites ativos por chamado atingido.'),
            'max_invites_per_ticket deve falhar com a mensagem PT real - veio: ' . $e->getMessage()
        );
    }
    cfgAssert($threw, 'max_invites_per_ticket deve bloquear o convite alem do limite configurado');
    cfgPass('max_invites_per_ticket limita o numero de convites ativos por chamado');

    // -----------------------------------------------------------------
    // unread_badge_threshold -> glpichat_display_unread_count respeita o limite
    // -----------------------------------------------------------------
    cfgSet(['unread_badge_threshold' => '5']);
    cfgAssert(glpichat_display_unread_count(4) === 0, 'Abaixo do threshold (5) deve retornar 0');
    cfgAssert(glpichat_display_unread_count(5) === 5, 'No threshold (5) deve retornar o valor real');
    cfgAssert(glpichat_display_unread_count(9) === 9, 'Acima do threshold deve retornar o valor real');
    cfgSet(['unread_badge_threshold' => '1']);
    cfgAssert(glpichat_display_unread_count(1) === 1, 'Com threshold 1, unread 1 deve aparecer');
    cfgPass('unread_badge_threshold controla glpichat_display_unread_count');

    // -----------------------------------------------------------------
    // guest_send_throttle_s -> throttle do guest (mensagem PT 429)
    // -----------------------------------------------------------------
    cfgSet(['guest_send_throttle_s' => '100']);
    $DB->insert('glpi_plugin_glpichat_participants', [
        'rooms_id'           => $public_rooms_id,
        'users_id'           => 0,
        'guest_name'         => 'Cfg Throttle Guest',
        'participant_type'   => GLPICHAT_ACTOR_GUEST,
        'session_token_hash' => hash('sha256', bin2hex(random_bytes(8))),
        'joined_at'          => date('Y-m-d H:i:s'),
        'last_send_at'       => date('Y-m-d H:i:s'),
    ]);
    $throttle_pid = (int) $DB->insertId();

    $throttle_threw = false;
    try {
        glpichat_assert_guest_not_throttled($throttle_pid);
    } catch (RuntimeException $e) {
        $throttle_threw = true;
        cfgAssert(
            str_contains($e->getMessage(), 'Muitas requisições. Tente novamente em instantes.'),
            'Throttle deve usar a mensagem PT real - veio: ' . $e->getMessage()
        );
    }
    cfgAssert($throttle_threw, 'Com throttle 100s e envio agora, o guest deve ser bloqueado');

    // Fora da janela de throttle: nao bloqueia.
    $DB->update('glpi_plugin_glpichat_participants', [
        'last_send_at' => date('Y-m-d H:i:s', time() - 200),
    ], ['id' => $throttle_pid]);
    $no_throttle = true;
    try {
        glpichat_assert_guest_not_throttled($throttle_pid);
    } catch (RuntimeException $e) {
        $no_throttle = false;
    }
    cfgAssert($no_throttle, 'Fora da janela de throttle (200s > 100s) o guest nao deve ser bloqueado');
    cfgPass('guest_send_throttle_s controla a janela de throttle do guest');

    // -----------------------------------------------------------------
    // user_inactivity_timeout_s -> expira participantes inativos alem do limite
    // -----------------------------------------------------------------
    cfgSet(['user_inactivity_timeout_s' => '3600']);
    // Participante "alem do limite" (2h atras) deve expirar.
    $DB->insert('glpi_plugin_glpichat_participants', [
        'rooms_id'         => $public_rooms_id,
        'users_id'         => $users_id,
        'participant_type' => GLPICHAT_ACTOR_USER,
        'joined_at'        => date('Y-m-d H:i:s', time() - 7200),
        'last_activity'    => date('Y-m-d H:i:s', time() - 7200),
    ]);
    $expire_pid = (int) $DB->insertId();
    // Participante "dentro do limite" (agora) nao deve expirar.
    $DB->insert('glpi_plugin_glpichat_participants', [
        'rooms_id'         => $internal_rooms_id,
        'users_id'         => $users_id,
        'participant_type' => GLPICHAT_ACTOR_USER,
        'joined_at'        => date('Y-m-d H:i:s'),
        'last_activity'    => date('Y-m-d H:i:s'),
    ]);
    $keep_pid = (int) $DB->insertId();

    glpichat_expire_inactive_user_participants();

    $expired = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_participants',
        'WHERE' => ['id' => $expire_pid],
        'LIMIT' => 1,
    ])->current();
    $kept = $DB->request([
        'FROM' => 'glpi_plugin_glpichat_participants',
        'WHERE' => ['id' => $keep_pid],
        'LIMIT' => 1,
    ])->current();
    cfgAssert($expired['left_at'] !== null, 'Participante alem do limite configurado deve expirar (left_at)');
    cfgAssert($kept['left_at'] === null, 'Participante dentro do limite configurado NAO deve expirar');
    cfgPass('user_inactivity_timeout_s controla glpichat_expire_inactive_user_participants');

    // -----------------------------------------------------------------
    // poll_max_channels -> Widget/Poll limita o numero de canais processados
    // -----------------------------------------------------------------
    $widget = new WidgetController();
    $build_poll = static function () use ($tickets_id): Request {
        return Request::create(
            '/plugins/glpichat/Widget/Poll',
            'POST',
            [
                '_glpi_csrf_token' => Session::getNewCSRFToken(),
                'channels' => [
                    ['tickets_id' => (string) $tickets_id, 'room_type' => 'public', 'after_id' => '0'],
                    ['tickets_id' => (string) $tickets_id, 'room_type' => 'internal', 'after_id' => '0'],
                ],
            ]
        );
    };

    cfgSet(['poll_max_channels' => '1']);
    $poll_resp = $widget->poll($build_poll());
    cfgAssert($poll_resp->getStatusCode() === 200, 'Widget/Poll deve retornar 200');
    $poll_json = json_decode($poll_resp->getContent(), true, 512, JSON_THROW_ON_ERROR);
    cfgAssert(count($poll_json['channels']) === 1, 'Com poll_max_channels=1 deve processar apenas 1 canal');

    cfgSet(['poll_max_channels' => '5']);
    $poll_resp2 = $widget->poll($build_poll());
    $poll_json2 = json_decode($poll_resp2->getContent(), true, 512, JSON_THROW_ON_ERROR);
    cfgAssert(count($poll_json2['channels']) === 2, 'Com poll_max_channels=5 deve processar os 2 canais');
    cfgPass('poll_max_channels limita os canais processados no Widget/Poll');

    // -----------------------------------------------------------------
    // history_page_default / history_page_max -> Widget/History usa default e cap
    // -----------------------------------------------------------------
    // Semeia 12 mensagens no canal publico para exercitar o paginador.
    for ($i = 0; $i < 12; $i++) {
        $DB->insert('glpi_plugin_glpichat_messages', [
            'rooms_id'         => $public_rooms_id,
            'tickets_id'       => $tickets_id,
            'participant_type' => GLPICHAT_ACTOR_USER,
            'users_id'         => $users_id,
            'participants_id'  => 0,
            'message_type'     => 'text',
            'content'          => 'cfg history msg ' . $i,
            'date_creation'    => date('Y-m-d H:i:s'),
        ]);
    }
    cfgSet(['history_page_default' => '7', 'history_page_max' => '9']);

    $hist_default = $widget->history(Request::create(
        '/plugins/glpichat/Widget/History',
        'GET',
        ['rooms_id' => $public_rooms_id, 'before_id' => 0]
    ));
    cfgAssert($hist_default->getStatusCode() === 200, 'Widget/History deve retornar 200');
    $hist_default_json = json_decode($hist_default->getContent(), true, 512, JSON_THROW_ON_ERROR);
    cfgAssert(count($hist_default_json['messages']) === 7, 'Sem limit, History deve usar history_page_default=7');

    $hist_cap = $widget->history(Request::create(
        '/plugins/glpichat/Widget/History',
        'GET',
        ['rooms_id' => $public_rooms_id, 'before_id' => 0, 'limit' => 100]
    ));
    $hist_cap_json = json_decode($hist_cap->getContent(), true, 512, JSON_THROW_ON_ERROR);
    cfgAssert(count($hist_cap_json['messages']) === 9, 'Com limit alto, History deve respeitar history_page_max=9');
    cfgPass('history_page_default/max controlam a paginacao do Widget/History');

    // -----------------------------------------------------------------
    // Chaves expostas ao frontend via Widget/Config -> valor exato
    // -----------------------------------------------------------------
    $frontend = [
        'poll_focused_ms'             => '3111',
        'poll_idle_ms'                => '8222',
        'poll_bg_ms'                  => '17333',
        'meta_sync_ms'                => '14444',
        'invite_poll_ms'              => '9555',
        'desktop_max_open'            => '4',
        'typing_indicator_timeout_ms' => '2777',
        'auto_scroll_threshold_px'    => '188',
        'history_page_default'        => '25',
        'history_page_max'            => '45',
        'widget_default_position'     => 'top-left',
    ];
    cfgSet($frontend);
    cfgSet(['auto_open_ticket_chat' => '0']); // edge: valor '0' deve ser lido como false

    $cfg_resp = $widget->config(Request::create('/plugins/glpichat/Widget/Config', 'GET'));
    cfgAssert($cfg_resp->getStatusCode() === 200, 'Widget/Config deve retornar 200');
    $cfg_json = json_decode($cfg_resp->getContent(), true, 512, JSON_THROW_ON_ERROR);

    cfgAssert($cfg_json['poll_focused_ms'] === 3111, 'Widget/Config poll_focused_ms deve refletir a config');
    cfgAssert($cfg_json['poll_idle_ms'] === 8222, 'Widget/Config poll_idle_ms deve refletir a config');
    cfgAssert($cfg_json['poll_bg_ms'] === 17333, 'Widget/Config poll_bg_ms deve refletir a config');
    cfgAssert($cfg_json['meta_sync_ms'] === 14444, 'Widget/Config meta_sync_ms deve refletir a config');
    cfgAssert($cfg_json['invite_poll_ms'] === 9555, 'Widget/Config invite_poll_ms deve refletir a config');
    cfgAssert($cfg_json['desktop_max_open'] === 4, 'Widget/Config desktop_max_open deve refletir a config');
    cfgAssert($cfg_json['typing_indicator_timeout_ms'] === 2777, 'Widget/Config typing_indicator_timeout_ms deve refletir a config');
    cfgAssert($cfg_json['auto_scroll_threshold_px'] === 188, 'Widget/Config auto_scroll_threshold_px deve refletir a config');
    cfgAssert($cfg_json['history_page_default'] === 25, 'Widget/Config history_page_default deve refletir a config');
    cfgAssert($cfg_json['history_page_max'] === 45, 'Widget/Config history_page_max deve refletir a config');
    cfgAssert($cfg_json['widget_default_position'] === 'top-left', 'Widget/Config widget_default_position deve refletir a config');
    cfgAssert($cfg_json['auto_open_ticket_chat'] === false, 'Widget/Config auto_open_ticket_chat deve ler o valor 0 como false');
    cfgPass('Widget/Config expoe exatamente os valores configurados ao frontend');

    // -----------------------------------------------------------------
    // external_followup_user_id -> validacao e leitura
    // -----------------------------------------------------------------
    cfgAssert(glpichat_is_valid_external_followup_user_id(0) === true, '0 (nao definido) deve ser valido');
    cfgAssert(glpichat_is_valid_external_followup_user_id(999999999) === false, 'id inexistente deve ser invalido');
    cfgAssert(glpichat_is_valid_external_followup_user_id($users_id) === true, 'id de usuario valido deve ser valido');

    cfgSet(['external_followup_user_id' => (string) $users_id]);
    cfgAssert(
        glpichat_external_followup_user_id() === $users_id,
        'glpichat_external_followup_user_id deve retornar o id configurado'
    );
    cfgPass('external_followup_user_id e validado e lido corretamente');

    fwrite(STDOUT, "[PASS] Smoke de efeito das configuracoes concluido\n");
} finally {
    // Restaura todas as configs alteradas.
    if (isset($config_snapshot) && is_array($config_snapshot)) {
        Config::setConfigurationValues('plugin:glpichat', $config_snapshot);
    }

    // Remove tudo que este teste criou (id > baseline) nas tabelas do plugin.
    foreach ($baseline as $table => $max_id) {
        $DB->delete($table, [['id' => ['>', $max_id]]]);
    }

    glpichat_clear_static_caches();

    unset($_SESSION['valid_id'], $_SESSION['glpiname'], $_SESSION['glpiID']);
    unset($_SESSION['glpiactiveprofile'], $_SESSION['glpiactive_entity'], $_SESSION['glpigroups']);
}
} catch (\Throwable $e) {
    // O finally acima ja restaurou config e limpou o banco antes de chegarmos
    // aqui - agora reportamos a falha e encerramos com codigo de erro.
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
