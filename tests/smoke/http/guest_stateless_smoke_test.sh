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
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
plugin_root="$(cd "$script_dir/../../.." && pwd)"
container_name="glpi"
GLPI_INSTALL_DIR="${GLPI_INSTALL_DIR:-/var/www/glpi}"
container_plugin_root="${GLPI_INSTALL_DIR}/plugins/glpichat"

base_url="${1:-http://localhost:8080}"
GLPICHAT_TICKET_ID="${2:-${GLPICHAT_TICKET_ID:-1}}"

# Nome do cookie (constante PHP: GLPICHAT_GUEST_COOKIE = 'glpichat_guest_session')
GUEST_COOKIE_NAME="glpichat_guest_session"

# Arquivos temporarios no host
sync_ok_json="/tmp/glpichat_guest_smoke_sync_ok.json"
sync_no_cookie_json="/tmp/glpichat_guest_smoke_sync_no_cookie.json"
sync_bad_cookie_json="/tmp/glpichat_guest_smoke_sync_bad_cookie.json"
send_ok_json="/tmp/glpichat_guest_smoke_send_ok.json"
send_no_cookie_json="/tmp/glpichat_guest_smoke_send_no_cookie.json"
upload_ok_json="/tmp/glpichat_guest_smoke_upload_ok.json"
# Scripts PHP gravados em arquivo (evita docker exec php - que nao le stdin em todos os ambientes CI)
php_setup_script="/tmp/glpichat_guest_smoke_setup.php"
php_cleanup_script="/tmp/glpichat_guest_smoke_cleanup.php"

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
    local actual="$1" expected="$2" message="$3"
    if [[ "$actual" != "$expected" ]]; then
        fail "$message (esperado HTTP $expected, recebido $actual)"
    fi
    pass "$message"
}

assert_json_field_int() {
    local file="$1" field="$2" message="$3"
    local value
    value="$(python3 -c "
import json, sys
with open('$file', 'r') as fh:
    data = json.load(fh)
v = data.get('$field')
print(v if isinstance(v, int) else '')
")"
    if [[ -z "$value" ]]; then
        fail "$message (campo '$field' ausente ou nao inteiro: $(cat "$file"))"
    fi
    pass "$message"
}

assert_json_has_arrays() {
    local file="$1" message="$2"
    local ok
    ok="$(python3 -c "
import json
with open('$file', 'r') as fh:
    data = json.load(fh)
has_messages = 'messages' in data and isinstance(data['messages'], list)
has_events   = 'events'   in data and isinstance(data['events'],   list)
print('yes' if (has_messages or has_events) else 'no')
")"
    if [[ "$ok" != "yes" ]]; then
        fail "$message (sem campo messages/events em $(cat "$file"))"
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
        rm -f "$php_setup_script" "$php_cleanup_script"
        return 0
    fi

    printf '\n[INFO] Executando cleanup do smoke guest stateless...\n'

    # Escreve o script de cleanup em arquivo no host e copia para o container.
    cat > "$php_cleanup_script" <<PHPEOF
<?php
\$participant_id    = (int)(\$_ENV['P_ID']  ?? 0);
\$invite_id         = (int)(\$_ENV['I_ID']  ?? 0);
\$original_followup = (string)(\$_ENV['ORIG_F'] ?? '-1');
\$loader = require '${GLPI_INSTALL_DIR}/vendor/autoload.php';
\$loader->addPsr4('GlpiPlugin\\\\Glpichat\\\\', '${GLPI_INSTALL_DIR}/plugins/glpichat/src/');
(new \Glpi\Kernel\Kernel())->boot();
global \$DB;
if (\$participant_id > 0) {
    \$DB->delete('glpi_plugin_glpichat_participants', ['id' => \$participant_id]);
}
if (\$invite_id > 0) {
    \$DB->delete('glpi_plugin_glpichat_invites', ['id' => \$invite_id]);
}
if (\$original_followup !== '-1') {
    Config::setConfigurationValues('plugin:glpichat', ['external_followup_user_id' => \$original_followup]);
}
PHPEOF

    docker cp "$php_cleanup_script" "${container_name}:/tmp/glpichat_guest_smoke_cleanup.php"
    docker exec \
        -e P_ID="$guest_participant_id" \
        -e I_ID="$invite_id" \
        -e ORIG_F="$original_followup_user" \
        "$container_name" \
        php /tmp/glpichat_guest_smoke_cleanup.php >/dev/null 2>&1 || true
    docker exec "$container_name" rm -f /tmp/glpichat_guest_smoke_cleanup.php || true

    printf '[INFO] Cleanup concluido (participant_id=%s invite_id=%s)\n' \
        "$guest_participant_id" "$invite_id"
    rm -f "$php_setup_script" "$php_cleanup_script"
}
trap run_cleanup EXIT

