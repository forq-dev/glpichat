#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
plugin_root="$(cd "$script_dir/../../.." && pwd)"

base_url="${1:-http://localhost:8080}"
ticket_id="${2:-1}"
room_type="${3:-public}"

cookie_file="/tmp/glpi_http_security.cookies"
login_html="/tmp/glpi_http_security_login.html"
ticket_html="/tmp/glpi_http_security_ticket.html"
send_no_csrf_json="/tmp/glpi_http_security_send_no_csrf.json"
send_ok_json="/tmp/glpi_http_security_send_ok.json"
upload_ok_json="/tmp/glpi_http_security_upload_ok.json"
upload_blocked_json="/tmp/glpi_http_security_upload_blocked.json"

fail() {
  printf '[FAIL] %s\n' "$1" >&2
  exit 1
}

pass() {
  printf '[PASS] %s\n' "$1"
}

extract_token() {
  sed -n 's/.*name="_glpi_csrf_token" value="\([^"]*\)".*/\1/p' "$1" | head -n1
}

extract_json_field() {
  local file="$1"
  local field="$2"
  python - "$file" "$field" <<'PY'
import json, sys
path, field = sys.argv[1], sys.argv[2]
with open(path, 'r', encoding='utf-8') as fh:
    data = json.load(fh)
value = data.get(field, '')
if isinstance(value, (dict, list)):
    print(json.dumps(value))
else:
    print(value)
PY
}

assert_contains() {
  local file="$1"
  local needle="$2"
  local message="$3"
  if ! grep -q "$needle" "$file"; then
    fail "$message"
  fi
  pass "$message"
}

assert_contains_any() {
  local file="$1"
  local needle_a="$2"
  local needle_b="$3"
  local message="$4"
  if ! grep -q "$needle_a" "$file" && ! grep -q "$needle_b" "$file"; then
    fail "$message"
  fi
  pass "$message"
}

rm -f "$cookie_file" "$login_html" "$ticket_html" \
  "$send_no_csrf_json" "$send_ok_json" "$upload_ok_json" "$upload_blocked_json"

curl -sS -c "$cookie_file" "$base_url/" -o "$login_html"
login_token="$(extract_token "$login_html")"
[[ -n "$login_token" ]] || fail "Nao foi possivel extrair CSRF do login"

curl -sS -c "$cookie_file" -b "$cookie_file" -L \
  -d "_glpi_csrf_token=$login_token&login_name=glpi&login_password=glpi&auth=local" \
  "$base_url/front/login.php" -o /tmp/glpi_http_security_after_login.html
if ! grep -q "/front/logout.php" /tmp/glpi_http_security_after_login.html; then
  fail "Login HTTP no GLPI falhou"
fi
pass "Login HTTP autenticado no GLPI"

curl -sS -b "$cookie_file" "$base_url/front/ticket.form.php?id=$ticket_id" -o "$ticket_html"
ticket_token="$(extract_token "$ticket_html")"
[[ -n "$ticket_token" ]] || fail "Nao foi possivel extrair CSRF do ticket"
pass "CSRF do ticket extraido"

curl -sS -b "$cookie_file" \
  -H "X-Requested-With: XMLHttpRequest" \
  -d "tickets_id=$ticket_id&room_type=$room_type&content=smoke-sem-csrf" \
  "$base_url/plugins/glpichat/Send" -o "$send_no_csrf_json"
assert_contains_any "$send_no_csrf_json" "Invalid CSRF token." "A ação que você requisitou não é permitida." "POST /Send sem CSRF falha"

curl -sS -b "$cookie_file" \
  -H "X-Requested-With: XMLHttpRequest" \
  -H "X-Glpi-Csrf-Token: $ticket_token" \
  -d "_glpi_csrf_token=$ticket_token&tickets_id=$ticket_id&room_type=$room_type&content=smoke-com-csrf" \
  "$base_url/plugins/glpichat/Send" -o "$send_ok_json"
assert_contains "$send_ok_json" "\"message_id\":" "POST /Send com CSRF cria mensagem"
ticket_token="$(extract_json_field "$send_ok_json" "_glpi_csrf_token")"
[[ -n "$ticket_token" ]] || fail "Resposta de /Send nao retornou novo CSRF"
pass "POST /Send retornou novo CSRF"

curl -sS -b "$cookie_file" \
  -H "X-Requested-With: XMLHttpRequest" \
  -H "X-Glpi-Csrf-Token: $ticket_token" \
  -F "_glpi_csrf_token=$ticket_token" \
  -F "tickets_id=$ticket_id" \
  -F "room_type=$room_type" \
  -F "file=@$plugin_root/tests/fixtures/allowed_upload.txt;type=text/plain" \
  "$base_url/plugins/glpichat/Upload" -o "$upload_ok_json"
assert_contains "$upload_ok_json" "\"documents_id\":" "POST /Upload com arquivo permitido funciona"
ticket_token="$(extract_json_field "$upload_ok_json" "_glpi_csrf_token")"
[[ -n "$ticket_token" ]] || fail "Resposta de /Upload permitido nao retornou novo CSRF"
pass "POST /Upload permitido retornou novo CSRF"

curl -sS -b "$cookie_file" \
  -H "X-Requested-With: XMLHttpRequest" \
  -H "X-Glpi-Csrf-Token: $ticket_token" \
  -F "_glpi_csrf_token=$ticket_token" \
  -F "tickets_id=$ticket_id" \
  -F "room_type=$room_type" \
  -F "file=@$plugin_root/tests/fixtures/blocked_upload.html;type=text/html" \
  "$base_url/plugins/glpichat/Upload" -o "$upload_blocked_json"
assert_contains "$upload_blocked_json" "permitido pelo GLPI" "POST /Upload com .html bloqueado falha"

printf '[PASS] Smoke HTTP autenticado concluido\n'
