#!/usr/bin/env bash
#
# validate_tests.sh — Valida se os testes sao realmente funcionais.
#
# Estrategia:
#   - Modificacoes feitas no HOST (onde temos permissao de escrita).
#   - Os arquivos do host sao montados no container (volume mount), entao
#     a mudanca fica visivel imediatamente para o "docker exec php".
#   - Nenhum sed -i dentro do container.
#
# Uso:
#   ./tests/validate_tests.sh
#
set -uo pipefail

CONTAINER="glpi"
PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONTAINER_PLUGIN_ROOT="/var/www/glpi/plugins/glpichat"

# Cores
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'

pass_count=0; fail_count=0; survivor_count=0

log_section()  { printf "\n${BOLD}${BLUE}══════════════════════════════════════════════════${NC}\n${BOLD}  %s${NC}\n${BLUE}══════════════════════════════════════════════════${NC}\n" "$1"; }
log_ok()       { printf "  ${GREEN}[OK]${NC}      %s\n" "$1"; ((pass_count++)) || true; }
log_killed()   { printf "  ${GREEN}[KILLED]${NC}  Mutante detectado: %s\n" "$1"; ((pass_count++)) || true; }
log_survived() { printf "  ${RED}[SURVIVED]${NC} ${RED}PROBLEMA:${NC} Mutante NAO detectado: %s\n" "$1"; ((survivor_count++)) || true; ((fail_count++)) || true; }
log_assert_ok()   { printf "  ${GREEN}[OK]${NC}      Assert falso detectado: %s\n" "$1"; ((pass_count++)) || true; }
log_assert_fail() { printf "  ${RED}[FAIL]${NC}    ${RED}PROBLEMA:${NC} Assert falso NAO detectado: %s\n" "$1"; ((fail_count++)) || true; }

# Roda teste unitario PHP no container
run_test() {
    docker exec "$CONTAINER" php "$CONTAINER_PLUGIN_ROOT/tests/unit/php/$1" >/dev/null 2>&1
}

# Roda teste smoke PHP no container
run_smoke_test() {
    docker exec -e "GLPICHAT_TICKET_ID=${GLPICHAT_TICKET_ID:-1}" \
        "$CONTAINER" php "$CONTAINER_PLUGIN_ROOT/tests/smoke/php/$1" >/dev/null 2>&1
}

# Aplica substituicao no HOST (sed no arquivo do host, que esta montado no container)
mutate_host() {
    local file="$PLUGIN_ROOT/$1"
    local old="$2"
    local new="$3"
    # Usa python3 para substituicao segura sem problemas de escape do sed
    python3 - "$file" "$old" "$new" <<'PYEOF'
import sys
path, old, new = sys.argv[1], sys.argv[2], sys.argv[3]
with open(path, 'r') as f:
    content = f.read()
if old not in content:
    print(f"[mutate_host] AVISO: padrao nao encontrado em {path}: {old!r}", flush=True)
    sys.exit(2)
with open(path, 'w') as f:
    f.write(content.replace(old, new, 1))
PYEOF
}

# Salva backup do arquivo no host
backup_host() {
    cp "$PLUGIN_ROOT/$1" "$PLUGIN_ROOT/$1.mutbak"
}

# Restaura backup no host e remove .mutbak
restore_host() {
    mv "$PLUGIN_ROOT/$1.mutbak" "$PLUGIN_ROOT/$1"
}

# ─────────────────────────────────────────────────────────────────────────────
# VERIFICA que os arquivos do host sao visiveis no container (volume mount)
# ─────────────────────────────────────────────────────────────────────────────
printf "\n${BOLD}Verificando volume mount host→container...${NC}\n"
host_hash=$(md5sum "$PLUGIN_ROOT/src/domain/domain.php" | awk '{print $1}')
container_hash=$(docker exec "$CONTAINER" md5sum "$CONTAINER_PLUGIN_ROOT/src/domain/domain.php" 2>/dev/null | awk '{print $1}')
if [[ "$host_hash" != "$container_hash" ]]; then
    printf "  ${RED}[ERRO]${NC} Os arquivos do host NAO batem com o container.\n"
    printf "         host hash     : %s\n" "$host_hash"
    printf "         container hash: %s\n" "$container_hash"
    printf "  O plugin nao esta montado via volume. Copie os arquivos manualmente ou\n"
    printf "  ajuste o docker-compose para montar o plugin como volume.\n\n"
    printf "  ${YELLOW}Tentando fallback via docker cp...${NC}\n"
    USE_DOCKER_CP=1
