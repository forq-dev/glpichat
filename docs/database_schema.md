# Modelagem do Banco de Dados — GLPI Chat

Este documento detalha o esquema de banco de dados utilizado pelo plugin **GLPI Chat**, incluindo tabelas, colunas, tipos de dados, chaves, índices e a relação lógica com o core do GLPI.

Todas as tabelas usam a engine `InnoDB` com o charset `utf8mb4` e a collation `utf8mb4_unicode_ci` (`ROW_FORMAT=DYNAMIC`) para suportar emojis e caracteres de múltiplos bytes.

---

## 🗺️ Visão Geral das Relações (DER Lógico)

```
       ┌────────────────────────┐
       │      glpi_tickets      │ (Core do GLPI)
       └────────────────────────┘
                    │ 1
                    │
                    ▼ 0..*
 ┌─────────────────────────────────────┐
 │     glpi_plugin_glpichat_rooms      │◄──────────────────────────┐
 └─────────────────────────────────────┘ 1                         │
         │ 1                   │ 1                                 │
         │                     │                                   │
         ▼ 0..*                ▼ 0..*                              │ 1
 ┌───────────────┐     ┌───────────────┐                  ┌─────────────────┐
 │ participants  │     │   messages    │                  │     invites     │
 └───────────────┘     └───────────────┘                  └─────────────────┘
         │ 1                   │ 0..1                              │ 0..1
         │                     │ (opcional)                        │
         ▼ 0..*                ▼                                   │
 ┌───────────────┐     ┌───────────────┐                           │
 │  readstates   │     │  glpi_docs    │◄──────────────────────────┘
 └───────────────┘     └───────────────┘ (Core)
```

---

## 🗃️ Detalhamento das Tabelas

### 1. `glpi_plugin_glpichat_rooms`
Define as salas de chat ativas. Cada chamado pode possuir até duas salas independentes (uma pública e uma interna).

* **Chaves e Restrições**:
  * `PRIMARY KEY`: (`id`)
  * `UNIQUE KEY` `unicity`: (`tickets_id`, `room_type`) — Garante que não haja duplicidade de canais para o mesmo chamado.

| Coluna | Tipo | Nulo | Padrão | Descrição |
| :--- | :--- | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | Não | *AUTO_INCREMENT* | Identificador único da sala. |
| `tickets_id` | `INT UNSIGNED` | Não | | ID do chamado associado (`glpi_tickets.id`). |
| `room_type` | `VARCHAR(20)` | Não | | Tipo da sala (`public` para atendimento com solicitante; `internal` para equipe técnica). |
| `date_creation` | `TIMESTAMP` | Não | `CURRENT_TIMESTAMP` | Data/hora de criação do canal. |
| `date_mod` | `TIMESTAMP` | Sim | `NULL` | Data/hora de modificação do canal. |

---

### 2. `glpi_plugin_glpichat_participants`
Representa as sessões ativas dos usuários e convidados que interagiram em uma sala.

* **Chaves e Restrições**:
  * `PRIMARY KEY`: (`id`)
  * `INDEX` `participants_room_user`: (`rooms_id`, `users_id`)
  * `INDEX` `participants_session`: (`session_token_hash`) — Usado para autenticação de convidados externos.

| Coluna | Tipo | Nulo | Padrão | Descrição |
| :--- | :--- | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | Não | *AUTO_INCREMENT* | Identificador do participante. |
| `rooms_id` | `INT UNSIGNED` | Não | | ID da sala vinculada (`glpi_plugin_glpichat_rooms.id`). |
| `users_id` | `INT UNSIGNED` | Não | `0` | ID do usuário no GLPI (`glpi_users.id`). Será `0` para convidados externos. |
| `guest_name` | `VARCHAR(255)` | Sim | `NULL` | Nome do convidado externo (se aplicável). |
| `guest_email` | `VARCHAR(255)` | Sim | `NULL` | E-mail do convidado externo (se aplicável). |
| `participant_type` | `VARCHAR(20)` | Não | | Tipo de participante (`user` ou `guest`). |
| `session_token_hash`| `CHAR(64)` | Sim | `NULL` | Hash SHA-256 do token de sessão ativo de convidados. |
| `joined_at` | `TIMESTAMP` | Não | `CURRENT_TIMESTAMP` | Data/hora de entrada na sala. |
| `left_at` | `TIMESTAMP` | Sim | `NULL` | Data/hora de saída da sala (se revogado/desconectado). |

