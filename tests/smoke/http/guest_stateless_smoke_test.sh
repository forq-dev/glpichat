#!/usr/bin/env bash
# guest_stateless_smoke_test.sh — Smoke HTTP stateless das rotas Guest
#
# Valida via HTTP real (curl, sem sessao GLPI) que as rotas /Guest/Sync,
# /Guest/Send e /Guest/Upload funcionam corretamente usando apenas o cookie
# de sessao guest (glpichat_guest_session).
#
# Uso:
#   bash tests/smoke/http/guest_stateless_smoke_test.sh [base_url] [ticket_id]
#
# Exemplos:
#   bash tests/smoke/http/guest_stateless_smoke_test.sh
#   bash tests/smoke/http/guest_stateless_smoke_test.sh http://localhost:8080 1
#
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
plugin_root="$(cd "$script_dir/../../.." && pwd)"
container_name="glpi"
container_plugin_root="/var/www/glpi/plugins/glpichat"

base_url="${1:-http://localhost:8080}"
GLPICHAT_TICKET_ID="${2:-${GLPICHAT_TICKET_ID:-1}}"

# Nome do cookie: GLPICHAT_GUEST_COOKIE = 'glpichat_guest_session' (definido em src/domain/domain.php)
GUEST_COOKIE_NAME="glpichat_guest_session"

# Arquivos temporarios
sync_ok_json="/tmp/glpichat_guest_smoke_sync_ok.json"
sync_no_cookie_json="/tmp/glpichat_guest_smoke_sync_no_cookie.json"
sync_bad_cookie_json="/tmp/glpichat_guest_smoke_sync_bad_cookie.json"
send_ok_json="/tmp/glpichat_guest_smoke_send_ok.json"
send_no_cookie_json="/tmp/glpichat_guest_smoke_send_no_cookie.json"
upload_ok_json="/tmp/glpichat_guest_smoke_upload_ok.json"

# Variaveis de estado para cleanup
guest_participant_id=0
invite_id=0
guest_session_token=""
original_followup_user=-1  # -1 = nao modificado pelo smoke

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
fail() {
    printf '[FAIL] %s\n' "$1" >&2
    exit 1
}

pass() {
    printf '[PASS] %s\n' "$1"
}

assert_http_status() {
    local actual="$1"
    local expected="$2"
    local message="$3"
    if [[ "$actual" != "$expected" ]]; then
        fail "$message (esperado HTTP $expected, recebido $actual)"
    fi
    pass "$message"
}

assert_json_field_int() {
    local file="$1"
    local field="$2"
    local message="$3"
    local value
    value="$(python3 - "$file" "$field" <<'PY'
import json, sys
path, field = sys.argv[1], sys.argv[2]
with open(path, 'r', encoding='utf-8') as fh:
    data = json.load(fh)
value = data.get(field)
if isinstance(value, int):
    print(value)
else:
    print('')
PY
)"
    if [[ -z "$value" ]]; then
        fail "$message (campo '$field' ausente ou nao inteiro em $file: $(cat "$file"))"
    fi
    pass "$message"
}

assert_json_has_key() {
    local file="$1"
    local key="$2"
    local message="$3"
    local found
    found="$(python3 - "$file" "$key" <<'PY'
import json, sys
path, key = sys.argv[1], sys.argv[2]
with open(path, 'r', encoding='utf-8') as fh:
    data = json.load(fh)
print('yes' if key in data else 'no')
PY
)"
    if [[ "$found" != "yes" ]]; then
        fail "$message (chave '$key' ausente em $(cat "$file"))"
    fi
    pass "$message"
}

# ---------------------------------------------------------------------------
# Cleanup (trap EXIT)
# ---------------------------------------------------------------------------
cleanup_done=0
run_cleanup() {
    [[ "$cleanup_done" -eq 1 ]] && return 0
    cleanup_done=1

    if [[ "$guest_participant_id" -le 0 && "$invite_id" -le 0 && "$original_followup_user" -eq -1 ]]; then
        return 0
    fi

    printf '\n[INFO] Executando cleanup do smoke guest stateless...\n'

    local pid_to_delete="$guest_participant_id"
    local iid_to_delete="$invite_id"
    local orig_followup="$original_followup_user"

    docker exec "$container_name" php - \
        "$pid_to_delete" \
        "$iid_to_delete" \
        "$orig_followup" \
        <<'PHP' >/dev/null 2>&1 || true
<?php
$participant_id    = (int) ($argv[1] ?? 0);
$invite_id         = (int) ($argv[2] ?? 0);
$original_followup = isset($argv[3]) ? (string) $argv[3] : '-1';

$loader = require '/var/www/glpi/vendor/autoload.php';
$loader->addPsr4('GlpiPlugin\\Glpichat\\', '/var/www/glpi/plugins/glpichat/src/');

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

global $DB;

if ($participant_id > 0) {
    $DB->delete('glpi_plugin_glpichat_participants', ['id' => $participant_id]);
}
if ($invite_id > 0) {
    $DB->delete('glpi_plugin_glpichat_invites', ['id' => $invite_id]);
}
// Restaura external_followup_user_id se o smoke o tiver modificado.
if ($original_followup !== '-1') {
    Config::setConfigurationValues('plugin:glpichat', [
        'external_followup_user_id' => $original_followup,
    ]);
}
PHP

    printf '[INFO] Cleanup concluido (participant_id=%s invite_id=%s)\n' \
        "$guest_participant_id" "$invite_id"
}
trap run_cleanup EXIT