else
    printf "  ${GREEN}Volume mount confirmado.${NC} Mutacoes no host ficam visiveis no container.\n"
    USE_DOCKER_CP=0
fi

# Funcoes de mutacao/restauracao que escolhem a estrategia certa
do_mutate() {
    local rel_path="$1" old="$2" new="$3"
    if [[ "${USE_DOCKER_CP:-0}" -eq 1 ]]; then
        # Modifica no host e copia para o container
        backup_host "$rel_path"
        mutate_host "$rel_path" "$old" "$new"
        docker cp "$PLUGIN_ROOT/$rel_path" "$CONTAINER:$CONTAINER_PLUGIN_ROOT/$rel_path" >/dev/null 2>&1
    else
        backup_host "$rel_path"
        mutate_host "$rel_path" "$old" "$new"
    fi
}

do_restore() {
    local rel_path="$1"
    if [[ "${USE_DOCKER_CP:-0}" -eq 1 ]]; then
        restore_host "$rel_path"
        docker cp "$PLUGIN_ROOT/$rel_path" "$CONTAINER:$CONTAINER_PLUGIN_ROOT/$rel_path" >/dev/null 2>&1
    else
        restore_host "$rel_path"
    fi
}

# ─────────────────────────────────────────────────────────────────────────────
# SETUP: garante GLPICHAT_TICKET_ID
# ─────────────────────────────────────────────────────────────────────────────
if [[ -z "${GLPICHAT_TICKET_ID:-}" ]]; then
    setup_out=$(docker exec "$CONTAINER" \
        php "$CONTAINER_PLUGIN_ROOT/tests/e2e/setup/prepare_env.php" 2>/dev/null || true)
    GLPICHAT_TICKET_ID="$(echo "$setup_out" | sed -n 's/^GLPICHAT_TICKET_ID=\([0-9][0-9]*\).*/\1/p' | head -n1)"
    [[ -z "${GLPICHAT_TICKET_ID:-}" || "${GLPICHAT_TICKET_ID:-0}" -le 0 ]] && GLPICHAT_TICKET_ID=1
    printf "  ${YELLOW}[INFO]${NC} GLPICHAT_TICKET_ID=%s\n" "$GLPICHAT_TICKET_ID"
fi
export GLPICHAT_TICKET_ID

# ─────────────────────────────────────────────────────────────────────────────
# BASELINE
# ─────────────────────────────────────────────────────────────────────────────
log_section "BASELINE — confirmando que os testes passam normalmente"

baseline_tests=(
    "domain_test.php"
    "access_test.php"
    "guest_throttle_test.php"
    "invite_flow_test.php"
    "invite_flow_max_invites_test.php"
)

baseline_ok=true
for t in "${baseline_tests[@]}"; do
    if run_test "$t"; then
        log_ok "Baseline OK: $t"
    else
        printf "  ${RED}[FAIL]${NC}   Baseline FALHOU: %s\n" "$t"
        baseline_ok=false
    fi
done

if [[ "$baseline_ok" != "true" ]]; then
    printf "\n${RED}Baseline com falhas. Corrija os testes antes de continuar.${NC}\n"
    exit 1
fi

# ─────────────────────────────────────────────────────────────────────────────
# SECAO 1: MUTATION TESTING
# ─────────────────────────────────────────────────────────────────────────────
log_section "SECAO 1 — Mutation Testing"
printf "  Modifica arquivos no host (montados no container) e verifica deteccao.\n\n"

# M1: inverte condicao de throttle (< → >=)
printf "  M1: guest_db.php — condicao de throttle: < → >=\n"
do_mutate "src/db/guest_db.php" \
    'if ($elapsed < $min_interval_seconds)' \
    'if ($elapsed >= $min_interval_seconds)'
if run_test "guest_throttle_test.php"; then log_survived "M1 — throttle < invertido para >="; else log_killed "M1 — throttle: condicao invertida"; fi
do_restore "src/db/guest_db.php"

# M2: remove throw do throttle
printf "  M2: guest_db.php — remove RuntimeException do throttle\n"
do_mutate "src/db/guest_db.php" \
    "throw new RuntimeException('Muitas requisições. Tente novamente em instantes.', 429);" \
    "return; // MUTANT M2: throw removido"
if run_test "guest_throttle_test.php"; then log_survived "M2 — throttle throw removido"; else log_killed "M2 — throttle: throw removido detectado"; fi
do_restore "src/db/guest_db.php"

