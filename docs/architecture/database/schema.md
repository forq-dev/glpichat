# Database — Schema de Banco

> **Audiência:** Desenvolvedor
> **Relacionado:** [Índice de Documentação Técnica](../../README.md)

## O que é

Schema completo das 7 tabelas do plugin GLPI Chat: estrutura de campos, índices, relacionamentos lógicos e valores válidos para campos de estado e tipo.

## Estrutura

Todas as tabelas usam engine `InnoDB`, charset `utf8mb4`, collation `utf8mb4_unicode_ci` e `ROW_FORMAT=DYNAMIC`.

### `glpi_plugin_glpichat_rooms`

Define as salas de chat. Cada chamado pode ter até duas salas independentes (pública e interna).

**Chaves:** `PRIMARY KEY (id)` | `UNIQUE KEY unicity (tickets_id, room_type)`

| Coluna | Tipo | Nulo | Padrão | Descrição |
|--------|------|:----:|:------:|-----------|
| `id` | `INT UNSIGNED` | Não | AUTO_INCREMENT | Identificador único |
| `tickets_id` | `INT UNSIGNED` | Não | — | FK lógica para `glpi_tickets.id` |
| `room_type` | `VARCHAR(20)` | Não | — | Tipo da sala (`public` ou `internal`) |
| `date_creation` | `TIMESTAMP` | Não | CURRENT_TIMESTAMP | Data de criação |
| `date_mod` | `TIMESTAMP` | Sim | NULL | Data de modificação |

---

### `glpi_plugin_glpichat_participants`

Sessões de usuários e convidados que interagiram em uma sala.

**Chaves:** `PRIMARY KEY (id)` | `INDEX participants_room_user (users_id, rooms_id)` | `INDEX participants_session (session_token_hash)`

| Coluna | Tipo | Nulo | Padrão | Descrição |
|--------|------|:----:|:------:|-----------|
| `id` | `INT UNSIGNED` | Não | AUTO_INCREMENT | Identificador |
| `rooms_id` | `INT UNSIGNED` | Não | — | FK lógica para `rooms.id` |
| `users_id` | `INT UNSIGNED` | Não | `0` | FK lógica para `glpi_users.id`; `0` para convidados |
| `guest_name` | `VARCHAR(255)` | Sim | NULL | Nome do convidado externo |
| `guest_email` | `VARCHAR(255)` | Sim | NULL | Email do convidado |
| `participant_type` | `VARCHAR(20)` | Não | — | `user` ou `guest` |
| `session_token_hash` | `CHAR(64)` | Sim | NULL | Hash SHA-256 do token de sessão do convidado |
| `last_activity` | `TIMESTAMP` | Sim | NULL | Última interação (atualizado a cada sync/send) |
| `last_seen` | `TIMESTAMP` | Sim | NULL | Última requisição recebida |
| `is_typing_until` | `TIMESTAMP` | Sim | NULL | Indicador de digitação; expira 4s após o último sinal |
| `joined_at` | `TIMESTAMP` | Não | CURRENT_TIMESTAMP | Data de entrada na sala |
| `left_at` | `TIMESTAMP` | Sim | NULL | Data de saída ou desconexão |

---

### `glpi_plugin_glpichat_messages`

Mensagens trocadas em todas as salas.

**Chaves:** `PRIMARY KEY (id)` | `INDEX messages_room_id (rooms_id, id)` | `INDEX messages_ticket_id (tickets_id, id)` | `INDEX messages_participant_id (participants_id, id)`

| Coluna | Tipo | Nulo | Padrão | Descrição |
|--------|------|:----:|:------:|-----------|
| `id` | `INT UNSIGNED` | Não | AUTO_INCREMENT | Identificador único |
| `rooms_id` | `INT UNSIGNED` | Não | — | FK lógica para `rooms.id` |
| `tickets_id` | `INT UNSIGNED` | Não | — | FK lógica para `glpi_tickets.id` |
| `participant_type` | `VARCHAR(20)` | Não | — | `user` ou `guest` |
| `users_id` | `INT UNSIGNED` | Não | `0` | ID do usuário emissor; `0` para convidado ou sistema |
| `participants_id` | `INT UNSIGNED` | Não | `0` | FK lógica para `participants.id` |
| `message_type` | `VARCHAR(30)` | Não | — | `message` ou `attachment` |
| `content` | `LONGTEXT` | Não | — | Conteúdo em texto plano ou HTML sanitizado |
| `documents_id` | `INT UNSIGNED` | Não | `0` | FK lógica para `glpi_documents.id`; `0` se não for anexo |
| `itilfollowups_id` | `INT UNSIGNED` | Não | `0` | FK lógica para `glpi_itilfollowups.id` |
| `date_creation` | `TIMESTAMP` | Não | CURRENT_TIMESTAMP | Data de envio |

