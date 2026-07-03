#!/usr/bin/env bash
#
# Esteira completa de testes do plugin glpichat.
#
# Fluxo:
#   1. SETUP    : abre UM ticket dedicado (self=requester, glpi=assign, status=2)
#                 e exporta GLPICHAT_TICKET_ID para todos os passos.
#   2. SUITE    : roda unit-js, unit-php, smoke-php, smoke-http e e2e usando ESSE
#                 ticket, gerando UM LOG POR TESTE.
#   3. TEARDOWN : (sempre, via trap EXIT) remove o ticket e os dados do plugin.
#
# NAO usa `set -e`: queremos rodar TUDO e coletar logs mesmo com falhas.
#
# Uso:
#   ./tests/run_full_suite.sh [base_url]
#   base_url (opcional) - padrao http://localhost:8080
#
set -uo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
plugin_root="$(cd "$script_dir/.." && pwd)"
container_name="glpi"
GLPI_INSTALL_DIR="${GLPI_INSTALL_DIR:-/var/www/glpi}"
container_plugin_root="${GLPI_INSTALL_DIR}/plugins/glpichat"

base_url="${1:-http://localhost:8080}"

run_stamp="$(date +%Y%m%d-%H%M%S)"
logs_root="$plugin_root/tests/logs/run-$run_stamp"
mkdir -p "$logs_root"

# Estado global de resultados por camada.
declare -A layer_pass
declare -A layer_fail
overall_fail=0

log_step() {
  printf '\n=== %s ===\n' "$1"
}

# Avalia o resultado de UM arquivo a partir de seu exit code + log.
# Conta PASS/FAIL por camada e ecoa uma linha de status.
record_result() {
  local layer="$1"
  local name="$2"
  local exit_code="$3"
  local log_file="$4"

  if [[ "$exit_code" -eq 0 ]]; then
    layer_pass["$layer"]=$(( ${layer_pass["$layer"]:-0} + 1 ))
    printf '  [PASS] %-45s -> %s\n' "$name" "${log_file#$plugin_root/}"
  else
    layer_fail["$layer"]=$(( ${layer_fail["$layer"]:-0} + 1 ))
    overall_fail=1
    printf '  [FAIL] %-45s (exit=%s) -> %s\n' "$name" "$exit_code" "${log_file#$plugin_root/}"
  fi
}

# ---------------------------------------------------------------------------
# TEARDOWN (sempre roda, via trap EXIT)
# ---------------------------------------------------------------------------
teardown_done=0
run_teardown() {
  [[ "$teardown_done" -eq 1 ]] && return 0
  teardown_done=1

  local teardown_log="$logs_root/99-teardown.log"
  log_step "TEARDOWN"
  if [[ -n "${GLPICHAT_TICKET_ID:-}" && "${GLPICHAT_TICKET_ID:-0}" -gt 0 ]]; then
    docker exec -e GLPICHAT_TICKET_ID="$GLPICHAT_TICKET_ID" "$container_name" \
      php "$container_plugin_root/tests/e2e/setup/teardown_env.php" >"$teardown_log" 2>&1
    local td_exit=$?
    if [[ "$td_exit" -eq 0 ]]; then
      printf '  Teardown OK (ticket %s) -> %s\n' "$GLPICHAT_TICKET_ID" "${teardown_log#$plugin_root/}"
    else
      printf '  Teardown FALHOU (exit=%s) -> %s\n' "$td_exit" "${teardown_log#$plugin_root/}"
    fi
  else
    printf '  Teardown ignorado: GLPICHAT_TICKET_ID nao definido\n' | tee "$teardown_log"
  fi
}
trap run_teardown EXIT

# ---------------------------------------------------------------------------
# SETUP
# ---------------------------------------------------------------------------
log_step "SETUP"
setup_log="$logs_root/00-setup.log"
docker exec "$container_name" \
  php "$container_plugin_root/tests/e2e/setup/prepare_env.php" >"$setup_log" 2>&1
setup_exit=$?

cat "$setup_log"

if [[ "$setup_exit" -ne 0 ]]; then
  printf '\n[FAIL] SETUP falhou (exit=%s). Abortando a esteira.\n' "$setup_exit" >&2
  exit 1
fi

GLPICHAT_TICKET_ID="$(sed -n 's/^GLPICHAT_TICKET_ID=\([0-9][0-9]*\).*/\1/p' "$setup_log" | head -n1)"
if [[ -z "${GLPICHAT_TICKET_ID:-}" || "${GLPICHAT_TICKET_ID:-0}" -le 0 ]]; then
  printf '\n[FAIL] SETUP nao retornou GLPICHAT_TICKET_ID. Abortando.\n' >&2
  exit 1
