# GLPI Chat

Plugin de chat contextual em tempo real para o **GLPI 11.0.7+**, vinculado diretamente a chamados.

O **GLPI Chat** permite a comunicação ágil e integrada entre técnicos, solicitantes e convidados sem a necessidade de ferramentas externas de comunicação (como Slack ou Teams). Ele organiza a conversação em duas salas independentes por chamado: uma sala pública (`Solicitante`) e uma sala interna (`Discussão técnica`).

---

## 🚀 Funcionalidades Principais

* **Widget de Chat no Rodapé (UX Dinâmica)**:
  * O launcher geral fica fixo no rodapé e abre a lista de conversas ativas.
  * Cada chamado selecionado abre um chatbox individual ao lado da barra inferior.
  * Filtros rápidos por status de chamados: `Novo`, `Processando` e `Pendente`.
* **Notificações Visuais de Mensagens Pendentes**:
  * Badge numérico no ícone do widget: total de mensagens não lidas de todos os chamados.
  * Dentro de cada chamado, abas **Solicitante** e **Discussão técnica** com badges individuais de não lidas.
  * As cores dos badges acompanham o tema visual ativo do GLPI (claro/escuro) — não há cores fixas.
* **🔗 Gestão de Convidados (Invites)**:
  * Criação de **links seguros de uso único** com validade de 1 hora para convidar terceiros externos.
  * Aba "Gestão de invites" no chamado com ações de criar, reemitir, cancelar e desconectar convidados.
  * Status visual de cada convite: ativo, expirado, cancelado, aceito, em sessão, sessão encerrada.
  * O convidado acessa o chat público via link, vê contador regressivo de expiração e pode enviar mensagens e anexos.
* **Histórico e Auditoria**:
  * **Histórico**: timeline unificada com mensagens + eventos do chamado (mudanças de status, prioridade, atribuições, entrada/saída de participantes). Paginação automática ao rolar para cima.
  * **Auditoria**: aba "Auditoria do Chat" no chamado com registro permanente de mensagens, convites criados/cancelados/reemitidos, entrada/saída de convidados e expirações de sessão.

---

## 📋 Requisitos Mínimos

* **GLPI**: versão `11.0.7` ou superior.
* **PHP**: versão `8.2` ou superior.
* **Banco de Dados**: MySQL ou MariaDB compatível com GLPI.

---

## ⚙️ Instalação Rápida

1. Extraia/Clone os arquivos do plugin dentro da pasta de plugins do seu GLPI:
   ```bash
   plugins/glpichat/
   ```
2. Acesse o painel administrativo do GLPI: **Administração > Plugins**.
3. Localize o plugin **GLPI Chat** na lista, clique em **Instalar** e depois em **Ativar**.

---

## ⚠️ Configuração Obrigatória Pós-Instalação

Após ativar o plugin, acesse **Configuração > Plugins > GLPI Chat** e preencha o campo:

**Usuário associado a mensagens de convidados externos**

Esse campo define qual usuário do GLPI será registrado como autor dos followups criados no ticket quando um convidado externo envia uma mensagem. **Sem essa configuração, o fluxo de convites não funcionará** — o convidado verá uma mensagem de erro ao tentar acessar o link do convite.

> **Recomendação:** crie um usuário dedicado (ex: `Chat Bot` ou `Suporte Externo`) e selecione-o nesse campo. Esse usuário não precisa ter acesso ao GLPI — ele serve apenas como autor técnico dos registros.

---

## 🔗 Gestão de Convidados
> 📖 [Documentação técnica completa](docs/features/invites.md)

Permite convidar terceiros externos para o chat público de um chamado via link seguro de uso único com validade de 1 hora — sem necessidade de conta no GLPI.

### Como funciona

O técnico acessa a aba **"Gestão de invites"** dentro de um chamado e cria um convite informando **nome** (obrigatório) e **email** (opcional) do convidado. O sistema gera um **link único** que deve ser enviado manualmente ao convidado por qualquer meio externo.

O convidado clica no link, vê a página do chat com o nome do chamado e um contador regressivo de expiração. Pode enviar mensagens e anexos enquanto a sessão estiver válida.

### Configuração de Permissões

**Convidar terceiros** (`plugin_glpichat_invite`) — o que cada coluna libera:
- **Read**: visualizar a aba "Gestão de invites" e os convites existentes
- **Write**: criar novos links de convite
- **Update**: reemitir links existentes e encerrar sessões ativas de convidados

**Matriz recomendada por perfil:**

| Opção | Coluna | Solicitante | Técnico | Líder Técnico | Gerente/TI |
|-------|--------|:-----------:|:-------:|:-------------:|:----------:|
| Convidar terceiros | Read | ❌ | ✅ | ✅ | ✅ |
| | Write | ❌ | ✅ | ✅ | ✅ |
| | Update | ❌ | ✅ | ✅ | ✅ |

→ [Ver documentação técnica completa](docs/features/invites.md)

---

## 🔔 Notificações Personalizadas
> 📖 [Documentação técnica completa](docs/features/notifications.md)

O plugin registra **6 eventos de notificação** no GLPI, configuráveis como qualquer notificação nativa, com entrega simultânea por email e por toast AJAX no navegador.