rm -f "$sync_ok_json" "$sync_no_cookie_json" "$sync_bad_cookie_json" \
      "$send_ok_json" "$send_no_cookie_json" "$upload_ok_json"

# ---------------------------------------------------------------------------
# FASE 0 — Setup PHP: criar participante guest diretamente no banco
# ---------------------------------------------------------------------------
printf '\n[INFO] Fase 0: criando participante guest via PHP (ticket_id=%s)...\n' \
    "$GLPICHAT_TICKET_ID"

# Escreve o script de setup em arquivo no host para evitar o problema de
# "docker exec php -" nao ler stdin em alguns ambientes CI.
cat > "$php_setup_script" <<PHPEOF
<?php
declare(strict_types=1);
try {
\$tickets_id = (int)(\$_ENV['TICKET_ID'] ?? 1);
\$loader = require '${GLPI_INSTALL_DIR}/vendor/autoload.php';
\$loader->addPsr4('GlpiPlugin\\\\Glpichat\\\\', '${GLPI_INSTALL_DIR}/plugins/glpichat/src/');
(new \Glpi\Kernel\Kernel())->boot();
if (!function_exists('plugin_init_glpichat')) { require '${GLPI_INSTALL_DIR}/plugins/glpichat/setup.php'; }
if (!function_exists('plugin_glpichat_install')) { require '${GLPI_INSTALL_DIR}/plugins/glpichat/hook.php'; }
plugin_init_glpichat();
global \$DB;

\$rooms_id = glpichat_room_id(\$tickets_id, GLPICHAT_ROOM_PUBLIC);
if (!\$rooms_id) { fwrite(STDERR, "[FAIL] rooms_id nao encontrado\n"); exit(1); }

\$current_followup = (int) Config::getConfigurationValue('plugin:glpichat', 'external_followup_user_id');
if (\$current_followup <= 0 || !glpichat_is_valid_external_followup_user_id(\$current_followup)) {
    \$row = \$DB->request(['SELECT'=>['id'],'FROM'=>'glpi_users',
        'WHERE'=>['name'=>'glpi','is_active'=>1,'is_deleted'=>0],'LIMIT'=>1])->current();
    \$fb = is_array(\$row) ? (int)(\$row['id'] ?? 0) : 0;
    if (\$fb <= 0) { fwrite(STDERR, "[FAIL] usuario glpi nao encontrado\n"); exit(1); }
    Config::setConfigurationValues('plugin:glpichat', ['external_followup_user_id' => (string)\$fb]);
    echo "FOLLOWUP_USER_CONFIGURED={\$fb}\n";
    echo "ORIGINAL_FOLLOWUP_USER={\$current_followup}\n";
} else {
    echo "FOLLOWUP_USER_CONFIGURED={\$current_followup}\n";
    echo "ORIGINAL_FOLLOWUP_USER=-1\n";
}

\$token      = bin2hex(random_bytes(16));
\$now        = date('Y-m-d H:i:s');
\$expires_at = date('Y-m-d H:i:s', time() + 3600);
\$DB->insert('glpi_plugin_glpichat_invites', [
    'rooms_id'=>\$rooms_id,'tickets_id'=>\$tickets_id,'token_hash'=>hash('sha256',\$token),
    'guest_name'=>'Smoke Stateless','guest_email'=>'','created_by'=>0,
    'accepted_at'=>null,'revoked_at'=>null,'expires_at'=>\$expires_at,'date_creation'=>\$now,
]);
\$invite_id = (int)\$DB->insertId();
if (\$invite_id <= 0) { fwrite(STDERR, "[FAIL] insert invite\n"); exit(1); }

\$session_token = bin2hex(random_bytes(16));
\$DB->insert('glpi_plugin_glpichat_participants', [
    'rooms_id'=>\$rooms_id,'users_id'=>0,'guest_name'=>'Smoke Stateless','guest_email'=>'',
    'participant_type'=>GLPICHAT_ACTOR_GUEST,
    'session_token_hash'=>hash('sha256',\$session_token),
    'joined_at'=>\$now,'last_activity'=>\$now,'left_at'=>null,
]);
\$participant_id = (int)\$DB->insertId();
if (\$participant_id <= 0) { fwrite(STDERR, "[FAIL] insert participant\n"); exit(1); }
\$DB->update('glpi_plugin_glpichat_invites', ['accepted_at'=>\$now], ['id'=>\$invite_id]);

echo "GUEST_SESSION_TOKEN={\$session_token}\n";
echo "GUEST_PARTICIPANT_ID={\$participant_id}\n";
echo "GUEST_INVITE_ID={\$invite_id}\n";
} catch (\Throwable \$e) {
    fwrite(STDERR, "[FAIL] Excecao no setup PHP: " . \$e->getMessage() . "\n" . \$e->getTraceAsString() . "\n");
    exit(1);
}
PHPEOF