---

### `glpi_plugin_glpichat_events`

Log incremental de deltas para sincronização eficiente do frontend (polling).

**Chaves:** `PRIMARY KEY (id)` | `INDEX events_room_id (rooms_id, id)` | `INDEX events_ticket_id (tickets_id, id)`

| Coluna | Tipo | Nulo | Padrão | Descrição |
|--------|------|:----:|:------:|-----------|
| `id` | `INT UNSIGNED` | Não | AUTO_INCREMENT | Identificador único |
| `rooms_id` | `INT UNSIGNED` | Não | — | FK lógica para `rooms.id` |
| `tickets_id` | `INT UNSIGNED` | Não | — | FK lógica para `glpi_tickets.id` |
| `event_type` | `VARCHAR(60)` | Não | — | Nome do evento (ver tabela de valores) |
| `actor_type` | `VARCHAR(20)` | Não | — | `user`, `guest` ou `system` |
| `users_id` | `INT UNSIGNED` | Não | `0` | ID do usuário que gerou o evento |
| `participants_id` | `INT UNSIGNED` | Não | `0` | ID do participante que gerou o evento |
| `payload_json` | `JSON` | Não | — | Dados variáveis estruturados do evento |
| `date_creation` | `TIMESTAMP` | Não | CURRENT_TIMESTAMP | Data do evento |

---

### `glpi_plugin_glpichat_invites`

Convites gerados para terceiros externos acessarem a sala pública.

**Chaves:** `PRIMARY KEY (id)` | `UNIQUE KEY token_hash (token_hash)` | `INDEX invites_expires_at (expires_at)`

| Coluna | Tipo | Nulo | Padrão | Descrição |
|--------|------|:----:|:------:|-----------|
| `id` | `INT UNSIGNED` | Não | AUTO_INCREMENT | Identificador único |
| `rooms_id` | `INT UNSIGNED` | Não | — | FK lógica para `rooms.id` |
| `tickets_id` | `INT UNSIGNED` | Não | — | FK lógica para `glpi_tickets.id` |
| `token_hash` | `CHAR(64)` | Não | — | Hash SHA-256 do token do link de aceite |
| `created_by` | `INT UNSIGNED` | Não | — | FK lógica para `glpi_users.id` |
| `guest_name` | `VARCHAR(255)` | Não | — | Nome informado para o convidado |
| `guest_email` | `VARCHAR(255)` | Sim | NULL | Email do convidado |
| `expires_at` | `TIMESTAMP` | Não | — | Expiração do convite (TTL: 3600s) |
| `accepted_at` | `TIMESTAMP` | Sim | NULL | Data em que o convidado usou o link |
| `revoked_at` | `TIMESTAMP` | Sim | NULL | Data de revogação manual |
| `participants_id` | `INT UNSIGNED` | Não | `0` | FK lógica para `participants.id` após o aceite |
| `date_creation` | `TIMESTAMP` | Não | CURRENT_TIMESTAMP | Data de criação do convite |

---

### `glpi_plugin_glpichat_readstates`

Ponteiro de leitura por participante e sala, usado para calcular mensagens não lidas.

**Chaves:** `PRIMARY KEY (id)` | `UNIQUE KEY room_participant (rooms_id, participants_id)` | `INDEX readstates_participant_room (participants_id, rooms_id)`

| Coluna | Tipo | Nulo | Padrão | Descrição |
|--------|------|:----:|:------:|-----------|
| `id` | `INT UNSIGNED` | Não | AUTO_INCREMENT | Identificador |
| `rooms_id` | `INT UNSIGNED` | Não | — | FK lógica para `rooms.id` |
| `participants_id` | `INT UNSIGNED` | Não | — | FK lógica para `participants.id` |
| `last_read_messages_id` | `INT UNSIGNED` | Não | `0` | ID da última mensagem lida |
| `date_mod` | `TIMESTAMP` | Não | CURRENT_TIMESTAMP | Data de atualização do ponteiro |

---

### `glpi_plugin_glpichat_auditlogs`

Log permanente de ações críticas para auditoria e supervisão.

**Chaves:** `PRIMARY KEY (id)` | `INDEX audit_ticket_date (tickets_id, date_creation)`