---

### 3. `glpi_plugin_glpichat_messages`
Armazena as mensagens trocadas em todas as salas.

* **Chaves e Restrições**:
  * `PRIMARY KEY`: (`id`)
  * `INDEX` `messages_room_id`: (`rooms_id`, `id`)
  * `INDEX` `messages_ticket_id`: (`tickets_id`, `id`)
  * `INDEX` `messages_participant_id`: (`participants_id`, `id`)

| Coluna | Tipo | Nulo | Padrão | Descrição |
| :--- | :--- | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | Não | *AUTO_INCREMENT* | Identificador único da mensagem. |
| `rooms_id` | `INT UNSIGNED` | Não | | ID da sala (`glpi_plugin_glpichat_rooms.id`). |
| `tickets_id` | `INT UNSIGNED` | Não | | ID do chamado (`glpi_tickets.id`). |
| `participant_type` | `VARCHAR(20)` | Não | | Tipo de emissor (`user` ou `guest`). |
| `users_id` | `INT UNSIGNED` | Não | `0` | ID do usuário emissor (`glpi_users.id`). `0` se for convidado ou sistema. |
| `participants_id` | `INT UNSIGNED` | Não | `0` | ID do participante emissor (`glpi_plugin_glpichat_participants.id`). |
| `message_type` | `VARCHAR(30)` | Não | | Tipo da mensagem (`message` ou `attachment`). |
| `content` | `LONGTEXT` | Não | | Conteúdo em texto plano/HTML sanitizado. |
| `documents_id` | `INT UNSIGNED` | Não | `0` | ID do anexo no GLPI (`glpi_documents.id`), se for `attachment`. |
| `itilfollowups_id` | `INT UNSIGNED` | Não | `0` | ID do acompanhamento gerado no core (`glpi_itilfollowups.id`). |
| `date_creation` | `TIMESTAMP` | Não | `CURRENT_TIMESTAMP` | Data/hora de envio da mensagem. |

---

### 4. `glpi_plugin_glpichat_events`
Log incremental de deltas que permite ao frontend atualizar o widget sem requisitar o histórico inteiro.

* **Chaves e Restrições**:
  * `PRIMARY KEY`: (`id`)
  * `INDEX` `events_room_id`: (`rooms_id`, `id`)
  * `INDEX` `events_ticket_id`: (`tickets_id`, `id`)

| Coluna | Tipo | Nulo | Padrão | Descrição |
| :--- | :--- | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | Não | *AUTO_INCREMENT* | Identificador único do evento. |
| `rooms_id` | `INT UNSIGNED` | Não | | ID da sala associada. |
| `tickets_id` | `INT UNSIGNED` | Não | | ID do chamado associado. |
| `event_type` | `VARCHAR(60)` | Não | | Nome do evento (ex: `message_created`, `guest_invited`, `guest_joined`). |
| `actor_type` | `VARCHAR(20)` | Não | | Quem gerou o evento (`user`, `guest` ou `system`). |
| `users_id` | `INT UNSIGNED` | Não | `0` | ID do usuário que gerou o evento (se aplicável). |
| `participants_id` | `INT UNSIGNED` | Não | `0` | ID do participante que gerou o evento. |
| `payload_json` | `JSON` | Não | | Payload com dados variáveis em formato estruturado. |
| `date_creation` | `TIMESTAMP` | Não | `CURRENT_TIMESTAMP` | Data/hora do evento. |

---

### 5. `glpi_plugin_glpichat_invites`
Gere os convites gerados para terceiros acessarem a sala pública de um chamado.

* **Chaves e Restrições**:
  * `PRIMARY KEY`: (`id`)
  * `UNIQUE KEY` `token_hash`: (`token_hash`) — Garante a unicidade do link de convite.
  * `INDEX` `invites_expires_at`: (`expires_at`)