docker cp "$php_setup_script" "${container_name}:/tmp/glpichat_guest_smoke_setup.php"
setup_output="$(docker exec \
    -e TICKET_ID="$GLPICHAT_TICKET_ID" \
    "$container_name" \
    php /tmp/glpichat_guest_smoke_setup.php)"
setup_exit=$?
docker exec "$container_name" rm -f /tmp/glpichat_guest_smoke_setup.php || true

if [[ "$setup_exit" -ne 0 ]]; then
    fail "Setup PHP falhou (exit=$setup_exit): $setup_output"
fi

guest_session_token="$(printf '%s' "$setup_output" | sed -n 's/^GUEST_SESSION_TOKEN=\(.*\)/\1/p' | head -n1)"
guest_participant_id="$(printf '%s' "$setup_output" | sed -n 's/^GUEST_PARTICIPANT_ID=\([0-9]*\)/\1/p' | head -n1)"
invite_id="$(printf '%s' "$setup_output" | sed -n 's/^GUEST_INVITE_ID=\([0-9]*\)/\1/p' | head -n1)"
_orig="$(printf '%s' "$setup_output" | sed -n 's/^ORIGINAL_FOLLOWUP_USER=\(-\?[0-9]*\)/\1/p' | head -n1)"
[[ -n "$_orig" ]] && original_followup_user="$_orig"

[[ -n "$guest_session_token" ]]          || fail "Setup nao retornou GUEST_SESSION_TOKEN"
[[ "${guest_participant_id:-0}" -gt 0 ]] || fail "Setup nao retornou GUEST_PARTICIPANT_ID valido"
[[ "${invite_id:-0}" -gt 0 ]]           || fail "Setup nao retornou GUEST_INVITE_ID valido"

printf '[INFO] Participante criado: id=%s invite_id=%s\n' "$guest_participant_id" "$invite_id"
pass "Fase 0: participante guest criado no banco via PHP"

