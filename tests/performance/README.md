# Performance

Esta pasta contém a suíte de carga/performance baseada em k6, cobrindo os endpoints
de widget (agente) e guest (convidado) do plugin glpichat.

## Execução

```bash
# Roda todos os scripts em tests/performance/k6/, um de cada vez
./tests/performance/run_k6.sh http://localhost:8080

# Roda apenas um script especifico (por nome de arquivo ou caminho)
./tests/performance/run_k6.sh http://localhost:8080 message_throughput.js

# Argumentos extras sao repassados ao k6 (ex: --vus, --duration, flags custom)
./tests/performance/run_k6.sh http://localhost:8080 soak.js --quiet
```

Sem o segundo argumento, o comportamento é retrocompatível: todos os `.js` de
`tests/performance/k6/` são executados sequencialmente.

## Logs

Cada execução grava, em `tests/performance/logs/`:

- `<nome_do_script>_<timestamp>.log` — stdout completo do k6 (via `tee`).
- `<nome_do_script>_<timestamp>.summary.json` — resumo estruturado (`k6 run --summary-export`).

O diretório `tests/performance/logs/` é ignorado pelo git (apenas `.gitkeep` é versionado).

## Scripts

### `chat_polling.js`
Polling leve de `Widget/Poll` (agente) e `Guest/Sync` (convidado) com taxa constante (`constant-arrival-rate`).

- `GLPICHAT_WIDGET_COOKIE`: cookie autenticado do GLPI para `/Widget/Poll`
- `GLPICHAT_WIDGET_CSRF_TOKEN`: token CSRF válido para `/Widget/Poll`
- `GLPICHAT_WIDGET_TICKET_ID`: ticket usado na simulação do poll
- `GLPICHAT_GUEST_COOKIE`: cookie do convidado para `/Guest/Sync`
- `GLPICHAT_GUEST_LAST_MESSAGE_ID`: cursor inicial de mensagens do convidado
- `GLPICHAT_GUEST_LAST_EVENT_ID`: cursor inicial de eventos do convidado
- `GLPICHAT_WIDGET_RATE`, `GLPICHAT_WIDGET_DURATION`, `GLPICHAT_WIDGET_P95_MS`
- `GLPICHAT_GUEST_RATE`, `GLPICHAT_GUEST_DURATION`, `GLPICHAT_GUEST_P95_MS`

### `polling_breakpoint.js`
Rampeia a taxa de `Widget/Poll` (`ramping-arrival-rate`) para descobrir o teto de agentes simultâneos antes do p95/erros degradarem.

- `GLPICHAT_WIDGET_COOKIE`, `GLPICHAT_WIDGET_CSRF_TOKEN`, `GLPICHAT_WIDGET_TICKET_ID`
- `GLPICHAT_BREAKPOINT_STAGES`: JSON de estágios `[{"target":N,"duration":"1m"}, ...]` (sobrepõe os stages individuais abaixo)
- `GLPICHAT_BREAKPOINT_STAGE1_RATE`/`_DURATION` ... `STAGE4` (defaults: 5→20→50→100 req/s, 1min cada)
- `GLPICHAT_BREAKPOINT_START_RATE`, `GLPICHAT_BREAKPOINT_PRE_ALLOCATED_VUS`, `GLPICHAT_BREAKPOINT_MAX_VUS`
- `GLPICHAT_BREAKPOINT_P95_MS`

### `guest_polling_breakpoint.js`
Mesma ideia do anterior, mas rampeia `Guest/Sync` para achar o teto de convidados simultâneos.

- `GLPICHAT_GUEST_COOKIE`, `GLPICHAT_GUEST_LAST_MESSAGE_ID`, `GLPICHAT_GUEST_LAST_EVENT_ID`
- `GLPICHAT_GUEST_BREAKPOINT_STAGES` (JSON) ou `GLPICHAT_GUEST_BREAKPOINT_STAGE1_RATE`/`_DURATION` ... `STAGE4`
- `GLPICHAT_GUEST_BREAKPOINT_START_RATE`, `GLPICHAT_GUEST_BREAKPOINT_PRE_ALLOCATED_VUS`, `GLPICHAT_GUEST_BREAKPOINT_MAX_VUS`
- `GLPICHAT_GUEST_BREAKPOINT_P95_MS`

### `rooms_scale.js`
Simula N salas (tickets) ativas simultaneamente — cada sala com um agente fazendo poll e um guest fazendo poll+send — para medir "quantos chats simultâneos" o sistema aguenta.

- `GLPICHAT_ROOMS_JSON`: array JSON de salas, cada uma com `{ticket_id, widget_cookie, widget_csrf, guest_cookie}`. Exemplo:
  ```json
  [
    {"ticket_id":"101","widget_cookie":"glpi_...","widget_csrf":"abc","guest_cookie":"glpichat_guest=..."},
    {"ticket_id":"102","widget_cookie":"glpi_...","widget_csrf":"def","guest_cookie":"glpichat_guest=..."}
  ]
  ```
  Sem esta variável, o script lança erro explicando o formato esperado.
- `GLPICHAT_ROOMS_ITERATIONS`: iterações de ciclo poll+send por sala (default 30)
- `GLPICHAT_ROOMS_MAX_DURATION`, `GLPICHAT_ROOMS_P95_MS`

### `message_throughput.js`
Rampeia envio de mensagens via `Send` (agente) e `Guest/Send` (convidado) para medir mensagens/s sustentável. Respeita o throttle de ~2s do `Guest/Send`: 429 é considerado resposta válida acima da taxa permitida, não é burlado.

