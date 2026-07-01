#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
k6_dir="$script_dir/k6"
logs_dir="$script_dir/logs"
base_url="${1:-http://localhost:8080}"
script_arg="${2:-}"

if ! command -v k6 >/dev/null 2>&1; then
  printf '[FAIL] k6 nao encontrado no PATH. Instale k6 para executar esta suite.\n' >&2
  exit 1
fi

shift || true
shift || true

mkdir -p "$logs_dir"

run_one() {
  local k6_script="$1"
  shift
  local base_name
  base_name="$(basename "$k6_script" .js)"
  local timestamp
  timestamp="$(date +%Y%m%d_%H%M%S)"
  local log_file="$logs_dir/${base_name}_${timestamp}.log"
  local summary_file="$logs_dir/${base_name}_${timestamp}.summary.json"

  printf '[INFO] Executando %s (base_url=%s)\n' "$k6_script" "$base_url"
  printf '[INFO] Log: %s\n' "$log_file"
  printf '[INFO] Summary: %s\n' "$summary_file"

  if GLPICHAT_BASE_URL="$base_url" k6 run --summary-export="$summary_file" "$k6_script" "$@" 2>&1 | tee "$log_file"; then
    printf '[OK] %s concluido.\n' "$base_name"
  else
    printf '[FAIL] %s terminou com erro. Veja %s\n' "$base_name" "$log_file" >&2
    return 1
  fi
}

if [ -n "$script_arg" ]; then
  if [ -f "$script_arg" ]; then
    k6_script="$script_arg"
  else
    k6_script="$k6_dir/$script_arg"
  fi

  if [ ! -f "$k6_script" ]; then
    printf '[FAIL] Script k6 nao encontrado: %s\n' "$script_arg" >&2
    exit 1
  fi

  run_one "$k6_script" "$@"
else
  overall_status=0
  for k6_script in "$k6_dir"/*.js; do
    [ -f "$k6_script" ] || continue
    if ! run_one "$k6_script" "$@"; then
      overall_status=1
    fi
  done
  exit "$overall_status"
fi
