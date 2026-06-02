# Mecanismo de Polling — GLPI Chat

Este documento detalha o funcionamento do mecanismo de **sincronização assíncrona incremental (polling)** do plugin **GLPI Chat**. Ele descreve como o frontend e o backend trocam mensagens e eventos em tempo real de forma eficiente, sem sobrecarregar o servidor web ou o banco de dados.

---

## ⚡ Intervalos Dinâmicos de Atualização

Para otimizar o consumo de recursos no servidor PHP e no banco de dados MariaDB/MySQL, a frequência com que o navegador faz requisições muda de acordo com o foco do usuário e o estado das janelas de chat:

```
                  ┌───────────────────────────────┐
                  │   Estado da Página no Navegador│
                  └───────────────┬───────────────┘
                                  │
                  ┌───────────────┴───────────────┐
         Sim      │      Aba está Visível?        │      Não
       ┌──────────┤ (Page Visibility API)         ├──────────┐
       │          └───────────────────────────────┘          │
       ▼                                                     ▼
┌───────────────────────────────┐                  ┌───────────────────────────────┐
│     Existem Chats Abertos?    │                  │  Intervalo em Segundo Plano   │
└───────┬───────────────┬───────┘                  │    POLL_BG_MS = 18000ms       │
        │ Sim           │ Não                      │       (Aba em background)     │
        ▼               ▼                          └───────────────────────────────┘
┌────────────────┐┌────────────────┐
│Intervalo Focado││ Intervalo Ocioso│
│POLL_FOCUSED_MS ││ POLL_IDLE_MS   │
│   = 2500ms     ││   = 9000ms     │
└────────────────┘└────────────────┘
```

1. **Intervalo Focado (`POLL_FOCUSED_MS = 2500ms`)**:
   * Ativado quando a página está visível e o usuário tem **pelo menos um chatbox de chamado aberto** no dock. Garante uma experiência de conversa fluida e ágil.
2. **Intervalo Ocioso (`POLL_IDLE_MS = 9000ms`)**:
   * Ativado quando a página está visível, mas **todas as janelas de chat estão minimizadas** (apenas o launcher principal com a lista de chamados e notificações está à mostra).
3. **Intervalo de Segundo Plano (`POLL_BG_MS = 18000ms`)**:
   * Ativado automaticamente usando a **Page Visibility API** quando a aba do navegador perde o foco (o usuário mudou de aba ou minimizou o navegador). Reduz a concorrência no servidor quando o usuário não está olhando para a tela.

---

## 🔄 Fluxo de Sincronização

A sincronização é dividida em dois tipos de requisições periódicas e complementares:

### A. Sincronização de Metadados (`syncChannelMetadata`)
* **Frequência**: Executada a cada **15 segundos** (`META_SYNC_MS = 15000ms`), desde que a aba esteja visível.
* **Endpoint**: `GET /plugins/glpichat/Widget/Channels`
* **Objetivo**: Atualizar o painel do launcher geral de conversas. Retorna a lista dos chamados vinculados ao usuário (com IDs de sala, tipo de sala, status do chamado no GLPI, contadores de mensagens não lidas e a identificação do usuário logado).
* **Parâmetro Opcional**: Se houver um chamado em foco na página ativa do GLPI, seu ID é enviado como `tickets_id` para garantir que o canal correspondente seja adicionado ou atualizado prioritariamente na fila visual.

### B. Polling de Mensagens e Eventos (`pollChannels`)
* **Frequência**: Definida pelos intervalos dinâmicos (2,5s / 9s / 18s).
* **Endpoint**: `POST /plugins/glpichat/Widget/Poll`
* **Objetivo**: Sincronizar o delta de dados das janelas de chat ativas.
* **Funcionamento**:
  1. O script JS mapeia as salas dos chamados atualmente abertos no dock.
  2. Para cada canal aberto, ele coleta o `tickets_id`, `room_type`, o ID da última mensagem carregada (`after_id`) e o ID do último evento carregado (`after_event_id`).
  3. Envia essa lista estruturada via requisição `POST` com token de segurança CSRF (`_glpi_csrf_token`).
  4. O backend consulta o banco usando filtros de índice e retorna as novas mensagens e eventos criados após os IDs informados pelo navegador.

---

## 🛠️ Endpoints e Formatos de Dados

### 1. Endpoint: `/Widget/Poll` (Usuário Autenticado)
* **Método**: `POST`
* **Headers**: `Content-Type: application/x-www-form-urlencoded`
* **Payload de Envio (Exemplo)**:
```ini
_glpi_csrf_token=abc123xyz
channels[0][tickets_id]=4502
channels[0][room_type]=public
channels[0][after_id]=122
channels[0][after_event_id]=8
channels[1][tickets_id]=4502
channels[1][room_type]=internal
channels[1][after_id]=89
channels[1][after_event_id]=12
```

* **Resposta de Sucesso (JSON)**:
```json
{
  "channels": {
    "4502:public": {
      "rooms_id": 14,
      "tickets_id": 4502,
      "room_type": "public",
      "messages": [
        {
          "id": 123,
          "actor_type": "user",
          "users_id": 42,
          "participants_id": 20,
          "sender_name": "Técnico Silva",
          "message_type": "message",
          "content": "Olá! Verifiquei a sua solicitação.",
          "documents_id": 0,
          "itilfollowups_id": 3442,
          "date_creation": "2026-06-02 00:20:10"
        }
      ],
      "events": [],
      "last_message_id": 123,
      "last_event_id": 8,
      "participants_id": 20,
      "unread_count": 0
    }
  },
  "server_time": "2026-06-02 00:20:12",
  "_glpi_csrf_token": "def456uvw"
}
```

### 2. Endpoint: `/Guest/Sync` (Convidados Externos)
Os convidados externos (sem login no GLPI) usam um fluxo simplificado baseado em cookie seguro e requisição `GET`.
* **Método**: `GET`
* **Endpoint**: `/plugins/glpichat/Guest/Sync?last_message_id=102&last_event_id=5`
* **Autenticação**: O servidor valida a sessão do convidado pelo cookie `glpichat_guest_session` e localiza o ID da sala correspondente.
* **Resposta de Sucesso (JSON)**:
```json
{
  "messages": [],
  "events": [
    {
      "id": 6,
      "event_type": "invite_revoked",
      "actor_type": "user",
      "users_id": 42,
      "participants_id": 0,
      "payload": {
        "invite_id": 15
      },
      "date_creation": "2026-06-02 00:20:44"
    }
  ]
}
```

---

## ⚡ Otimizações Implementadas

1. **Busca Incremental (Delta)**:
   A consulta SQL de mensagens e eventos utiliza sempre filtros rígidos no ID (`id > ?`), reduzindo o tempo de varredura no banco.
2. **Otimização de Fila Sem Chats Abertos**:
   Se a lista de canais abertos for vazia (comprimento 0), o frontend cancela o envio do `POST` para o endpoint `/Widget/Poll`. Em vez disso, ele faz apenas a sincronização leve de metadados (`syncChannelMetadata`) para garantir contadores de não lidas corretos.
3. **Limite de Canais Concorrentes**:
   O loop de polling no JavaScript restringe a no máximo **20 salas ativas** por ciclo para evitar requisições gigantescas em usuários com dezenas de janelas antigas em aberto.
4. **Renovação de CSRF**:
   Toda resposta de polling/sincronização do widget atualiza o token CSRF (`_glpi_csrf_token`) no estado do JavaScript, prevenindo a expiração de sessão em sessões de longa duração.