fi
export GLPICHAT_TICKET_ID

# GLPICHAT_SELF_ID / GLPICHAT_GLPI_ID: atores REAIS (requester/assign) do ticket
# dedicado, ja resolvidos por prepare_env.php. Exportados para que os testes
# smoke-php semeiem a sessao de "tecnico" com um usuario que garantidamente tem
# direito de leitura sobre o ticket, em vez de uma query solta com LIMIT 1 sem
# ORDER BY (nao-deterministica entre motores/planos de execucao distintos -
# ver .agents/references/security-audits.md / decisions.md).
GLPICHAT_SELF_ID="$(sed -n 's/^GLPICHAT_SELF_ID=\([0-9][0-9]*\).*/\1/p' "$setup_log" | head -n1)"
GLPICHAT_GLPI_ID="$(sed -n 's/^GLPICHAT_GLPI_ID=\([0-9][0-9]*\).*/\1/p' "$setup_log" | head -n1)"
if [[ -z "${GLPICHAT_GLPI_ID:-}" || "${GLPICHAT_GLPI_ID:-0}" -le 0 ]]; then
  printf '\n[FAIL] SETUP nao retornou GLPICHAT_GLPI_ID. Abortando.\n' >&2
  exit 1
fi
export GLPICHAT_SELF_ID
export GLPICHAT_GLPI_ID
printf '\n[INFO] Ticket dedicado da esteira: GLPICHAT_TICKET_ID=%s (self=%s glpi=%s)\n' \
  "$GLPICHAT_TICKET_ID" "${GLPICHAT_SELF_ID:-0}" "$GLPICHAT_GLPI_ID"

# ---------------------------------------------------------------------------
# unit-js
# ---------------------------------------------------------------------------
log_step "unit-js"
unit_js_log="$logs_root/unit-js.log"
unit_js_runner="$plugin_root/tests/unit/js/run_vitest.sh"
if [[ -f "$unit_js_runner" ]]; then
  bash "$unit_js_runner" >"$unit_js_log" 2>&1
  record_result "unit-js" "vitest" "$?" "$unit_js_log"
else
  printf '  [FAIL] runner ausente: %s\n' "$unit_js_runner" | tee "$unit_js_log"
  layer_fail["unit-js"]=1
  overall_fail=1
fi

# ---------------------------------------------------------------------------
# unit-php (um log por arquivo)
# ---------------------------------------------------------------------------
log_step "unit-php"
mkdir -p "$logs_root/unit-php"
while IFS= read -r f; do
  [[ -z "$f" ]] && continue
  base="$(basename "$f")"
  log_file="$logs_root/unit-php/${base%.php}.log"
  docker exec -e GLPICHAT_TICKET_ID="$GLPICHAT_TICKET_ID" "$container_name" \
    php "$container_plugin_root/tests/unit/php/$base" >"$log_file" 2>&1
  record_result "unit-php" "$base" "$?" "$log_file"
done < <(find "$plugin_root/tests/unit/php" -maxdepth 1 -name '*_test.php' | sort)

# ---------------------------------------------------------------------------
# smoke-php (um log por arquivo)
# ---------------------------------------------------------------------------
log_step "smoke-php"
mkdir -p "$logs_root/smoke-php"
while IFS= read -r f; do
  [[ -z "$f" ]] && continue
  base="$(basename "$f")"
  log_file="$logs_root/smoke-php/${base%.php}.log"
  docker exec \
    -e GLPICHAT_TICKET_ID="$GLPICHAT_TICKET_ID" \
    -e GLPICHAT_SELF_ID="${GLPICHAT_SELF_ID:-0}" \
    -e GLPICHAT_GLPI_ID="$GLPICHAT_GLPI_ID" \
    "$container_name" \
    php "$container_plugin_root/tests/smoke/php/$base" >"$log_file" 2>&1
  record_result "smoke-php" "$base" "$?" "$log_file"
done < <(find "$plugin_root/tests/smoke/php" -maxdepth 1 -name '*_smoke_test.php' | sort)