rm -f "$sync_ok_json" "$sync_no_cookie_json" "$sync_bad_cookie_json" \
      "$send_ok_json" "$send_no_cookie_json" "$upload_ok_json"

# ---------------------------------------------------------------------------
# FASE 0 — Setup PHP: criar participante guest diretamente no banco
# ---------------------------------------------------------------------------
printf '\n[INFO] Fase 0: criando participante guest via PHP (ticket_id=%s)...\n' \
    "$GLPICHAT_TICKET_ID"

setup_output="$(docker exec "$container_name" php - "$GLPICHAT_TICKET_ID" <<'PHP'
<?php
declare(strict_types=1);

$tickets_id = (int) ($argv[1] ?? 1);

$loader = require '/var/www/glpi/vendor/autoload.php';
$loader->addPsr4('GlpiPlugin\\Glpichat\\', '/var/www/glpi/plugins/glpichat/src/');

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

if (!function_exists('plugin_init_glpichat')) {
    require '/var/www/glpi/plugins/glpichat/setup.php';
}
if (!function_exists('plugin_glpichat_install')) {
    require '/var/www/glpi/plugins/glpichat/hook.php';
}

plugin_init_glpichat();

global $DB;

$rooms_id = glpichat_room_id($tickets_id, GLPICHAT_ROOM_PUBLIC);
if (!$rooms_id) {
    fwrite(STDERR, "[FAIL] Nao foi possivel obter rooms_id para ticket $tickets_id (room public)\n");
    exit(1);
}

// Garante external_followup_user_id configurado (necessario para Guest/Send e Guest/Upload).
// Sem isso, glpichat_external_followup_user_id() lanca RuntimeException e o endpoint retorna 400.
$current_followup = (int) Config::getConfigurationValue('plugin:glpichat', 'external_followup_user_id');
if ($current_followup <= 0 || !glpichat_is_valid_external_followup_user_id($current_followup)) {
    // Usa o usuario 'glpi' (id=2 na instalacao padrao); faz lookup pelo name para ser robusto.
    global $DB;
    $row = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => 'glpi_users',
        'WHERE'  => ['name' => 'glpi', 'is_active' => 1, 'is_deleted' => 0],
        'LIMIT'  => 1,
    ])->current();
    $fallback_user = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    if ($fallback_user > 0) {
        Config::setConfigurationValues('plugin:glpichat', [
            'external_followup_user_id' => (string) $fallback_user,
        ]);
        // Sinaliza ao shell que a config foi modificada, para que o cleanup restaure o original.
        echo "FOLLOWUP_USER_CONFIGURED={$fallback_user}\n";
        echo "ORIGINAL_FOLLOWUP_USER={$current_followup}\n"; // era 0 ou invalido
    } else {
        fwrite(STDERR, "[FAIL] Nao foi possivel configurar external_followup_user_id (usuario glpi nao encontrado)\n");
        exit(1);
    }
} else {
    echo "FOLLOWUP_USER_CONFIGURED={$current_followup}\n";
    // Nao modificado; exporta -1 para o shell saber que nao deve restaurar.
    echo "ORIGINAL_FOLLOWUP_USER=-1\n";
}

// Cria um invite real no banco
$token          = bin2hex(random_bytes(16));
$token_hash     = hash('sha256', $token);
$now            = date('Y-m-d H:i:s');
$expires_at     = date('Y-m-d H:i:s', time() + 3600);

$DB->insert('glpi_plugin_glpichat_invites', [
    'tickets_id'   => $tickets_id,
    'token_hash'   => $token_hash,
    'guest_name'   => 'Smoke Stateless',
    'guest_email'  => '',
    'created_by'   => 0,
    'accepted_at'  => null,
    'revoked_at'   => null,
    'expires_at'   => $expires_at,
    'created_at'   => $now,
]);
$invite_id = (int) $DB->insertId();
if ($invite_id <= 0) {
    fwrite(STDERR, "[FAIL] Nao foi possivel inserir invite\n");
    exit(1);
}