# M3: inverte is_terminal_ticket na can_send
printf "  M3: access.php — can_send: \$is_terminal_ticket → !\$is_terminal_ticket\n"
do_mutate "src/domain/access.php" \
    'if ($is_terminal_ticket || !glpichat_is_valid_room_type($room_type))' \
    'if (!$is_terminal_ticket || !glpichat_is_valid_room_type($room_type))'
if run_test "access_test.php"; then log_survived "M3 — is_terminal_ticket invertido"; else log_killed "M3 — access: terminal invertido detectado"; fi
do_restore "src/domain/access.php"

# M4: OR → AND em can_bootstrap_widget
printf "  M4: access.php — can_bootstrap_widget: || → &&\n"
do_mutate "src/domain/access.php" \
    'return $can_read_public_room || $can_read_internal_room;' \
    'return $can_read_public_room && $can_read_internal_room;'
if run_test "access_test.php"; then log_survived "M4 — bootstrap OR→AND"; else log_killed "M4 — access: OR→AND detectado"; fi
do_restore "src/domain/access.php"

# M5: inverte condicao display_unread_count (< → >)
printf "  M5: domain.php — display_unread_count: < → >\n"
do_mutate "src/domain/domain.php" \
    'if ($raw_unread < glpichat_config_int(' \
    'if ($raw_unread > glpichat_config_int('
if run_test "domain_test.php"; then log_survived "M5 — unread threshold < invertido"; else log_killed "M5 — domain: threshold invertido detectado"; fi
do_restore "src/domain/domain.php"

# M6: >= para > no limite de convites (boundary escape)
printf "  M6: invite_flow.php — limite convites: >= → > (off-by-one)\n"
do_mutate "src/flows/invite_flow.php" \
    '>= $max_invites)' \
    '> $max_invites)'
if run_test "invite_flow_max_invites_test.php"; then log_survived "M6 — invite boundary >= para >"; else log_killed "M6 — invite: boundary detectado"; fi
do_restore "src/flows/invite_flow.php"

# M7: remove validacao de guest_name vazio
printf "  M7: invite_flow.php — remove validacao de guest_name vazio\n"
do_mutate "src/flows/invite_flow.php" \
    'if ($tickets_id <= 0 || $trimmed_name === '"'"''"'"') {' \
    'if ($tickets_id <= 0) { // MUTANT M7'
if run_test "invite_flow_test.php"; then log_survived "M7 — validacao guest_name removida"; else log_killed "M7 — invite: validacao de nome detectada"; fi
do_restore "src/flows/invite_flow.php"

# M8: is_valid_room_type sempre true
printf "  M8: domain.php — is_valid_room_type sempre retorna true\n"
do_mutate "src/domain/domain.php" \
    'return in_array($room_type, glpichat_room_types(), true);' \
    'return true; // MUTANT M8'
if run_test "domain_test.php"; then log_survived "M8 — is_valid_room_type sempre true"; else log_killed "M8 — domain: always-true detectado"; fi
do_restore "src/domain/domain.php"

# M9: rate limit >= para > (boundary)
printf "  M9: guest_db.php — rate limit accept: >= → >\n"
do_mutate "src/db/guest_db.php" \
    'if ($count >= glpichat_config_int(' \
    'if ($count > glpichat_config_int('
if run_test "guest_throttle_test.php"; then log_survived "M9 — rate limit boundary >= para >"; else log_killed "M9 — rate limit: boundary detectado"; fi
do_restore "src/db/guest_db.php"

# ─────────────────────────────────────────────────────────────────────────────
# SECAO 2: ASSERT-VALUE
# ─────────────────────────────────────────────────────────────────────────────
log_section "SECAO 2 — Assert-Value (valores esperados sao reais?)"
printf "  Altera valores esperados nos testes; o teste deve passar a FALHAR.\n\n"

# AV1: muda evento esperado para valor errado
printf "  AV1: domain_test.php — evento 'glpichat_public_message' → 'WRONG_EVENT'\n"
do_mutate "tests/unit/php/domain_test.php" \
    "'glpichat_public_message'," \
    "'WRONG_EVENT_VALUE',"
if run_test "domain_test.php"; then log_assert_fail "AV1 — evento errado nao quebrou o teste"; else log_assert_ok "AV1 — evento errado detectado"; fi
do_restore "tests/unit/php/domain_test.php"

