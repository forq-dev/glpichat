# Integração GLPI — Ambiente e Configuração (pré-requisitos de deploy)

> **Audiência:** Administrador / Desenvolvedor
> **Relacionado:** [Índice de Documentação Técnica](../../README.md) · [Sistema de Permissões](permissions.md) · [Gestão de Convidados](../../features/invites.md) · [Notificações](../../features/notifications.md)

## O que é

O GLPI Chat depende de algumas configurações do ambiente GLPI e do próprio plugin para funcionar de ponta a ponta. Alguns fluxos (especialmente o de **convidado externo**) **falham silenciosamente** se esses pré-requisitos não estiverem corretos. Este documento lista o que precisa estar configurado antes de usar o plugin em produção e como cada item é validado pela suíte de testes.

---

## Pré-requisitos obrigatórios

### 1. `url_base` do GLPI deve refletir a URL real de acesso

- **Onde:** Configuração do GLPI (`Setup > Geral > URL da aplicação`), armazenado em `glpi_configs` (`context=core`, `name=url_base`).
- **Por quê:** O link de convite e o redirecionamento de aceite do convidado são gerados a partir de `CFG_GLPI['url_base']`. Se o `url_base` não corresponder ao host/porta pelos quais o GLPI é realmente acessado (ex.: `http://localhost` enquanto o serviço roda em `http://localhost:8080`), o link de convite aponta para um endereço inacessível e o **fluxo do convidado quebra** (link não abre / sessão não é criada).
- **Sintoma de configuração errada:** o convidado recebe um link que não abre, ou a página de aceite redireciona para um host/porta que recusa conexão.
- **Correto:** `url_base` = exatamente a URL pública pela qual os usuários acessam o GLPI (com a porta, se aplicável).

### 2. Usuário de followup externo (`external_followup_user_id`) — **obrigatório para o fluxo do convidado**

- **Onde:** `Configuração > Plugins > GLPI Chat`, aba **Integração** (campo "Usuário associado a mensagens de convidados externos"). Armazenado em `glpi_configs` (`context=plugin:glpichat`, `name=external_followup_user_id`).
- **Por quê:** Quando um convidado **aceita** o convite e quando **envia** mensagens, o plugin cria um `ITILFollowup` no chamado usando esse usuário. Se a config estiver **vazia/0 ou apontar para um usuário inexistente/deletado**, `glpichat_external_followup_user_id()` lança exceção e o **aceite do convite falha (HTTP 400)** — a sessão do convidado nunca é criada.
- **Sintoma de configuração errada:** o convidado clica no link válido, mas a página de chat não carrega (erro "Sessão de acesso inválida ou ausente") e nada acontece.
- **Correto:** selecione um usuário GLPI válido e ativo (ex.: um usuário de serviço/bot, ou um técnico). O save da config já valida a existência do usuário (`glpichat_is_valid_external_followup_user_id`).

### 3. Direitos de perfil (`plugin_glpichat_*`)

- **Onde:** `Administração > Perfis`, aba de direitos (matriz nativa do GLPI). Ver [Sistema de Permissões](permissions.md).
- **Por quê:** Sem os direitos corretos por perfil, o widget não aparece, ou canais/abas/ações ficam indisponíveis:
  - `plugin_glpichat_public` (READ/CREATE) — ler/enviar no canal Solicitante.
  - `plugin_glpichat_internal` (READ/CREATE) — canal Discussão técnica.
  - `plugin_glpichat_invite` (READ/CREATE/UPDATE) — ver/criar/reemitir-revogar convites.
  - `plugin_glpichat_view_audit` (READ) — aba de Auditoria.
  - `plugin_glpichat_admin` (READ/UPDATE) — página de configuração do plugin.
- **Observação Helpdesk:** os direitos são injetados em `Profile::$helpdesk_rights` na inicialização do plugin para não serem removidos da sessão de interface simplificada. Se o plugin não inicializar (`plugin_init_glpichat`), usuários Helpdesk perdem acesso ao widget.

### 4. Entrega de notificações por e-mail (opcional, mas recomendado)

- **Onde:** `Configuração > Notificações` (ativar notificações + SMTP) e os 6 eventos "GLPI CHAT" (ver [Notificações](../../features/notifications.md)).
- **Por quê:** O plugin **monta** as notificações (assunto, tags, destinatários), mas a **entrega** por e-mail depende do SMTP/configuração de notificações do GLPI. Sem isso, apenas o toast AJAX (com o GLPI aberto) é exibido; e-mails não são enviados.

