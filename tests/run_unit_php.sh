#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
plugin_root="$(cd "$script_dir/.." && pwd)"
container_name="glpi"

suite="${1:-all}"
base_url="${2:-http://localhost:8080}"
ticket_id="${3:-1}"
room_type="${4:-public}"

print_help() {
  cat <<'EOF'
Uso:
  ./tests/run_unit_php.sh [suite] [base_url] [ticket_id] [room_type]

Suites disponiveis:
  all         Roda tudo: unit-php, unit-js, smoke-php, smoke-http e e2e
  unit        Alias para unit-php + unit-js
  unit-php    Roda apenas os testes unitarios PHP no container glpi
  unit-js     Roda apenas os testes unitarios JS via Vitest
  smoke       Alias para smoke-php + smoke-http
  smoke-php   Roda apenas os smokes PHP no container glpi
  smoke-http  Roda apenas o smoke HTTP autenticado
  e2e         Roda apenas o E2E Python com Playwright
  help        Exibe esta ajuda

Parametros adicionais:
  base_url    Usado por smoke-http e e2e. Padrao: http://localhost:8080
  ticket_id   Usado por smoke-http. Padrao: 1
  room_type   Usado por smoke-http. Padrao: public

Exemplos:
  ./tests/run_unit_php.sh
  ./tests/run_unit_php.sh unit
  ./tests/run_unit_php.sh unit-php
  ./tests/run_unit_php.sh smoke-http http://localhost:8080 2 internal
  ./tests/run_unit_php.sh e2e http://localhost:8080
EOF
}

log_step() {
  printf '\n=== %s ===\n' "$1"
}

require_command() {
  local command_name="$1"
  if ! command -v "$command_name" >/dev/null 2>&1; then
    printf '[FAIL] Comando obrigatorio ausente: %s\n' "$command_name" >&2
    exit 1
  fi
}

require_file() {
  local file_path="$1"
  if [[ ! -f "$file_path" ]]; then
    printf '[FAIL] Arquivo obrigatorio ausente: %s\n' "$file_path" >&2
    exit 1
  fi
}

run_php_glob_in_container() {
  local container_path="$1"
  local label="$2"
  log_step "$label"
  require_command docker
  docker exec "$container_name" bash -lc "
set -euo pipefail
shopt -s nullglob
files=($container_path)
if [ \${#files[@]} -eq 0 ]; then
  echo '[FAIL] Nenhum arquivo encontrado para o padrao: $container_path' >&2
  exit 1
fi
for test_file in \"\${files[@]}\"; do
  php \"\$test_file\"
done
"
}

run_unit_php() {
  run_php_glob_in_container "/var/www/glpi/plugins/glpichat/tests/unit/php/*_test.php" "Running PHP unit tests"
}

run_unit_js() {
  local runner_path="$plugin_root/tests/unit/js/run_vitest.sh"
  require_command bash
  require_file "$runner_path"
  log_step "Running JS unit tests"
  bash "$runner_path"
}

run_smoke_php() {
  run_php_glob_in_container "/var/www/glpi/plugins/glpichat/tests/smoke/php/*_smoke_test.php" "Running PHP smoke tests"
}

run_smoke_http() {
  local smoke_runner="$plugin_root/tests/smoke/http/http_security_smoke_test.sh"
  require_command bash
  require_file "$smoke_runner"
  log_step "Running HTTP smoke tests"
  bash "$smoke_runner" "$base_url" "$ticket_id" "$room_type"
}

run_e2e() {
  require_command python3
  log_step "Running Python E2E tests"
  shopt -s nullglob
  local e2e_files=("$plugin_root"/tests/e2e/python/*.py)
  if [[ ${#e2e_files[@]} -eq 0 ]]; then
    printf '[FAIL] Nenhum arquivo E2E encontrado em: %s\n' "$plugin_root/tests/e2e/python/*.py" >&2
    exit 1
  fi
  for e2e_runner in "${e2e_files[@]}"; do
    printf -- '--- %s ---\n' "$(basename "$e2e_runner")"
    GLPICHAT_BASE_URL="$base_url" python3 "$e2e_runner"
  done
}

case "$suite" in
  all)
    run_unit_php
    run_unit_js
    run_smoke_php
    run_smoke_http
    run_e2e
    ;;
  unit)
    run_unit_php
    run_unit_js
    ;;
  unit-php)
    run_unit_php
    ;;
  unit-js)
    run_unit_js
    ;;
  smoke)
    run_smoke_php
    run_smoke_http
    ;;
  smoke-php)
    run_smoke_php
    ;;
  smoke-http)
    run_smoke_http
    ;;
  e2e)
    run_e2e
    ;;
  help|-h|--help)
    print_help
    ;;
  *)
    printf '[FAIL] Suite invalida: %s\n' "$suite" >&2
    print_help >&2
    exit 1
    ;;
esac
