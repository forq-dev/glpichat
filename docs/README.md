# Documentação Técnica do GLPI Chat

Bem-vindo à área de documentação técnica do **GLPI Chat**. Esta pasta contém guias detalhados sobre o funcionamento interno, arquitetura e decisões de projeto do plugin.

Ela é voltada para desenvolvedores, administradores de sistema e equipe técnica que precisam manter, auditar ou estender as capacidades do plugin.

---

## 🗂️ Guias Disponíveis

1. [**Modelagem do Banco de Dados (`database_schema.md`)**](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/docs/database_schema.md)
   * Detalhes de tabelas, campos, chaves primárias e estrangeiras.
   * Relações lógicas e índices de performance criados na inicialização do plugin.

2. [**Mecanismo de Polling (`polling_mechanism.md`)**](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/docs/polling_mechanism.md)
   * Frequências dinâmicas de polling (focado, ocioso, segundo plano).
   * Ciclo de vida e sincronização delta de mensagens e eventos.
   * Endpoints de backend envolvidos na troca de dados.

3. [**Arquitetura do Frontend (`frontend_architecture.md`)**](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/docs/frontend_architecture.md)
   * Gerenciamento de estado em JavaScript.
   * Integração com variáveis CSS do GLPI (`--tblr-*`).
   * Decisões de experiência do usuário (skeletons, colagem Ctrl+V, drag & drop, limites móveis e margens de rodapé).

4. [**Gestão de Permissões (`permissions_technical.md`)**](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/docs/permissions_technical.md)
   * Integração com Perfis nativos do GLPI.
   * Como a sessão global preserva permissões de Helpdesk (interface simplificada).
   * Mapeamento de checagens no código PHP.

---

## 🏗️ Visão Geral da Arquitetura

O plugin baseia-se em um modelo assíncrono cliente-servidor adaptado para a infraestrutura padrão do GLPI (PHP + MariaDB/MySQL).

```
┌─────────────────────────────────────┐    Peticionamento HTTP    ┌────────────────────────────────┐
│            Frontend                 │ ────────────────────────> │        Controllers PHP         │
│  (glpichat-widget.js ou guest.js)   │ <──────────────────────── │  (Widget, Sync, Guest, etc.)   │
│   ├── modules/constants.js          │      Respostas JSON       └────────────────────────────────┘
│   ├── modules/state.js             │
│   ├── modules/dom.js               │
│   ├── modules/sanitize.js          │
│   ├── modules/http.js              │
│   └── modules/format.js            │
└─────────────────────────────────────┘
               │                                                                         │
        Leitura/Escrita                                                           Leitura/Escrita
       no localStorage e                                                                no
          visibilidade                                                            Banco de Dados
               ▼                                                                         ▼
┌────────────────────────────────┐                                       ┌────────────────────────────────┐
│      Navegador (Cliente)       │                                       │        Tabelas GLPI Chat       │
│      - Estado local (Map)      │                                       │        - Mensagens / Eventos   │
│      - Controle de visores     │                                       │        - Participantes / Audit │
└────────────────────────────────┘                                       └────────────────────────────────┘
```