# ---------------------------------------------------------------------------
# smoke-http (um log por arquivo — itera todos os *.sh em tests/smoke/http/)
# ---------------------------------------------------------------------------
log_step "smoke-http"
mkdir -p "$logs_root/smoke-http"
smoke_http_found=0
while IFS= read -r smoke_runner; do
  [[ -z "$smoke_runner" ]] && continue
  smoke_http_found=1
  smoke_base="$(basename "$smoke_runner")"
  smoke_http_log="$logs_root/smoke-http/${smoke_base%.sh}.log"
  bash "$smoke_runner" "$base_url" "$GLPICHAT_TICKET_ID" "public" >"$smoke_http_log" 2>&1
  record_result "smoke-http" "$smoke_base" "$?" "$smoke_http_log"
done < <(find "$plugin_root/tests/smoke/http" -maxdepth 1 -name '*.sh' | sort)

if [[ "$smoke_http_found" -eq 0 ]]; then
  printf '  [FAIL] nenhum *.sh encontrado em tests/smoke/http/\n'
  layer_fail["smoke-http"]=1
  overall_fail=1
fi

# ---------------------------------------------------------------------------
# e2e (um log por arquivo, com timeout)
# ---------------------------------------------------------------------------
log_step "e2e"
mkdir -p "$logs_root/e2e"
if command -v python3 >/dev/null 2>&1; then
  while IFS= read -r f; do
    [[ -z "$f" ]] && continue
    base="$(basename "$f")"
    log_file="$logs_root/e2e/${base%.py}.log"
    timeout 180 env \
      GLPICHAT_BASE_URL="$base_url" \
      GLPICHAT_TICKET_ID="$GLPICHAT_TICKET_ID" \
      python3 "$f" >"$log_file" 2>&1
    e2e_exit=$?
    if [[ "$e2e_exit" -eq 124 ]]; then
      printf '[FAIL] timeout (180s) excedido\n' >>"$log_file"
    fi
    record_result "e2e" "$base" "$e2e_exit" "$log_file"
  done < <(find "$plugin_root/tests/e2e/python" -maxdepth 1 -name '*.py' | sort)
else
  printf '  [FAIL] python3 ausente; e2e ignorado\n'
  layer_fail["e2e"]=1
  overall_fail=1
fi

# ---------------------------------------------------------------------------
# lifecycle (um log por arquivo)
#
# Camada destrutiva que roda por ULTIMO (depois de unit/smoke/e2e): valida o
# ciclo completo install -> idempotencia -> migracao de direitos -> uninstall
# (limpeza COMPLETA, incl. notificacoes sem orfaos) -> reinstalacao final.
#
# GLPICHAT_ALLOW_DESTRUCTIVE_TEST=1 habilita o opt-in destrutivo. So e seguro
# porque esta esteira roda contra um banco DE TESTE/DESCARTAVEL. O teste reinstala
# o schema e restaura o snapshot de configs ao final (mesmo em falha, via
# register_shutdown_function), deixando o ambiente intacto para o TEARDOWN.
# ---------------------------------------------------------------------------
log_step "lifecycle"
mkdir -p "$logs_root/lifecycle"
while IFS= read -r f; do
  [[ -z "$f" ]] && continue
  base="$(basename "$f")"
  log_file="$logs_root/lifecycle/${base%.php}.log"
  docker exec \
    -e GLPICHAT_TICKET_ID="$GLPICHAT_TICKET_ID" \
    -e GLPICHAT_ALLOW_DESTRUCTIVE_TEST=1 \
    "$container_name" \
    php "$container_plugin_root/tests/lifecycle/$base" >"$log_file" 2>&1
  record_result "lifecycle" "$base" "$?" "$log_file"
done < <(find "$plugin_root/tests/lifecycle" -maxdepth 1 -name '*_test.php' | sort)

# ---------------------------------------------------------------------------
# RESUMO
# ---------------------------------------------------------------------------
log_step "RESUMO"
printf 'Ticket dedicado : %s\n' "$GLPICHAT_TICKET_ID"
printf 'Logs            : %s\n\n' "${logs_root#$plugin_root/}"

printf '%-12s | %5s | %5s\n' "CAMADA" "PASS" "FAIL"
printf -- '-------------+-------+------\n'
for layer in unit-js unit-php smoke-php smoke-http e2e lifecycle; do
  printf '%-12s | %5s | %5s\n' \
    "$layer" "${layer_pass[$layer]:-0}" "${layer_fail[$layer]:-0}"
done
printf '\nDetalhe dos logs por teste em: %s\n' "$logs_root"

if [[ "$overall_fail" -ne 0 ]]; then
  printf '\n[RESULTADO] Houve FALHAS em uma ou mais camadas.\n'
else
  printf '\n[RESULTADO] Todas as camadas passaram.\n'
fi

# Teardown roda via trap EXIT; o exit code reflete o resultado da suite.
exit "$overall_fail"
