<?php

declare(strict_types=1);

// Smoke (redline de seguranca): rate limit por IP no aceite de convite.
//
// Exercita glpichat_assert_accept_not_rate_limited() lendo a config real
// (accept_rate_limit_window_s / accept_rate_limit_max_attempts) e o
// GuestController::accept (resposta 429 quando estourado). O contador e
// alimentado pelos auditlogs de 'guest_accept_failed' por hash de IP dentro da
// janela.
//
// NAO e destrutivo: snapshot/restore das configs + baseline de ids nos
// auditlogs, limpos no finally.

use GlpiPlugin\Glpichat\Controller\GuestController;
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
function rlFail(string $message): void
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function rlPass(string $message): void
{
    fwrite(STDOUT, "[PASS] {$message}\n");
}

function rlAssert(bool $condition, string $message): void
{
    if (!$condition) {
        rlFail($message);
    }
}

function rlMaxId(string $table): int
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
 * Insere N linhas de auditlog 'guest_accept_failed' para o IP informado, com
 * date_creation deslocado $age_seconds no passado (0 = agora).
 */
function rlSeedFailures(string $ip, int $n, int $age_seconds = 0): void
{
    global $DB;
    $ip_hash = hash('sha256', $ip);
    $when = date('Y-m-d H:i:s', time() - $age_seconds);
    for ($i = 0; $i < $n; $i++) {
        $DB->insert('glpi_plugin_glpichat_auditlogs', [
            'rooms_id'        => 0,
            'tickets_id'      => 0,
            'action'          => 'guest_accept_failed',
            'actor_type'      => 'unknown',
            'users_id'        => 0,
            'participants_id' => 0,
            'payload_json'    => json_encode(['detail' => 'smoke', 'ip_hash' => $ip_hash]),
            'date_creation'   => $when,
        ]);
    }
}

global $DB;

$baseline_auditlogs = rlMaxId('glpi_plugin_glpichat_auditlogs');

$config_keys = ['accept_rate_limit_window_s', 'accept_rate_limit_max_attempts'];
$config_snapshot = [];
foreach ($config_keys as $key) {
    $config_snapshot[$key] = (string) Config::getConfigurationValue('plugin:glpichat', $key);
}

$ip_main  = '203.0.113.77';
$ip_other = '198.51.100.5';
$ip_old   = '203.0.113.200';

try {
    // Janela grande (1h) e limite baixo (3) para um teste deterministico.
    Config::setConfigurationValues('plugin:glpichat', [
        'accept_rate_limit_window_s'     => '3600',
        'accept_rate_limit_max_attempts' => '3',
    ]);
    glpichat_clear_static_caches();

    rlAssert(glpichat_config_int('accept_rate_limit_max_attempts', 10) === 3, 'Config de limite deve ler 3 do banco');
    rlAssert(glpichat_config_int('accept_rate_limit_window_s', 60) === 3600, 'Config de janela deve ler 3600 do banco');

    // -----------------------------------------------------------------
    // 1) Abaixo do limite (2 < 3) -> NAO lanca.
    // -----------------------------------------------------------------
    rlSeedFailures($ip_main, 2);
    $threw = false;
    try {
        glpichat_assert_accept_not_rate_limited($ip_main);
    } catch (RuntimeException $e) {
        $threw = true;
    }
    rlAssert(!$threw, 'Abaixo do limite (2<3) NAO deve lancar rate limit');
    rlPass('Abaixo do limite: aceite nao e bloqueado');

    // -----------------------------------------------------------------
    // 2) No limite (3 >= 3) -> lanca RuntimeException codigo 429 com PT.
    // -----------------------------------------------------------------
    rlSeedFailures($ip_main, 1); // total = 3
    $threw = false;
    $code = 0;
    $msg = '';
    try {
        glpichat_assert_accept_not_rate_limited($ip_main);
    } catch (RuntimeException $e) {
        $threw = true;
        $code = $e->getCode();
        $msg = $e->getMessage();
    }
    rlAssert($threw, 'No limite (>=3) deve lancar rate limit');
    rlAssert($code === 429, 'Rate limit deve usar codigo HTTP 429 - veio: ' . $code);
    rlAssert(str_contains($msg, 'Muitas tentativas. Tente novamente mais tarde.'), 'Rate limit deve usar a mensagem PT real - veio: ' . $msg);
    rlPass('No limite: aceite e bloqueado com 429 e mensagem PT real');

    // -----------------------------------------------------------------
    // 3) Isolamento por IP: outro IP nao e afetado.
    // -----------------------------------------------------------------
    $threw = false;
    try {
        glpichat_assert_accept_not_rate_limited($ip_other);
    } catch (RuntimeException $e) {
        $threw = true;
    }
    rlAssert(!$threw, 'IP diferente nao deve herdar o rate limit de outro IP');
    rlPass('Rate limit e isolado por IP');

    // -----------------------------------------------------------------
    // 4) Fora da janela: tentativas antigas (2h atras) nao contam.
    // -----------------------------------------------------------------
    rlSeedFailures($ip_old, 5, 7200); // 5 falhas, mas com 2h de idade (> janela 1h)
    $threw = false;
    try {
        glpichat_assert_accept_not_rate_limited($ip_old);
    } catch (RuntimeException $e) {
        $threw = true;
    }
    rlAssert(!$threw, 'Tentativas fora da janela (antigas) nao devem contar para o rate limit');
    rlPass('Rate limit respeita a janela temporal (tentativas antigas nao contam)');

    // -----------------------------------------------------------------
    // 5) IP vazio -> no-op (nao ha o que limitar).
    // -----------------------------------------------------------------
    $threw = false;
    try {
        glpichat_assert_accept_not_rate_limited('');
    } catch (RuntimeException $e) {
        $threw = true;
    }
    rlAssert(!$threw, 'IP vazio deve ser no-op');
    rlPass('IP vazio nao e limitado (no-op)');

    // -----------------------------------------------------------------
    // 6) GuestController::accept com IP estourado -> resposta 429.
    //    O rate limit dispara ANTES da busca do convite, entao um token
    //    qualquer serve.
    // -----------------------------------------------------------------
    $request = Request::create(
        '/plugins/glpichat/Accept/tokeninvalido',
        'GET',
        [],
        [],
        [],
        ['REMOTE_ADDR' => $ip_main]
    );
    $controller = new GuestController();
    $response = $controller->accept($request, 'tokeninvalido');
    rlAssert($response->getStatusCode() === 429, 'GuestController::accept com IP estourado deve responder 429 - veio: ' . $response->getStatusCode());
    rlPass('GuestController::accept responde 429 quando o IP esta rate-limited');

    fwrite(STDOUT, "[PASS] Smoke de accept rate limit concluido\n");
} finally {
    if (isset($config_snapshot) && is_array($config_snapshot)) {
        Config::setConfigurationValues('plugin:glpichat', $config_snapshot);
    }
    $DB->delete('glpi_plugin_glpichat_auditlogs', [['id' => ['>', $baseline_auditlogs]]]);
    glpichat_clear_static_caches();
}