- `GLPICHAT_WIDGET_COOKIE`, `GLPICHAT_WIDGET_CSRF_TOKEN`, `GLPICHAT_WIDGET_TICKET_ID`
- `GLPICHAT_GUEST_COOKIE`
- `GLPICHAT_SEND_STAGES` (JSON) ou `GLPICHAT_SEND_STAGE1_RATE`/`_DURATION` ... `STAGE3` (agente)
- `GLPICHAT_GUEST_SEND_STAGES` (JSON) ou `GLPICHAT_GUEST_SEND_STAGE1_RATE`/`_DURATION` ... `STAGE3` (convidado)
- `GLPICHAT_SEND_START_RATE`, `GLPICHAT_SEND_PRE_ALLOCATED_VUS`, `GLPICHAT_SEND_MAX_VUS`, `GLPICHAT_SEND_P95_MS`
- `GLPICHAT_GUEST_SEND_START_RATE`, `GLPICHAT_GUEST_SEND_PRE_ALLOCATED_VUS`, `GLPICHAT_GUEST_SEND_MAX_VUS`

### `upload_throughput.js`
Uploads concorrentes via `Upload` (agente) e `Guest/Upload` (convidado), usando um arquivo `.txt` pequeno gerado em memória via `http.file()` (nunca arquivo real).

- `GLPICHAT_WIDGET_COOKIE`, `GLPICHAT_WIDGET_CSRF_TOKEN`, `GLPICHAT_WIDGET_TICKET_ID`
- `GLPICHAT_GUEST_COOKIE`
- `GLPICHAT_UPLOAD_FILE_REPEAT`: quantas vezes repetir o conteúdo sintético do arquivo (controla o tamanho)
- `GLPICHAT_UPLOAD_STAGE1_VUS`/`_DURATION` ... `STAGE3` (agente, `ramping-vus`)
- `GLPICHAT_GUEST_UPLOAD_STAGE1_VUS`/`_DURATION` ... `STAGE2` (convidado)
- `GLPICHAT_UPLOAD_P95_MS`

### `guest_onboarding_burst.js`
Simula rajada de aceite de convites (`Accept/{token}`) vindos de IPs diferentes (via header `X-Forwarded-For`), validando que o rate limit de 10 tentativas/60s por IP responde com 429 sem crash do serviço. Não é teste de abuso/DoS.

- `GLPICHAT_INVITE_TOKENS_JSON`: array JSON de tokens de convite válidos, um por "IP simulado", ex: `["token1","token2","token3"]`
- `GLPICHAT_ONBOARDING_ATTEMPTS_PER_IP`: tentativas por IP simulado dentro da janela (default 15, acima do limite de 10, para observar o 429)
- `GLPICHAT_ONBOARDING_IP_OCTET`, `GLPICHAT_ONBOARDING_MAX_DURATION`

### `realistic_mix.js`
Cenário único combinando proporção realista de tráfego: ~80% `Widget/Poll`, ~15% `Send`, ~4% `Widget/MarkRead`, ~1% `Upload`, cada um como scenario próprio com rate proporcional ao total.

- `GLPICHAT_WIDGET_COOKIE`, `GLPICHAT_WIDGET_CSRF_TOKEN`, `GLPICHAT_WIDGET_TICKET_ID`
- `GLPICHAT_MIX_TOTAL_RATE`: taxa total de req/s do mix (default 20)
- `GLPICHAT_MIX_DURATION`: duração do mix (default 5m)
- `GLPICHAT_MIX_POLL_RATIO`, `GLPICHAT_MIX_SEND_RATIO`, `GLPICHAT_MIX_MARKREAD_RATIO`, `GLPICHAT_MIX_UPLOAD_RATIO` (defaults 0.80/0.15/0.04/0.01)
- `GLPICHAT_MIX_POLL_P95_MS`, `GLPICHAT_MIX_SEND_P95_MS`

### `soak.js`
Carga moderada e constante (poll + send mesclados) por duração longa configurável, para detectar degradação/vazamento ao longo do tempo.

- `GLPICHAT_WIDGET_COOKIE`, `GLPICHAT_WIDGET_CSRF_TOKEN`, `GLPICHAT_WIDGET_TICKET_ID`
- `GLPICHAT_GUEST_COOKIE`
- `GLPICHAT_SOAK_DURATION`: duração total do soak test (default `30m`)
- `GLPICHAT_SOAK_WIDGET_RATE`, `GLPICHAT_SOAK_SEND_RATE`, `GLPICHAT_SOAK_GUEST_RATE`
- `GLPICHAT_SOAK_WIDGET_P95_MS`, `GLPICHAT_SOAK_SEND_P95_MS`, `GLPICHAT_SOAK_GUEST_P95_MS`

## Convenções

- Todos os scripts são parametrizados via `__ENV.GLPICHAT_*`, com defaults sensatos.
- Nenhuma credencial, token, cookie ou URL de produção é hardcoded — tudo vem de variável de ambiente.
- Cenários só são ativados se as env vars necessárias estiverem presentes; caso contrário o script lança erro explicando o que falta.
- Nenhum teste tenta burlar ou desabilitar rate limiting — apenas medir/validar seu comportamento.
- **Cuidado operacional**: `GLPICHAT_ROOMS_JSON` e `GLPICHAT_INVITE_TOKENS_JSON` carregam cookies/tokens reais de sessão e convite. Não exporte essas variáveis em shells compartilhados nem em logs de CI públicos/acessíveis a terceiros.
