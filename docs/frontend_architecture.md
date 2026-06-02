# Arquitetura do Frontend — GLPI Chat

Este documento detalha o funcionamento técnico do frontend do **GLPI Chat** (implementado no arquivo [glpichat.js](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/public/js/glpichat.js) e [glpichat.css](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/public/css/glpichat.css)), cobrindo estrutura, gerenciamento de estado, experiência do usuário (UX), responsividade e decisões de design.

---

## 🏗️ Estrutura de Componentes da Interface

O widget cliente é dinamicamente injetado no final do `<body>` do GLPI pela função `createShell()`. A estrutura DOM principal é organizada da seguinte maneira:

```
div.glpichat-widget
├── div.glpichat-dock [data-role="dock"]
│   ├── div.glpichat-chatbox (uma por chamado aberto)
│   │   ├── div.glpichat-chatbox-panel
│   │   │   ├── div.glpichat-dropzone-overlay (drag-and-drop)
│   │   │   ├── div.glpichat-window-head (título e botão fechar)
│   │   │   ├── div.glpichat-tabs (abas: Solicitante / Discussão técnica)
│   │   │   ├── div.glpichat-messages (lista de mensagens e skeletons)
│   │   │   └── form.glpichat-send (campo de digitação e upload de arquivos)
│   │   └── button.glpichat-chatbox-tab (aba minimizada no rodapé)
│   │
│   └── div.glpichat-chatbox.glpichat-launcher [data-role="launcher"]
│       ├── div.glpichat-chatbox-panel [data-role="launcher-panel"] (lista de chamados e filtros)
│       └── button.glpichat-chatbox-tab [data-role="launcher-tab"] (ícone flutuante de abertura)
│
└── div.glpichat-lightbox [data-role="lightbox"]
    └── img.glpichat-lightbox-img (visualizador de imagens em tela cheia)
```

---

## 💾 Gerenciamento de Estado no Cliente

O arquivo `glpichat.js` utiliza uma arquitetura de estado centralizada e reativa por meio do objeto global `state`.

* **Estrutura do Objeto `state`**:
  * `csrf`: Token de segurança CSRF ativo no GLPI (atualizado a cada resposta).
  * `currentUserId`: ID do usuário logado no GLPI.
  * `activeTicketId`: ID do chamado atualmente visualizado na página do GLPI.
  * `channels`: Um `Map` que armazena os metadados de cada sala de chat (ex: mensagens carregadas, ID da última mensagem, total de não lidas, estados de carregamento).
  * `tickets`: Um `Map` contendo a lista de chamados conhecidos.
  * `openTicketIds`: Lista dos IDs dos chamados cujas caixas de chat estão abertas no rodapé.
  * `closedTicketIds`: Conjunto (`Set`) de chamados fechados pelo usuário.
  * `manualTicketIds`: Conjunto de chamados que o usuário abriu explicitamente via clique.
  * `transientTicketIds`: Chamados abertos de forma transitória (ex: chamados carregados automaticamente pela página atual).
  * `pollTimer` / `metaTimer`: Ponteiros para os loops de polling de dados.
  * `launcherOpen`: Booleano indicando se o drawer geral de chamados está expandido.

---

## 🎨 Integração Visual com o GLPI (Tabler CSS)

Para manter a unidade de design sem inflar o carregamento de CSS, o plugin **não cria paletas de cores próprias**. Ele herda as variáveis de estilo do framework **Tabler CSS** nativo do GLPI 11:

* **Superfícies e Bordas**: Utiliza `--tblr-bg-surface`, `--tblr-border-color` e `--tblr-border-color-translucent`. Isso faz com que o widget se adapte nativamente se o usuário alterar o tema do GLPI (Modo Escuro / Modo Claro).
* **Tipografia**: Respeita as famílias de fontes e tamanhos operacionais do sistema para que a leitura seja integrada.
* **Mensagens**: A classe `.glpichat-message` usa `white-space: pre-wrap` para preservar quebras de linha e espaçamentos manuais inseridos nos textos, o que é fundamental para trechos de logs ou códigos colados pela equipe de TI.

---

## 📐 Cálculo Dinâmico de Margem de Rodapé (`safe-bottom`)

Um problema comum em widgets flutuantes é a sobreposição de elementos fixados por outros plugins (ex: barra de cookies, rodapés de marca, assistentes virtuais). Para contornar isso, o frontend implementa o algoritmo `detectBottomObstruction()`:

1. Executa uma varredura em todos os elementos HTML visíveis na página.
2. Identifica elementos com posicionamento `fixed` ou `sticky` que interceptam a parte inferior do viewport (largura mínima de 220px e limite de alinhamento à esquerda).
3. Calcula a altura de obstrução.
4. Define a propriedade CSS customizada `--glpichat-safe-bottom` no elemento raiz `:root` somando um espaçamento padrão de `8px`.
5. O CSS do widget aplica a margem de rodapé de forma responsiva:
   ```css
   .glpichat-dock {
     bottom: var(--glpichat-safe-bottom, 8px);
   }
   ```

---

## 💡 Recursos de UX e Micro-interações

### 1. Atualizações Otimistas (Optimistic Updates)
Quando o usuário envia uma mensagem de texto ou inicia o upload de um arquivo, o widget injeta a mensagem imediatamente na tela com a marcação temporária "Enviando..." e com opacidade reduzida (`is-optimistic`), antes mesmo da resposta do servidor PHP.
* **Vantagem**: Elimina a sensação de latência de rede.
* **Tratamento de Falha**: Se a requisição falhar (ex: queda de conexão), o elemento otimista é removido da lista e o conteúdo é devolvido para a caixa de digitação.

### 2. Esqueletos de Carregamento (Skeleton Loaders)
Ao alternar entre salas ou carregar o histórico anterior, o chat exibe animações pulsantes no formato dos balões de mensagens em vez de um spinner tradicional. Isso reduz a carga cognitiva e melhora a percepção de performance.

### 3. Integração com Área de Transferência (Clipboard Paste & Drag-and-Drop)
* O usuário pode arrastar qualquer arquivo do computador e soltá-lo sobre o chatbox para iniciar o upload automaticamente (exibindo a camada `.glpichat-dropzone-overlay`).
* O campo de digitação escuta eventos de colagem (`paste`). Se o usuário copiar uma imagem (ex: capturada via ferramenta de screenshot) e pressionar `Ctrl+V` dentro do textarea, o arquivo é capturado da área de transferência e o upload é disparado imediatamente.

### 4. Animação de Entrada de Novas Mensagens
As mensagens recebidas em segundo plano pelo polling entram na tela utilizando uma micro-animação CSS de surgimento (`slide-in` e `fade-in`) controlada pela classe `.is-new`.

---

## 📱 Responsividade e Limites de Tela

* **Limites de Caixas Abertas**:
  * **Desktop**: Máximo de **3 chats** abertos lado a lado no rodapé.
  * **Mobile (largura de tela <= 980px)**: Máximo de **1 chat** aberto. A UI se adapta ocultando chats excedentes para não cobrir a tela sensível ao toque.
* **Persistência de Sessão**:
  O estado do widget (como quais chamados estão abertos de forma persistente) é salvo localmente no navegador via `localStorage` na chave `glpichat_widget_ui`. Isso garante que se o técnico recarregar a página ou navegar entre chamados no painel do GLPI, as caixas de conversa que ele estava ativamente monitorando permaneçam abertas na mesma posição.
