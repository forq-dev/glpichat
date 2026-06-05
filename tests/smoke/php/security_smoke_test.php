<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\File\UploadedFile;

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

final class OversizedUploadedFile extends UploadedFile
{
    private int $forcedSize;

    public function __construct(string $path, string $originalName, int $forcedSize)
    {
        parent::__construct($path, $originalName, null, null, true);
        $this->forcedSize = $forcedSize;
    }

    public function getSize(): int
    {
        return $this->forcedSize;
    }
}

/**
 * @return never
 */
function failTest(string $message): void
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function passTest(string $message): void
{
    fwrite(STDOUT, "[PASS] {$message}\n");
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        failTest($message);
    }
}

function assertThrows(callable $callback, string $expectedMessageFragment, string $message): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        assertTrue(
            str_contains($error->getMessage(), $expectedMessageFragment),
            $message . ' - unexpected error: ' . $error->getMessage()
        );
        passTest($message);
        return;
    }

    failTest($message . ' - no exception thrown');
}

function uploadMatches(string $basename): array
{
    $pattern = rtrim(GLPI_UPLOAD_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*_' . $basename;
    $matches = glob($pattern);
    if (!is_array($matches)) {
        return [];
    }

    return $matches;
}

function assertNoUploadArtifacts(string $basename, string $message): void
{
    $matches = uploadMatches($basename);
    assertTrue(count($matches) === 0, $message . ' - artifacts: ' . implode(', ', $matches));
    passTest($message);
}

function seedValidSession(): void
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
            'glpi_users.is_active'               => 1,
            'glpi_users.is_deleted'              => 0,
            'glpi_profiles_users.profiles_id'    => ['>', 0],
            'glpi_profiles_users.entities_id'    => ['>=', 0],
        ],
        'LIMIT' => 1,
    ])->current();

    assertTrue(is_array($row), 'Sessão de teste precisa de um usuário GLPI ativo com perfil');

    $_SESSION['valid_id'] = session_id();
    $_SESSION['glpiname'] = (string) $row['user_name'];
    $_SESSION['glpiID'] = (int) $row['users_id'];
    $_SESSION['glpiactiveprofile'] = [
        'id'                 => (int) $row['profiles_id'],
        'last_rights_update' => $row['last_rights_update'],
    ];
    $_SESSION['glpiactive_entity'] = (int) $row['entities_id'];
}

seedValidSession();

// CSRF checking is delegated to GLPI core listener, so glpichat_require_logged_csrf only checks active session now.
// $validToken = Session::getNewCSRFToken();
// glpichat_require_logged_csrf([
//     '_glpi_csrf_token' => $validToken,
// ]);
// passTest('CSRF token válido é aceito');
// 
// assertThrows(
//     static function (): void {
//         glpichat_require_logged_csrf([]);
//     },
//     'Invalid CSRF token.',
//     'CSRF ausente é rejeitado'
// );

$blockedFixture = dirname(__DIR__, 2) . '/fixtures/blocked_upload.php';
$blockedBasename = basename($blockedFixture);
assertThrows(
    static function () use ($blockedFixture): void {
        $file = new UploadedFile($blockedFixture, 'blocked_upload.php', 'application/x-php', null, true);
        glpichat_upload_document(1, $file, true);
    },
    'not allowed by GLPI',
    'Upload com extensão bloqueada é rejeitado'
);
assertNoUploadArtifacts($blockedBasename, 'Upload bloqueado não deixa artefato em disco');

$blockedHtmlFixture = dirname(__DIR__, 2) . '/fixtures/blocked_upload.html';
$blockedHtmlBasename = basename($blockedHtmlFixture);
assertThrows(
    static function () use ($blockedHtmlFixture): void {
        $file = new UploadedFile($blockedHtmlFixture, 'blocked_upload.html', 'text/html', null, true);
        glpichat_upload_document(1, $file, true);
    },
    'not allowed by GLPI',
    'Upload de .html bloqueado é rejeitado'
);
assertNoUploadArtifacts($blockedHtmlBasename, 'Upload de .html bloqueado não deixa artefato em disco');

$glpiMaxSize = (int) (($CFG_GLPI['document_max_size'] ?? 0) * 1024 * 1024);
$phpMaxSize = Toolbox::getPhpUploadSizeLimit();
$effectiveMaxSize = 0;
if ($glpiMaxSize > 0 && $phpMaxSize > 0) {
    $effectiveMaxSize = min($glpiMaxSize, $phpMaxSize);
} elseif ($glpiMaxSize > 0) {
    $effectiveMaxSize = $glpiMaxSize;
} else {
    $effectiveMaxSize = $phpMaxSize;
}

assertTrue($effectiveMaxSize > 0, 'Limite efetivo de upload deve ser positivo para o smoke test');

$oversizedPath = tempnam(sys_get_temp_dir(), 'glpctest_');
if ($oversizedPath === false) {
    failTest('Não foi possível criar arquivo temporário para teste de tamanho');
}
file_put_contents($oversizedPath, 'tiny');

$oversizedName = 'oversized-smoke.txt';
assertThrows(
    static function () use ($oversizedPath, $oversizedName, $effectiveMaxSize): void {
        $file = new OversizedUploadedFile($oversizedPath, $oversizedName, $effectiveMaxSize + 1);
        glpichat_upload_document(1, $file, true);
    },
    'exceeds the',
    'Upload acima do limite é rejeitado'
);
@unlink($oversizedPath);
assertNoUploadArtifacts($oversizedName, 'Upload oversized não deixa artefato em disco');

fwrite(STDOUT, "[PASS] Smoke de segurança concluído\n");