### Como configurar

1. Acesse **Administração > Notificações** no GLPI
2. Filtre por "GLPI CHAT" na lista de notificações
3. Edite cada notificação como desejar — modelo de email, destinatários, ativar/desativar

### Eventos disponíveis

| Evento | Disparado quando |
|--------|-----------------|
| `glpichat_public_message` | Mensagem enviada no canal Solicitante |
| `glpichat_internal_message` | Mensagem enviada no canal Discussão técnica |
| `glpichat_guest_message` | Convidado externo envia mensagem |
| `glpichat_guest_joined` | Convidado aceita o convite e entra no chat |
| `glpichat_guest_left` | Convidado sai do chat |
| `glpichat_guest_left_timeout` | Sessão do convidado expira por inatividade |

→ [Ver documentação técnica completa](docs/features/notifications.md)

---

## 📜 Histórico e Auditoria do Chat
> 📖 [Documentação técnica completa](docs/features/audit.md)

O chat mantém dois registros distintos: uma **linha do tempo** no widget (com limpeza automática de eventos após 30 dias) e uma **aba de Auditoria** no chamado com log permanente.

### Linha do Tempo (Timeline)

Exibe em ordem cronológica mensagens, anexos e eventos de sistema (mudanças de status, prioridade, atribuições, entrada/saída de participantes). Carrega os últimos 50 itens e pagina automaticamente ao rolar para cima.

### Aba de Auditoria

Visível para perfis com permissão `plugin_glpichat_view_audit` (Read). Exibe tabela com **Data/Hora**, **Ação**, **Ator** e **Detalhes** de todos os eventos de segurança registrados permanentemente.

### Configuração de Permissões

**Ver auditoria** (`plugin_glpichat_view_audit`):
- **Read**: exibe a aba "Auditoria do Chat" no chamado

**Matriz recomendada por perfil:**

| Opção | Coluna | Solicitante | Técnico | Líder Técnico | Gerente/TI |
|-------|--------|:-----------:|:-------:|:-------------:|:----------:|
| Ver auditoria | Read | ❌ | ❌ | ✅ | ✅ |

→ [Ver documentação técnica completa](docs/features/audit.md)

---

## 🔑 Gestão de Permissões (Profile Rights)

O GLPI Chat se integra ao sistema nativo de Perfis do GLPI. A configuração é feita em **Administração > Perfis**, sob a aba **GLPI Chat**.

### Opções e colunas disponíveis

1. **Canal publico** (`plugin_glpichat_public`)
   * **Read**: habilita o widget de chat no rodapé e leitura do canal público (Solicitante)
   * **Write**: habilita o envio de mensagens e anexos no canal público

2. **Canal interno** (`plugin_glpichat_internal`)
   * **Read**: exibe a aba Discussão técnica e permite ler mensagens internas
   * **Write**: permite enviar mensagens no canal interno

3. **Convidar terceiros** (`plugin_glpichat_invite`)
   * **Read**: visualizar a aba de participantes e convites existentes
   * **Write**: criar novos links de convite
   * **Update**: reemitir ou cancelar links e encerrar sessões

4. **Ver auditoria** (`plugin_glpichat_view_audit`)
   * **Read**: exibe a aba de Auditoria no chamado

5. **Administrar plugin** (`plugin_glpichat_admin`)
   * **Read**: visualizar configurações do plugin
   * **Update / Write**: editar e salvar configurações globais

### Matriz de configuração recomendada por perfil

| Opção no Profile | Coluna | Solicitante | Técnico | Líder Técnico | Gerente/TI |
|:-----------------|:------:|:-----------:|:-------:|:-------------:|:----------:|
| Canal publico | Read | ✅ | ✅ | ✅ | ✅ |
| | Write | ✅ | ✅ | ✅ | ✅ |
| Canal interno | Read | ❌ | ✅ | ✅ | ✅ |
| | Write | ❌ | ✅ | ✅ | ✅ |
| Convidar terceiros | Read | ❌ | ✅ | ✅ | ✅ |
| | Update | ❌ | ✅ | ✅ | ✅ |
| | Write | ❌ | ✅ | ✅ | ✅ |
| Ver auditoria | Read | ❌ | ❌ | ✅ | ✅ |
| Administrar plugin | Read | ❌ | ❌ | ❌ | ✅ |
| | Update | ❌ | ❌ | ❌ | ✅ |
| | Write | ❌ | ❌ | ❌ | ✅ |

---

## 🛠️ Documentação Técnica Detalhada

Para desenvolvedores, administradores de infraestrutura e técnicos que precisam entender ou alterar o código interno do plugin, consulte os guias avançados em `docs/`:

* 📖 [**Índice de Guias Técnicos**](docs/README.md)
* 💾 [**Schema de Banco de Dados**](docs/architecture/database/schema.md)
* 🔄 [**Polling e Sincronização**](docs/architecture/flows/polling.md)
* 🎨 [**Arquitetura do Frontend e Widget**](docs/architecture/frontend/widget.md)
* 🔑 [**Sistema de Permissões (Integração GLPI)**](docs/architecture/glpi-integration/permissions.md)
