<?php

declare(strict_types=1);

// Smoke: valida o contrato da aba de perfil do plugin (GlpiPlugin\Glpichat\ProfileTab):
//   - registro da aba no Profile (Plugin::registerClass / addtabon);
//   - os 5 direitos do plugin sao declarados (glpichat_right_names) e aparecem
//     na matriz renderizada por displayTabContentForItem;
//   - getTabNameForItem so retorna a aba para Profile (e nao para template/outros
//     itemtypes);
//   - displayTabContentForItem nao quebra e respeita o gating de edicao
//     (form editavel apenas com direito de profile; matriz readonly sem ele).
//
// Foca no CONTRATO (direitos declarados + gating por permissao), evitando
// asserções de HTML frageis. NAO e destrutivo; restaura a sessao no finally.

use GlpiPlugin\Glpichat\ProfileTab;

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
function ptFail(string $message): void
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function ptPass(string $message): void
{
    fwrite(STDOUT, "[PASS] {$message}\n");
}

function ptAssert(bool $condition, string $message): void
{
    if (!$condition) {
        ptFail($message);
    }
}

/**
 * Captura o HTML ecoado por um callable (displayTabContentForItem) sem vaza-lo
 * para o stdout do teste.
 */
function ptCapture(callable $fn): string
{
    ob_start();
    try {
        $fn();
    } finally {
        $out = ob_get_clean();
    }
    return is_string($out) ? $out : '';
}

function ptSeedSession(): int
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
            'glpi_users.is_active'            => 1,
            'glpi_users.is_deleted'           => 0,
            'glpi_profiles_users.profiles_id' => ['>', 0],
        ],
        'LIMIT' => 1,
    ])->current();

    ptAssert(is_array($row), 'Sessao de teste precisa de um usuario GLPI ativo com profile');

    $users_id = (int) $row['users_id'];
    $_SESSION['valid_id'] = session_id();
    $_SESSION['glpiname'] = (string) $row['user_name'];
    $_SESSION['glpiID'] = $users_id;
    Session::initEntityProfiles($users_id);
    Session::changeProfile((int) $row['profiles_id']);
    $_SESSION['glpiactive_entity'] = (int) ($row['entities_id'] ?? 0);
    $_SESSION['glpigroups'] = [];
    $_SESSION['glpicsrftokens'] = [];

    return $users_id;
}

global $DB;

ptSeedSession();

$expected_rights = [
    'plugin_glpichat_public',
    'plugin_glpichat_internal',
    'plugin_glpichat_invite',
    'plugin_glpichat_view_audit',
    'plugin_glpichat_admin',
];

// Snapshot do direito de 'profile' na sessao para restaurar no finally.
$profile_right_snapshot = $_SESSION['glpiactiveprofile']['profile'] ?? null;

