#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
k6_script="$script_dir/k6/chat_polling.js"
base_url="${1:-http://localhost:8080}"

if ! command -v k6 >/dev/null 2>&1; then
  printf '[FAIL] k6 nao encontrado no PATH. Instale k6 para executar esta suite.\n' >&2
  exit 1
fi

shift || true

GLPICHAT_BASE_URL="$base_url" k6 run "$k6_script" "$@"