# ---------------------------------------------------------------------------
# FASE 1 — GET /Guest/Sync com cookie valido → 200 + JSON com messages/events
# ---------------------------------------------------------------------------
printf '\n[INFO] Fase 1: GET /Guest/Sync...\n'
sync_status="$(curl -sS -o "$sync_ok_json" -w "%{http_code}" \
    -H "Cookie: ${GUEST_COOKIE_NAME}=${guest_session_token}" \
    -H "X-Requested-With: XMLHttpRequest" \
    "${base_url}/plugins/glpichat/Guest/Sync?last_message_id=0&last_event_id=0")"
assert_http_status "$sync_status" "200" "GET /Guest/Sync com cookie valido retorna 200"
assert_json_has_arrays "$sync_ok_json" "GET /Guest/Sync retornou JSON com campo messages/events"

# ---------------------------------------------------------------------------
# FASE 2 — POST /Guest/Send com cookie valido → 200 + JSON com message_id int
# ---------------------------------------------------------------------------
printf '\n[INFO] Fase 2: POST /Guest/Send...\n'
send_status="$(curl -sS -o "$send_ok_json" -w "%{http_code}" \
    -X POST \
    -H "Cookie: ${GUEST_COOKIE_NAME}=${guest_session_token}" \
    -H "X-Requested-With: XMLHttpRequest" \
    -d "content=smoke-stateless-test" \
    "${base_url}/plugins/glpichat/Guest/Send")"
assert_http_status "$send_status" "200" "POST /Guest/Send com cookie valido retorna 200"
assert_json_field_int "$send_ok_json" "message_id" "POST /Guest/Send retornou message_id inteiro"

# A rota de upload compartilha o throttle do fluxo guest; aguardamos fora da janela padrão.
sleep 3

# ---------------------------------------------------------------------------
# FASE 3 — POST /Guest/Upload com cookie valido → 200 + JSON com documents_id int
# ---------------------------------------------------------------------------
printf '\n[INFO] Fase 3: POST /Guest/Upload...\n'
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
sync_no_status="$(curl -sS -o "$sync_no_cookie_json" -w "%{http_code}" \
    -H "X-Requested-With: XMLHttpRequest" \
    "${base_url}/plugins/glpichat/Guest/Sync?last_message_id=0&last_event_id=0")"
assert_http_status "$sync_no_status" "400" "GET /Guest/Sync sem cookie retorna 400"

send_no_status="$(curl -sS -o "$send_no_cookie_json" -w "%{http_code}" \
    -X POST \
    -H "X-Requested-With: XMLHttpRequest" \
    -d "content=x" \
    "${base_url}/plugins/glpichat/Guest/Send")"
assert_http_status "$send_no_status" "400" "POST /Guest/Send sem cookie retorna 400"

# ---------------------------------------------------------------------------
# FASE 5 — Erros com cookie invalido → 400
# ---------------------------------------------------------------------------
printf '\n[INFO] Fase 5: cookie invalido...\n'
sync_bad_status="$(curl -sS -o "$sync_bad_cookie_json" -w "%{http_code}" \
    -H "Cookie: ${GUEST_COOKIE_NAME}=token_invalido_xxx_smoke" \
    -H "X-Requested-With: XMLHttpRequest" \
    "${base_url}/plugins/glpichat/Guest/Sync?last_message_id=0&last_event_id=0")"
assert_http_status "$sync_bad_status" "400" "GET /Guest/Sync com cookie invalido retorna 400"

# ---------------------------------------------------------------------------
# FASE 6 — Cleanup
# ---------------------------------------------------------------------------
printf '\n[INFO] Fase 6: cleanup...\n'
run_cleanup

rm -f "$sync_ok_json" "$sync_no_cookie_json" "$sync_bad_cookie_json" \
      "$send_ok_json" "$send_no_cookie_json" "$upload_ok_json"

printf '[PASS] Smoke HTTP stateless das rotas Guest concluido\n'