// Cria o participante guest diretamente (mais simples que usar o use case)
$session_token      = bin2hex(random_bytes(16));
$session_token_hash = hash('sha256', $session_token);

$DB->insert('glpi_plugin_glpichat_participants', [
    'rooms_id'           => $rooms_id,
    'users_id'           => 0,
    'guest_name'         => 'Smoke Stateless',
    'guest_email'        => '',
    'participant_type'   => GLPICHAT_ACTOR_GUEST,
    'session_token_hash' => $session_token_hash,
    'joined_at'          => $now,
    'last_activity'      => $now,
    'left_at'            => null,
]);
$participant_id = (int) $DB->insertId();
if ($participant_id <= 0) {
    fwrite(STDERR, "[FAIL] Nao foi possivel inserir participante guest\n");
    exit(1);
}

// Marca o invite como aceito para consistencia
$DB->update('glpi_plugin_glpichat_invites', [
    'accepted_at' => $now,
], ['id' => $invite_id]);

echo "GUEST_SESSION_TOKEN={$session_token}\n";
echo "GUEST_PARTICIPANT_ID={$participant_id}\n";
echo "GUEST_INVITE_ID={$invite_id}\n";
// Exporta o valor ORIGINAL de external_followup_user_id para o cleanup restaurar.
$original_followup = (int) Config::getConfigurationValue('plugin:glpichat', 'external_followup_user_id');
echo "ORIGINAL_FOLLOWUP_USER={$original_followup}\n";
PHP
)"

if [[ $? -ne 0 ]]; then
    fail "Setup PHP falhou (docker exec)"
fi

guest_session_token="$(printf '%s' "$setup_output" | sed -n 's/^GUEST_SESSION_TOKEN=\(.*\)/\1/p' | head -n1)"
guest_participant_id="$(printf '%s' "$setup_output" | sed -n 's/^GUEST_PARTICIPANT_ID=\([0-9]*\)/\1/p' | head -n1)"
invite_id="$(printf '%s' "$setup_output" | sed -n 's/^GUEST_INVITE_ID=\([0-9]*\)/\1/p' | head -n1)"
_followup_configured="$(printf '%s' "$setup_output" | sed -n 's/^FOLLOWUP_USER_CONFIGURED=\([0-9]*\)/\1/p' | head -n1)"
_original_followup="$(printf '%s' "$setup_output" | sed -n 's/^ORIGINAL_FOLLOWUP_USER=\(-\?[0-9]*\)/\1/p' | head -n1)"

# Se o setup modificou external_followup_user_id, registra o valor original para restaurar no cleanup.
# original_followup_user=-1 significa "nao modificado; nao restaurar".
if [[ -n "$_followup_configured" && -n "$_original_followup" ]]; then
    original_followup_user="$_original_followup"
fi

[[ -n "$guest_session_token" ]] || fail "Setup nao retornou GUEST_SESSION_TOKEN"
[[ "${guest_participant_id:-0}" -gt 0 ]] || fail "Setup nao retornou GUEST_PARTICIPANT_ID valido"
[[ "${invite_id:-0}" -gt 0 ]] || fail "Setup nao retornou GUEST_INVITE_ID valido"

printf '[INFO] Participante criado: id=%s, invite_id=%s\n' \
    "$guest_participant_id" "$invite_id"
pass "Fase 0: participante guest criado no banco via PHP"

# ---------------------------------------------------------------------------
# FASE 1 — GET /Guest/Sync com cookie valido → 200 + JSON com messages/events
# ---------------------------------------------------------------------------
printf '\n[INFO] Fase 1: GET /Guest/Sync com cookie valido...\n'

sync_status="$(curl -sS -o "$sync_ok_json" -w "%{http_code}" \
    -H "Cookie: ${GUEST_COOKIE_NAME}=${guest_session_token}" \
    -H "X-Requested-With: XMLHttpRequest" \
    "${base_url}/plugins/glpichat/Guest/Sync?last_message_id=0&last_event_id=0")"

assert_http_status "$sync_status" "200" "GET /Guest/Sync com cookie valido retorna 200"

# Verifica que a resposta é JSON com messages ou events (array)
sync_has_field="$(python3 - "$sync_ok_json" <<'PY'
import json, sys
path = sys.argv[1]
with open(path, 'r', encoding='utf-8') as fh:
    data = json.load(fh)