try {
    // -----------------------------------------------------------------
    // 1) ProfileTab registrada como aba do Profile (addtabon => Profile)
    // -----------------------------------------------------------------
    $registered_tabs = \CommonGLPI::getOtherTabs(\Profile::class);
    ptAssert(
        in_array(ProfileTab::class, $registered_tabs, true),
        'ProfileTab deve estar registrada como aba do Profile (encontradas: ' . implode(', ', $registered_tabs) . ')'
    );
    ptPass('ProfileTab esta registrada como aba do Profile (Plugin::registerClass/addtabon)');

    // -----------------------------------------------------------------
    // 2) Os 5 direitos do plugin sao declarados (contrato canonico)
    // -----------------------------------------------------------------
    $declared = glpichat_right_names();
    ptAssert(count($declared) === 5, 'glpichat_right_names deve declarar exatamente 5 direitos');
    foreach ($expected_rights as $right) {
        ptAssert(in_array($right, $declared, true), "Direito esperado ausente em glpichat_right_names: {$right}");
    }
    ptPass('Os 5 direitos do plugin estao declarados em glpichat_right_names');

    // -----------------------------------------------------------------
    // 3) getTabNameForItem so retorna a aba para Profile
    // -----------------------------------------------------------------
    $tab = new ProfileTab();
    $profile = new \Profile();
    ptAssert($profile->getFromDB(4) || $profile->getFromDB(1), 'Deve existir ao menos um Profile para o teste');

    $name_for_profile = $tab->getTabNameForItem($profile, 0);
    ptAssert($name_for_profile !== '', 'getTabNameForItem deve retornar uma aba para um Profile');

    $name_for_template = $tab->getTabNameForItem($profile, 1);
    ptAssert($name_for_template === '', 'getTabNameForItem deve retornar vazio quando withtemplate');

    $ticket = new \Ticket();
    $name_for_ticket = $tab->getTabNameForItem($ticket, 0);
    ptAssert($name_for_ticket === '', 'getTabNameForItem deve retornar vazio para itemtype != Profile');
    ptPass('getTabNameForItem so expoe a aba para Profile (vazio p/ template e outros itemtypes)');

    // -----------------------------------------------------------------
    // 4) displayTabContentForItem nao quebra e renderiza os 5 direitos
    //    (a matriz monta os names dos checkboxes a partir do campo do direito)
    // -----------------------------------------------------------------
    $html = ptCapture(static function () use ($profile): void {
        $result = ProfileTab::displayTabContentForItem($profile, 1, 0);
        ptAssert($result === true, 'displayTabContentForItem deve retornar true para Profile');
    });
    ptAssert($html !== '', 'displayTabContentForItem deve renderizar conteudo para Profile');
    foreach ($expected_rights as $right) {
        ptAssert(
            str_contains($html, $right),
            "A matriz de direitos deve referenciar o campo {$right} no conteudo renderizado"
        );
    }
    ptPass('displayTabContentForItem renderiza a matriz com os 5 direitos do plugin');

    // -----------------------------------------------------------------
    // 4b) displayTabContentForItem retorna false para itemtype != Profile
    // -----------------------------------------------------------------
    $non_profile_result = null;
    $html_non_profile = ptCapture(static function () use ($ticket, &$non_profile_result): void {
        $non_profile_result = ProfileTab::displayTabContentForItem($ticket, 1, 0);
    });
    ptAssert($non_profile_result === false, 'displayTabContentForItem deve retornar false para itemtype != Profile');
    ptPass('displayTabContentForItem rejeita itemtype != Profile sem quebrar');

    // -----------------------------------------------------------------
    // 5) Gating de edicao: form editavel apenas com direito de 'profile'
    // -----------------------------------------------------------------
    // Com direito de profile (CREATE|UPDATE) -> renderiza o form de edicao.
    $_SESSION['glpiactiveprofile']['profile'] = READ | CREATE | UPDATE | PURGE;
    $html_editable = ptCapture(static function () use ($profile): void {
        ProfileTab::displayTabContentForItem($profile, 1, 0);
    });
    ptAssert(str_contains($html_editable, '<form'), 'Com direito de profile, a aba deve renderizar o form de edicao');
    ptAssert(str_contains($html_editable, '_glpi_csrf_token'), 'O form editavel deve incluir o token CSRF');

    // Sem direito de profile -> matriz readonly, sem form.
    $_SESSION['glpiactiveprofile']['profile'] = 0;
    $html_readonly = ptCapture(static function () use ($profile): void {
        $result = ProfileTab::displayTabContentForItem($profile, 1, 0);
        ptAssert($result === true, 'displayTabContentForItem deve retornar true mesmo readonly');
    });
    ptAssert(!str_contains($html_readonly, '<form'), 'Sem direito de profile, a aba NAO deve renderizar o form de edicao');
    // Mesmo readonly, os direitos continuam sendo exibidos.
    foreach ($expected_rights as $right) {
        ptAssert(
            str_contains($html_readonly, $right),
            "A matriz readonly ainda deve referenciar o campo {$right}"
        );
    }
    ptPass('Gating de edicao respeitado: form editavel com direito de profile, readonly sem ele');

    fwrite(STDOUT, "[PASS] Smoke da ProfileTab concluido\n");
} finally {
    if ($profile_right_snapshot === null) {
        unset($_SESSION['glpiactiveprofile']['profile']);
    } else {
        $_SESSION['glpiactiveprofile']['profile'] = $profile_right_snapshot;
    }

    glpichat_clear_static_caches();

    unset($_SESSION['valid_id'], $_SESSION['glpiname'], $_SESSION['glpiID']);
    unset($_SESSION['glpiactiveprofile'], $_SESSION['glpiactive_entity'], $_SESSION['glpigroups']);
    unset($_SESSION['glpicsrftokens']);
}