---

## Tabela de configurações do plugin

Todas as chaves abaixo ficam em `Configuração > Plugins > GLPI Chat` (contexto `plugin:glpichat`). Valores fora dos limites são "clampados" no save.

| Chave | Default | Efeito |
|-------|---------|--------|
| `poll_focused_ms` / `poll_idle_ms` / `poll_bg_ms` | 2500 / 9000 / 18000 | Intervalos de polling do widget conforme foco/visibilidade |
| `guest_poll_ms` | 5000 | Intervalo de sincronização do convidado |
| `meta_sync_ms` | 15000 | Sincronização de metadados (não lidas, canais) |
| `invite_poll_ms` | 10000 | Atualização automática da lista de convites |
| `poll_max_channels` | 20 | Máximo de canais processados por requisição de poll |
| `user_inactivity_timeout_s` | 120 | Timeout para considerar usuário desconectado |
| `guest_session_ttl` | 3600 | TTL da sessão do convidado |
| `invite_ttl_seconds` | 3600 | Validade do link de convite |
| `guest_send_throttle_s` | 2 | Intervalo mínimo entre mensagens do convidado |
| `accept_rate_limit_window_s` / `accept_rate_limit_max_attempts` | 60 / 10 | Rate limit de aceite de convite por IP |
| `max_invites_per_ticket` | 10 | Máximo de convites ativos por chamado |
| `desktop_max_open` | 3 | Máximo de chats abertos no desktop |
| `widget_default_position` | bottom-right | Posição padrão do widget (whitelist) |
| `typing_indicator_timeout_ms` | 3000 | Tempo do indicador "digitando" |
| `auto_open_ticket_chat` | 1 | Abrir o chat automaticamente ao entrar no chamado |
| `auto_scroll_threshold_px` | 160 | Distância para auto-scroll |
| `unread_badge_threshold` | 2 | A partir de quantas não lidas o badge aparece |
| `history_page_default` / `history_page_max` | 30 / 50 | Paginação do histórico |
| `events_retention_days` / `messages_retention_days` / `auditlogs_retention_days` | 30 / 365 / 180 | Retenção (CronTasks de limpeza) |
| `external_followup_user_id` | — | **Usuário dos followups de convidado (obrigatório p/ guest)** |

> O efeito real de cada chave (lida do banco, não de defaults hardcoded) é validado por `tests/smoke/php/config_effect_smoke_test.php`.

---

## Como a suíte de testes valida esses pré-requisitos

A esteira `tests/run_full_suite.sh` prepara um ambiente determinístico e o leva até o fim:

- **`tests/e2e/setup/prepare_env.php`** cria um chamado dedicado (solicitante `self`, técnico `glpi`, status não-terminal), garante as salas do plugin e **configura `external_followup_user_id`** para um usuário válido — exatamente o pré-requisito do item 2.
- **`config_effect_smoke_test.php`** confirma que cada chave de config tem efeito real no consumidor correspondente.
- **`routes_smoke_test.php`** cobre as rotas do widget (Channels/Config/History/Poll/MarkRead/Leave).
- **`invite_form_smoke_test.php`** valida o formulário/fluxo de convite campo a campo e os bloqueios de permissão.
- **`profiletab_smoke_test.php`** valida a matriz de direitos do perfil.
- **`tests/lifecycle/install_uninstall_test.php`** valida o ciclo install → idempotência → migração de direitos → uninstall (sem órfãos) → reinstala.
- **E2E (Playwright)** exercita o fluxo visível (mensageria, imagens, presença, digitando, convidado).

> Em produção, o `url_base` (item 1) e o `external_followup_user_id` (item 2) precisam ser configurados manualmente no ambiente real — a preparação automática só vale para o ambiente de teste.

---

## Checklist rápido de deploy

- [ ] `url_base` do GLPI = URL real de acesso (com porta, se houver).
- [ ] `external_followup_user_id` aponta para um usuário GLPI válido e ativo.
- [ ] Direitos `plugin_glpichat_*` configurados nos perfis que usarão o chat.
- [ ] Notificações + SMTP configurados (se quiser e-mail além do toast).
- [ ] CronTasks de limpeza ativas (criadas no install) conforme a política de retenção desejada.