has_messages = 'messages' in data and isinstance(data['messages'], list)
has_events   = 'events' in data and isinstance(data['events'], list)
print('yes' if (has_messages or has_events) else 'no')
PY
)"
if [[ "$sync_has_field" != "yes" ]]; then
    fail "GET /Guest/Sync nao retornou campo 'messages' ou 'events' (array) em $(cat "$sync_ok_json")"
fi
pass "GET /Guest/Sync retornou JSON com campo messages/events (array)"

# ---------------------------------------------------------------------------
# FASE 2 — POST /Guest/Send com cookie valido → 200 + JSON com message_id int
# ---------------------------------------------------------------------------
printf '\n[INFO] Fase 2: POST /Guest/Send com cookie valido...\n'

send_status="$(curl -sS -o "$send_ok_json" -w "%{http_code}" \
    -X POST \
    -H "Cookie: ${GUEST_COOKIE_NAME}=${guest_session_token}" \
    -H "X-Requested-With: XMLHttpRequest" \
    -d "content=smoke-stateless-test" \
    "${base_url}/plugins/glpichat/Guest/Send")"

assert_http_status "$send_status" "200" "POST /Guest/Send com cookie valido retorna 200"
assert_json_field_int "$send_ok_json" "message_id" "POST /Guest/Send retornou message_id inteiro"

# ---------------------------------------------------------------------------
# FASE 3 — POST /Guest/Upload com cookie valido → 200 + JSON com documents_id int
# ---------------------------------------------------------------------------
printf '\n[INFO] Fase 3: POST /Guest/Upload com cookie valido...\n'

upload_fixture="$plugin_root/tests/fixtures/allowed_upload.txt"
[[ -f "$upload_fixture" ]] || fail "Fixture de upload ausente: $upload_fixture"

upload_status="$(curl -sS -o "$upload_ok_json" -w "%{http_code}" \
    -X POST \
    -H "Cookie: ${GUEST_COOKIE_NAME}=${guest_session_token}" \
    -H "X-Requested-With: XMLHttpRequest" \
    -F "file=@${upload_fixture};type=text/plain" \
    "${base_url}/plugins/glpichat/Guest/Upload")"

assert_http_status "$upload_status" "200" "POST /Guest/Upload com cookie valido retorna 200"
assert_json_field_int "$upload_ok_json" "documents_id" "POST /Guest/Upload retornou documents_id inteiro"

# ---------------------------------------------------------------------------
# FASE 4 — Erros sem cookie → 400
# ---------------------------------------------------------------------------
printf '\n[INFO] Fase 4: erros sem cookie...\n'

sync_no_cookie_status="$(curl -sS -o "$sync_no_cookie_json" -w "%{http_code}" \
    -H "X-Requested-With: XMLHttpRequest" \
    "${base_url}/plugins/glpichat/Guest/Sync?last_message_id=0&last_event_id=0")"

assert_http_status "$sync_no_cookie_status" "400" "GET /Guest/Sync sem cookie retorna 400"

send_no_cookie_status="$(curl -sS -o "$send_no_cookie_json" -w "%{http_code}" \
    -X POST \
    -H "X-Requested-With: XMLHttpRequest" \
    -d "content=x" \
    "${base_url}/plugins/glpichat/Guest/Send")"

assert_http_status "$send_no_cookie_status" "400" "POST /Guest/Send sem cookie retorna 400"

# ---------------------------------------------------------------------------
# FASE 5 — Erros com cookie invalido → 400
# ---------------------------------------------------------------------------
printf '\n[INFO] Fase 5: erros com cookie invalido...\n'

sync_bad_status="$(curl -sS -o "$sync_bad_cookie_json" -w "%{http_code}" \
    -H "Cookie: ${GUEST_COOKIE_NAME}=token_invalido_xxx_smoke" \
    -H "X-Requested-With: XMLHttpRequest" \
    "${base_url}/plugins/glpichat/Guest/Sync?last_message_id=0&last_event_id=0")"

assert_http_status "$sync_bad_status" "400" "GET /Guest/Sync com cookie invalido retorna 400"

# ---------------------------------------------------------------------------
# FASE 6 — Cleanup (tambem roda via trap EXIT)
# ---------------------------------------------------------------------------
printf '\n[INFO] Fase 6: cleanup explicito...\n'
run_cleanup

# ---------------------------------------------------------------------------
# Limpeza de temporarios
# ---------------------------------------------------------------------------
rm -f "$sync_ok_json" "$sync_no_cookie_json" "$sync_bad_cookie_json" \
      "$send_ok_json" "$send_no_cookie_json" "$upload_ok_json"

printf '[PASS] Smoke HTTP stateless das rotas Guest concluido\n'