# AV2: muda argumento de display_unread_count(2) para (99)
printf "  AV2: domain_test.php — glpichat_display_unread_count(2) → (99)\n"
do_mutate "tests/unit/php/domain_test.php" \
    'glpichat_display_unread_count(2),' \
    'glpichat_display_unread_count(99),'
if run_test "domain_test.php"; then log_assert_fail "AV2 — unread(2)→(99) nao quebrou"; else log_assert_ok "AV2 — unread(99) detectado"; fi
do_restore "tests/unit/php/domain_test.php"

# AV3: muda argumento de can_bootstrap_widget(true, false) para (false, false)
printf "  AV3: access_test.php — can_bootstrap_widget(true, false) → (false, false)\n"
do_mutate "tests/unit/php/access_test.php" \
    'glpichat_can_bootstrap_widget_from_state(true, false),' \
    'glpichat_can_bootstrap_widget_from_state(false, false),'
if run_test "access_test.php"; then log_assert_fail "AV3 — bootstrap args errados nao quebraram"; else log_assert_ok "AV3 — args errados detectados"; fi
do_restore "tests/unit/php/access_test.php"

# AV4: muda last_send_at de 3s atrás (ok) para 0s (throttled) no caso "permite"
printf "  AV4: guest_throttle_test.php — last_send_at: time()-3 → time()-0\n"
do_mutate "tests/unit/php/guest_throttle_test.php" \
    "date('Y-m-d H:i:s', time() - 3)" \
    "date('Y-m-d H:i:s', time() - 0)"
if run_test "guest_throttle_test.php"; then log_assert_fail "AV4 — 3s→0s nao quebrou"; else log_assert_ok "AV4 — 3s→0s detectado"; fi
do_restore "tests/unit/php/guest_throttle_test.php"

# AV5: no Scenario 5 (count=1 max=1 → throws), muda active_count de 1 para 0
printf "  AV5: invite_flow_max_invites_test.php — Scenario 5: active_count=1 → 0\n"
do_mutate "tests/unit/php/invite_flow_max_invites_test.php" \
    '$DB->active_count = 1;' \
    '$DB->active_count = 0;'
if run_test "invite_flow_max_invites_test.php"; then log_assert_fail "AV5 — active_count 1→0 nao quebrou"; else log_assert_ok "AV5 — active_count 1→0 detectado"; fi
do_restore "tests/unit/php/invite_flow_max_invites_test.php"

# AV6: smoke config_effect — altera threshold de 5 para 999
printf "  AV6: config_effect_smoke_test.php — threshold: '5' → '999'\n"
do_mutate "tests/smoke/php/config_effect_smoke_test.php" \
    "cfgSet(['unread_badge_threshold' => '5']);" \
    "cfgSet(['unread_badge_threshold' => '999']);"
if run_smoke_test "config_effect_smoke_test.php"; then log_assert_fail "AV6 — threshold 5→999 nao quebrou"; else log_assert_ok "AV6 — threshold 999 detectado"; fi
do_restore "tests/smoke/php/config_effect_smoke_test.php"

# ─────────────────────────────────────────────────────────────────────────────
# BASELINE FINAL — verifica integridade
# ─────────────────────────────────────────────────────────────────────────────
log_section "BASELINE FINAL — verificando integridade dos arquivos"

for t in "${baseline_tests[@]}"; do
    if run_test "$t"; then
        log_ok "Integridade OK: $t"
    else
        printf "  ${RED}[FAIL]${NC}   ${RED}ATENCAO: arquivo corrompido!${NC} %s\n" "$t"
        ((fail_count++)) || true
    fi
done

# ─────────────────────────────────────────────────────────────────────────────
# RESUMO
# ─────────────────────────────────────────────────────────────────────────────
log_section "RESUMO"
printf "  Checks OK (mutantes detectados + asserts reais) : ${GREEN}%d${NC}\n" "$pass_count"
printf "  Checks com problema                             : ${RED}%d${NC}\n"  "$fail_count"

if [[ "$survivor_count" -gt 0 ]]; then
    printf "\n  ${RED}${BOLD}ATENCAO: %d mutante(s) sobreviveram — logica sem cobertura de teste.${NC}\n" "$survivor_count"
fi

if [[ "$fail_count" -eq 0 ]]; then
    printf "\n  ${GREEN}${BOLD}[RESULTADO] Todos os testes sao funcionais e detectam bugs reais.${NC}\n\n"
    exit 0
else
    printf "\n  ${RED}${BOLD}[RESULTADO] Problemas encontrados. Veja [SURVIVED] e [FAIL] acima.${NC}\n\n"
    exit 1
fi
