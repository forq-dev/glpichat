# Frontend — Widget de Chat

> **Audiência:** Desenvolvedor
> **Relacionado:** [Índice de Documentação Técnica](../../README.md)

## O que é

Implementação do widget de chat flutuante do GLPI Chat, construído em JavaScript ES6 modular (sem framework), injetado dinamicamente no `<body>` do GLPI e integrado visualmente às variáveis CSS nativas do Tabler.

## Estrutura

### Arquivos principais

| Arquivo | Responsabilidade |
|---------|-----------------|
| `public/js/glpichat-widget.js` | Bundle para usuários autenticados — polling, múltiplos chatboxes, dock |
| `public/js/glpichat-guest.js` | Bundle para convidados externos — fluxo simplificado, canal único |
| `public/css/glpichat.css` | Estilos do widget — usa variáveis CSS do Tabler |

### Módulos ES6 compartilhados (`src/js/modules/`)

| Módulo | Responsabilidade |
|--------|-----------------|
| `constants.js` | Constantes de tempo (intervalos de polling), limites de canais, chaves de localStorage |
| `state.js` | Estado global centralizado (`Map` de canais, `Set` de tickets fechados, CSRF, IDs ativos) |
| `dom.js` | Criação e manipulação do DOM do widget — `createShell()`, `createChatbox()`, `renderMessage()` |
| `sanitize.js` | Sanitização de conteúdo exibido no DOM — usa `textContent` em vez de `innerHTML` onde possível |
| `http.js` | Wrappers para `fetch` com renovação de CSRF e tratamento de erros de rede |
| `format.js` | Formatação de datas, nomes, previews de mensagem e badges de contagem |

### Estrutura DOM do widget

```
div.glpichat-widget
├── div.glpichat-dock [data-role="dock"]
│   ├── div.glpichat-chatbox (uma por chamado aberto)
│   │   ├── div.glpichat-chatbox-panel
│   │   │   ├── div.glpichat-dropzone-overlay
│   │   │   ├── div.glpichat-window-head
│   │   │   ├── div.glpichat-tabs
│   │   │   ├── div.glpichat-messages
│   │   │   └── form.glpichat-send
│   │   └── button.glpichat-chatbox-tab
│   └── div.glpichat-chatbox.glpichat-launcher [data-role="launcher"]
│       ├── div.glpichat-chatbox-panel [data-role="launcher-panel"]
│       └── button.glpichat-chatbox-tab [data-role="launcher-tab"]
└── div.glpichat-lightbox [data-role="lightbox"]
    └── img.glpichat-lightbox-img
```

### Estado centralizado (`state.js`)

| Campo | Tipo | O que armazena |
|-------|------|---------------|
| `csrf` | `string` | Token CSRF ativo — atualizado a cada resposta de polling |
| `currentUserId` | `number` | ID do usuário logado no GLPI |
| `activeTicketId` | `number` | ID do chamado aberto na página atual do GLPI |
| `channels` | `Map` | Metadados por sala: mensagens carregadas, last_id, unread_count, estados de loading |
| `tickets` | `Map` | Lista de chamados conhecidos pelo widget |
| `openTicketIds` | `Array` | IDs dos chatboxes abertos no dock |
| `closedTicketIds` | `Set` | Chamados fechados manualmente pelo usuário nesta sessão |
| `manualTicketIds` | `Set` | Chamados abertos via clique explícito do usuário |
| `transientTicketIds` | `Set` | Chamados abertos automaticamente pela página atual |
| `pollTimer` | `number` | Timer ID do loop de polling de mensagens |
| `metaTimer` | `number` | Timer ID do loop de sincronização de metadados |
| `launcherOpen` | `boolean` | Se o drawer de lista de chamados está expandido |

## Comunicação com o backend

O protocolo de polling está documentado em detalhes em [flows/polling.md](../flows/polling.md). Em resumo:

- `GET /Widget/Channels` — metadados da lista de conversas (a cada 15s)
- `POST /Widget/Poll` — delta de mensagens e eventos dos chatboxes abertos (2,5s / 9s / 18s)
- `POST /Widget/Leave` — saída voluntária de uma sala
- `GET /Guest/Sync` — polling simplificado para convidados

Todas as respostas retornam um novo `_glpi_csrf_token` que o JS armazena em `state.csrf`. O módulo `http.js` injeta o CSRF em cada requisição POST automaticamente.

Erros de rede são tratados com backoff silencioso — o widget não exibe modal de erro em falhas de polling intermitentes. Apenas falhas de envio de mensagem (POST para send) geram feedback visual ao usuário.

### Otimistic updates

Ao enviar uma mensagem, o widget injeta o elemento na tela imediatamente com classe `is-optimistic` e opacidade reduzida antes da resposta do servidor. Se a requisição falhar, o elemento é removido e o conteúdo devolvido ao textarea.

### Persistência via localStorage

O estado de quais chatboxes estão abertos é salvo em `localStorage` na chave `glpichat_widget_ui`. Ao recarregar a página, o widget restaura os chatboxes que estavam abertos como `manualTicketIds`.

## Restrições do GLPI

- **Não usar cores hardcodadas.** Usar exclusivamente variáveis CSS do Tabler: `--tblr-bg-surface`, `--tblr-border-color`, `--tblr-border-color-translucent`, `--tblr-body-color`. O widget deve mudar de tema (Claro/Escuro) automaticamente.
- **Não importar Bootstrap, jQuery ou Tabler CSS.** O GLPI 11.x já carrega essas bibliotecas globalmente. Duplicar causa conflitos de versão e aumenta o tempo de carregamento.
- **Não usar `innerHTML` com dados externos.** O módulo `sanitize.js` existe exatamente para isso — usar `textContent` ou a função de sanitização antes de qualquer inserção de conteúdo de mensagem no DOM.
- **Não usar `document.cookie` no JavaScript do widget.** Cookies de sessão do convidado têm flag `HttpOnly` e não são acessíveis via JS — o backend gerencia a sessão via cookie.

### Cálculo de margem de rodapé (`safe-bottom`)

O algoritmo `detectBottomObstruction()` escaneia elementos `fixed`/`sticky` na parte inferior do viewport (largura mínima 220px) e define `--glpichat-safe-bottom` no `:root`. O CSS do dock usa essa variável como `bottom`. Isso evita sobreposição com barras de outros plugins.

### Responsividade

- **Desktop:** máximo de 3 chatboxes simultâneos no dock.
- **Mobile (≤ 980px):** máximo de 1 chatbox. Chatboxes excedentes são ocultados sem serem fechados.

## Riscos e pontos de atenção

**localStorage pode ser limpo pelo usuário.** Se o usuário limpar dados do navegador, `glpichat_widget_ui` é perdido e os chatboxes não são restaurados ao recarregar. O widget abre vazio — comportamento esperado, não um bug.

**Limite de 3 chatboxes não é enforçado no backend.** O limite é apenas visual e de estado no cliente. O backend aceita polling para qualquer número de canais (até o máximo de 20 por requisição). Um usuário com JS modificado pode abrir mais de 3.

**Mobile: o chatbox único oculta os demais, não os fecha.** Os chatboxes excedentes permanecem em `openTicketIds` — apenas são ocultados via CSS. Se o usuário girar o dispositivo para landscape e a largura ultrapassar 980px, os chatboxes ocultos reaparecem.

**Animações CSS dependem da classe `.is-new`.** Se o polling retornar mensagens antigas (ex: após reconexão com `after_id` muito baixo), todas elas receberão `.is-new` e dispararão a animação de entrada. Isso pode acontecer se o estado local for perdido (ex: `localStorage` limpo entre sessões).

**Skeleton loaders não têm timeout.** Se o backend não responder à primeira carga de histórico, o skeleton permanece visível indefinidamente. Não há fallback de erro para a carga inicial de mensagens.