| Coluna | Tipo | Nulo | Padrão | Descrição |
|--------|------|:----:|:------:|-----------|
| `id` | `INT UNSIGNED` | Não | AUTO_INCREMENT | Identificador |
| `rooms_id` | `INT UNSIGNED` | Não | — | FK lógica para `rooms.id` |
| `tickets_id` | `INT UNSIGNED` | Não | — | FK lógica para `glpi_tickets.id` |
| `action` | `VARCHAR(60)` | Não | — | Nome da ação (ex: `guest_invited`, `invite_revoked`) |
| `actor_type` | `VARCHAR(20)` | Não | — | `user`, `guest` ou `system` |
| `users_id` | `INT UNSIGNED` | Não | `0` | ID do usuário executor |
| `participants_id` | `INT UNSIGNED` | Não | `0` | ID do participante executor |
| `payload_json` | `JSON` | Não | — | Dados adicionais do evento |
| `date_creation` | `TIMESTAMP` | Não | CURRENT_TIMESTAMP | Data do registro |

---

## Relacionamentos

Todas as foreign keys são **lógicas** — sem constraints `FOREIGN KEY` no banco. O plugin gerencia integridade referencial no código PHP.

```
       ┌────────────────────────┐
       │      glpi_tickets      │  (Core do GLPI)
       └────────────────────────┘
                    │ 1
                    ▼ 0..*
 ┌─────────────────────────────────────┐
 │     glpi_plugin_glpichat_rooms      │◄──────────────────────────┐
 └─────────────────────────────────────┘ 1                         │
         │ 1                   │ 1                                 │
         ▼ 0..*                ▼ 0..*                              │ 1
 ┌───────────────┐     ┌───────────────┐                  ┌─────────────────┐
 │ participants  │     │   messages    │                  │     invites     │
 └───────────────┘     └───────────────┘                  └─────────────────┘
         │ 1                   │ 0..1                              │ 0..1
         ▼ 0..*                ▼                                   │
 ┌───────────────┐     ┌───────────────┐                           │
 │  readstates   │     │  glpi_docs    │◄──────────────────────────┘
 └───────────────┘     └───────────────┘  (Core)
```

A ausência de FK reais significa que registros órfãos são possíveis se chamados forem deletados diretamente no banco sem passar pelo ciclo de vida do GLPI.

## O que cada estado/valor significa

### `room_type`

| Valor | Significado |
|-------|-------------|
| `public` | Sala do solicitante — acessível a técnicos, solicitantes e convidados externos |
| `internal` | Sala de discussão técnica — acessível apenas a técnicos com `plugin_glpichat_internal` (Read) |

### `participant_type` e `actor_type`

| Valor | Significado |
|-------|-------------|
| `user` | Usuário autenticado no GLPI |
| `guest` | Convidado externo (sem conta GLPI) |
| `system` | Evento gerado automaticamente pelo plugin (ex: expiração de sessão) |

### `event_type` (valores conhecidos)

| Valor | Quando é gerado |
|-------|----------------|
| `message_created` | Mensagem de texto enviada |
| `attachment_created` | Arquivo enviado |
| `guest_invited` | Convite criado |
| `guest_joined` | Convidado aceitou o convite |
| `guest_left` | Convidado saiu voluntariamente |
| `guest_left_timeout` | Sessão expirou por inatividade |
| `invite_revoked` | Convite cancelado ou reemitido |
| `ticket_status_changed` | Status do chamado alterado |
| `ticket_priority_changed` | Prioridade alterada |
| `ticket_assigned` | Técnico ou grupo atribuído |
| `followup_added` | Acompanhamento adicionado fora do chat |

### `message_type`

| Valor | Significado |
|-------|-------------|
| `message` | Texto enviado pelo participante |
| `attachment` | Arquivo enviado — `documents_id` aponta para o registro em `glpi_documents` |

## Riscos e pontos de atenção

**Crescimento da tabela `glpi_plugin_glpichat_events`:** Esta tabela cresce com cada polling e cada evento do sistema. Uma CronTask do plugin limpa eventos com mais de 30 dias. Em instâncias com alto volume de chamados e muitos participantes, monitorar o tamanho da tabela periodicamente.

**`glpi_plugin_glpichat_auditlogs` não tem limpeza automática:** O log de auditoria cresce indefinidamente. Não existe CronTask de purga — qualquer estratégia de retenção exige implementação manual ou script externo.

**`session_token_hash` nunca deve ser logado:** O campo contém o hash SHA-256 do token de sessão do convidado. Logar esse valor equivale a expor a credencial de sessão. Nunca incluir em logs de debug, responses de API ou registros de auditoria.

**`itilfollowups_id` é FK lógica sem constraint:** Se um `ITILFollowup` for deletado diretamente no banco, a mensagem correspondente ficará com um ID órfão. O código PHP não verifica a existência do followup ao renderizar mensagens antigas.

**Ausência de constraints FK:** Deletar um chamado diretamente no banco (sem passar pelo ciclo de vida do GLPI) deixa todas as tabelas do plugin com registros órfãos para aquele `tickets_id`. O plugin não registra hook de purga para `Ticket`.