| Coluna | Tipo | Nulo | Padrão | Descrição |
| :--- | :--- | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | Não | *AUTO_INCREMENT* | Identificador único do convite. |
| `rooms_id` | `INT UNSIGNED` | Não | | ID da sala associada. |
| `tickets_id` | `INT UNSIGNED` | Não | | ID do chamado associado. |
| `token_hash` | `CHAR(64)` | Não | | Hash SHA-256 do token gerado para a URL de aceite. |
| `created_by` | `INT UNSIGNED` | Não | | ID do usuário que criou o convite (`glpi_users.id`). |
| `guest_name` | `VARCHAR(255)` | Não | | Nome informado para o convidado. |
| `guest_email` | `VARCHAR(255)` | Sim | `NULL` | E-mail do convidado. |
| `expires_at` | `TIMESTAMP` | Não | | Data/hora de expiração do convite (TTL padrão: 3600 segundos). |
| `accepted_at` | `TIMESTAMP` | Sim | `NULL` | Data/hora em que o convidado clicou no link e aceitou. |
| `revoked_at` | `TIMESTAMP` | Sim | `NULL` | Data/hora de revogação manual pelo técnico/administrador. |
| `participants_id` | `INT UNSIGNED` | Não | `0` | ID do participante gerado após o aceite. |
| `date_creation` | `TIMESTAMP` | Não | `CURRENT_TIMESTAMP` | Data/hora de criação do convite. |

---

### 6. `glpi_plugin_glpichat_readstates`
Persiste o marcador de leitura de mensagens dos participantes. É utilizado para calcular a contagem de mensagens não lidas.

* **Chaves e Restrições**:
  * `PRIMARY KEY`: (`id`)
  * `UNIQUE KEY` `room_participant`: (`rooms_id`, `participants_id`)
  * `INDEX` `readstates_participant_room`: (`participants_id`, `rooms_id`)

| Coluna | Tipo | Nulo | Padrão | Descrição |
| :--- | :--- | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | Não | *AUTO_INCREMENT* | Identificador do estado de leitura. |
| `rooms_id` | `INT UNSIGNED` | Não | | ID da sala. |
| `participants_id` | `INT UNSIGNED` | Não | | ID do participante (`glpi_plugin_glpichat_participants.id`). |
| `last_read_messages_id`|`INT UNSIGNED`| Não | `0` | ID da última mensagem lida (`glpi_plugin_glpichat_messages.id`). |
| `date_mod` | `TIMESTAMP` | Não | `CURRENT_TIMESTAMP` | Data/hora de atualização do ponteiro de leitura. |

---

### 7. `glpi_plugin_glpichat_auditlogs`
Armazena logs permanentes das ações cruciais de segurança para auditoria e controle de supervisão.

* **Chaves e Restrições**:
  * `PRIMARY KEY`: (`id`)
  * `INDEX` `audit_ticket_date`: (`tickets_id`, `date_creation`)

| Coluna | Tipo | Nulo | Padrão | Descrição |
| :--- | :--- | :---: | :---: | :--- |
| `id` | `INT UNSIGNED` | Não | *AUTO_INCREMENT* | Identificador do log. |
| `rooms_id` | `INT UNSIGNED` | Não | | ID da sala associada. |
| `tickets_id` | `INT UNSIGNED` | Não | | ID do chamado associado. |
| `action` | `VARCHAR(60)` | Não | | Nome da ação executada (ex: `guest_invited`, `invite_revoked`). |
| `actor_type` | `VARCHAR(20)` | Não | | Tipo do executor (`user`, `guest`, `system`). |
| `users_id` | `INT UNSIGNED` | Não | `0` | ID do usuário executor. |
| `participants_id` | `INT UNSIGNED` | Não | `0` | ID do participante executor. |
| `payload_json` | `JSON` | Não | | Dados adicionais do evento. |
| `date_creation` | `TIMESTAMP` | Não | `CURRENT_TIMESTAMP` | Data/hora do registro de auditoria. |

---

## 🚀 Otimizações e Índices de Performance

Para evitar consultas lentas em instâncias do GLPI com volumes massivos de chamados e mensagens, o plugin adiciona índices secundários na instalação (em `functions.php::glpichat_add_indexes()`):

1. **Mensagens**:
   * O índice composto `(rooms_id, id)` acelera a recuperação incremental:
     `WHERE rooms_id = ? AND id > ?`
   * O índice `(tickets_id, id)` otimiza a listagem de auditoria ou feeds de mensagens por chamado.
2. **Leitura e Presença**:
   * O índice composto `(participants_id, rooms_id)` reduz drasticamente a busca pelo último ponteiro de leitura durante o polling de múltiplos canais.
3. **Segurança e Convites**:
   * A busca por convites e tokens baseia-se na `UNIQUE KEY` do hash SHA-256 (`token_hash`), resultando em busca de complexidade $O(1)$.
   * O índice `expires_at` é usado para filtrar e desconsiderar links expirados.
