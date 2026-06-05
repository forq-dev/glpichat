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

## 🔗 Gestão de Convidados — Guia Completo

### Como funcionam os convites

O técnico acessa a aba **"Gestão de invites"** dentro de um chamado e cria um convite informando **nome** (obrigatório) e **email** (opcional) do convidado. O sistema gera um **link único** com validade de **1 hora**.

Esse link é de **uso único** — após o convidado clicar e aceitar, o mesmo link não pode ser reutilizado.

### Exemplo prático: fluxo completo

1. Técnico abre chamado #42, vai na aba "Gestão de invites", preenche "João" e clica em **"Criar invite"**
2. Sistema retorna o link: `https://glpi.exemplo.com/plugins/glpichat/Accept/a1b2c3d4...`
3. Técnico copia o link e envia para João por email, WhatsApp ou outro meio
4. João clica no link, vê a página do chat com o título **"Chamado #42"** e um contador **"Expira em 59:59"**
5. João digita e envia mensagens — elas aparecem no widget do técnico em tempo real (~2.5s)
6. Se João fechar a aba, a saída é registrada imediatamente como "Convidado saiu do chat"
7. Se o prazo de **1 hora** passar sem atividade, a sessão expira automaticamente
8. Se João tentar usar o **mesmo link** novamente após já ter aceitado, o sistema rejeita — é necessário um novo convite

### O que o convidado vê na tela

- Nome do chamado ("Chamado #42")
- Badge com o nome dele (ex: "João")
- **Contador regressivo**: "Expira em MM:SS" (atualizado a cada 1 segundo)
- Área de mensagens com o histórico do chat público
- Campo de texto e botão de anexo (ativo enquanto a sessão estiver válida)
- Se a sessão expirar ou for revogada: formulário desabilitado e mensagem "Sessão expirada ou revogada. O chat foi encerrado."

### Ações disponíveis para o técnico

| Ação | Botão na tela | Quando usar | O que acontece |
|------|---------------|-------------|----------------|
| **Criar convite** | "Criar invite" | Sempre (se tem permissão) | Gera novo link com validade de 1h |
| **Reemitir** | "Reemitir" | Convite ativo, expirado ou cancelado | Gera **novo link** — o anterior morre |
| **Cancelar convite** | "Cancelar convite" | Convite ainda não foi aceito | Invalida o link permanentemente |
| **Encerrar chat** | "Encerrar chat" | Convidado está em sessão ativa | Desconecta o convidado imediatamente |

### Status dos convites (visíveis na tabela da aba)

| Status | Significado |
|--------|-------------|
| **Ativo** | Link criado, aguardando o convidado usar |
| **Expirado** | Passou 1h, ninguém usou o link |
| **Cancelado** | Técnico revogou antes do uso |
| **Aceito** | Convidado usou o link (mas ainda não entrou na sala) |
| **Em sessão** | Convidado está conectado no chat agora |
| **Sessão encerrada** | Convidado saiu ou foi desconectado pelo técnico |
| **Sessão expirada** | Convidado ficou inativo por mais de 1h |

### ⚠️ Atenção: o link é de uso único

Se o convidado aceitar o convite e depois **perder a sessão** (cookie expirado, trocou de navegador, fechou o modo anônimo), ele **não consegue reutilizar o mesmo link** — o vínculo já foi consumido. O técnico precisa gerar um novo convite ou usar o botão **"Reemitir"** para criar um novo link.

---

## 🔔 Notificações Personalizadas

O plugin registra **6 eventos de notificação** no GLPI. Eles aparecem em **Administração > Notificações** e podem ser customizados como qualquer notificação nativa.

### Eventos disponíveis

| Evento (código interno) | Disparado quando | Para quem |
|------------------------|------------------|-----------|
| `glpichat_public_message` | Alguém envia mensagem no canal **Solicitante** | Técnicos designados, grupos, observadores |
| `glpichat_internal_message` | Alguém envia mensagem no canal **Discussão técnica** | Técnicos designados, grupos, observadores |
| `glpichat_guest_message` | Um **convidado externo** envia mensagem | Técnicos designados, grupos, observadores |
| `glpichat_guest_joined` | Um convidado aceita o convite e entra no chat | Técnicos designados, grupos, observadores |
| `glpichat_guest_left` | Um convidado sai do chat (fecha a aba) | Técnicos designados, grupos, observadores |
| `glpichat_guest_left_timeout` | Sessão do convidado expira por inatividade | Técnicos designados, grupos, observadores |

### Como configurar

1. Acesse **Administração > Notificações** no GLPI
2. Filtre por "GLPI CHAT" na lista de notificações
3. Edite cada notificação como desejar — pode alterar o modelo de email, adicionar destinatários, ativar/desativar eventos específicos

### Tags disponíveis para o modelo de notificação

Use estas tags no assunto ou corpo do email/notificação:

| Tag | O que retorna | Exemplo |
|-----|---------------|---------|
| `##glpichat.ticket_label##` | Nome e ID do chamado | `Helpdesk Ticket #42` |
| `##glpichat.sender_name##` | Nome de quem enviou a mensagem | `João Silva` / `Convidado #7` / `Sistema` |
| `##glpichat.message_preview##` | Preview da mensagem (máx. 180 caracteres) ou texto do evento | `Estou com problema no sistema...` / `Convidado João entrou no chat.` |
| `##glpichat.unread_count##` | Total de mensagens não lidas (só aparece se >= 2) | `3` |
| `##glpichat.unread_badge##` | Badge formatado com total de não lidas | `(3)` |

### Exemplo de notificação recebida

```
Assunto: Helpdesk Ticket #42
Corpo: João Silva: Estou com problema no sistema (2)
```

### Modos de entrega

Cada notificação é entregue em **2 modos** simultaneamente:
- **Email**: enviado pelo sistema de notificações padrão do GLPI
- **AJAX**: notificação toast no navegador (enquanto o usuário estiver com o GLPI aberto)

---

## 📜 Histórico e Auditoria do Chat

### Linha do Tempo (Timeline)

O feed principal do chat exibe uma **linha do tempo unificada** com todos os acontecimentos do chamado:

- **Mensagens** enviadas por técnicos e convidados (com indicador visual de autoria)
- **Anexos** (imagens com preview, documentos com link para download)
- **Eventos de sistema**:
  - Mudanças de **status** do chamado (ex: "Status alterado de Atribuído para Pendente")
  - Mudanças de **prioridade**, **urgência**, **impacto**
  - **Atribuições** de técnicos e grupos
  - **Acompanhamentos** adicionados por outras origens fora do chat
  - **Entrada e saída** de participantes (técnicos e convidados)

O histórico carrega automaticamente mensagens mais antigas conforme o usuário rola a tela para cima (**paginação infinita**, 50 mensagens por vez).

### Auditoria (aba no chamado)

A aba **"Auditoria do Chat"** (visível no chamado para perfis com permissão `plugin_glpichat_view_audit`) exibe uma tabela com:

| Coluna | Descrição |
|--------|-----------|
| **Data/Hora** | Momento exato do evento |
| **Ação** | O que foi feito (ex: "Mensagem Enviada", "Convite Criado") |
| **Ator** | Quem fez (técnico, convidado, sistema) |
| **Detalhes** | Informações complementares |

### O que é registrado na auditoria

| Ação registrada | Quando acontece | Detalhes |
|-----------------|-----------------|----------|
| Mensagem Enviada | Técnico ou convidado envia mensagem | Sala (público/interno) e conteúdo |
| Convite Criado | Técnico gera novo link | Nome do convidado |
| Convite Cancelado | Técnico revoga link antes do uso | ID do convite |
| Convite Reemitido | Técnico gera novo link para substituir | Nova data de expiração |
| Convidado Entrou | Convidado aceita o link e acessa o chat | Nome do convidado |
| Convidado Saiu | Convidado fecha a aba ou é desconectado | Nome do convidado |
| Sessão Expirada | Sessão do convidado expira por inatividade | Nome do convidado |

> **Importante**: O log de auditoria é **permanente** — não é limpo automaticamente. Já os eventos da timeline (como mudanças de status) são apagados após **30 dias** por uma tarefa automática do cron.

---

## 🔑 Gestão de Permissões (Profile Rights)

O GLPI Chat se integra ao sistema nativo de Perfis do GLPI. A configuração é feita em **Administração > Perfis**, sob a aba **GLPI Chat**. 

Para configurar o acesso dos usuários, marque ou desmarque as opções de acordo com as colunas **Read** (Leitura), **Update** (Atualização) e **Write** (Escrita/Gravação) na matriz exibida no perfil.

### 🔍 Explicação das Opções e Colunas do Profile

1. **Canal publico** (Mapeia para `plugin_glpichat_public`)
   * **Read**: Habilita a visualização do widget de chat flutuante no rodapé do GLPI e dá acesso de leitura ao histórico de conversas do canal público (`Solicitante`).
   * **Write** (CREATE): Habilita a caixa de digitação de texto e envio de arquivos/anexos na sala pública (`Solicitante`). Permite a interação com a outra parte.
2. **Canal interno** (Mapeia para `plugin_glpichat_internal`)
   * **Read**: Exibe a aba secundária **Discussão técnica** no chat e permite ler mensagens internas de apoio da equipe técnica.
   * **Write** (CREATE): Permite enviar mensagens na discussão técnica interna.
3. **Convidar terceiros** (Mapeia para `plugin_glpichat_invite`)
   * **Read**: Permite abrir a aba **Participantes** para ver quem está ativo no chat do chamado.
   * **Write**: Habilita o formulário para gerar e enviar links de convites para convidados externos.
   * **Update**: Permite revogar ou reemitir links de convite gerados.
5. **Ver auditoria** (Mapeia para `plugin_glpichat_view_audit`)
   * **Read**: Exibe a aba **Auditoria** no widget do chamado com o histórico de eventos de segurança.
6. **Administrar plugin** (Mapeia para `plugin_glpichat_admin`)
   * **Read**: Permite visualizar as configurações do plugin na interface administrativa geral.
   * **Update / Write**: Permite editar e salvar alterações nas configurações globais do plugin.

### 📋 Matriz de Configuração Recomendada por Perfil

Configure a aba **GLPI Chat** nos perfis do seu GLPI marcando exatamente os checkboxes conforme indicado na tabela abaixo:

| Opção no Profile | Coluna | Solicitante (Self-Service) | Técnico (Technician) | Líder Técnico (Supervisor) | Gerente / TI (Admin) |
| :--- | :---: | :---: | :---: | :---: | :---: |
| **Canal publico** | **Read** | ✅ Ativo | ✅ Ativo | ✅ Ativo | ✅ Ativo |
| | **Write** | ✅ Ativo | ✅ Ativo | ✅ Ativo | ✅ Ativo |
| **Canal interno** | **Read** | ❌ Inativo | ✅ Ativo | ✅ Ativo | ✅ Ativo |
| | **Write** | ❌ Inativo | ✅ Ativo | ✅ Ativo | ✅ Ativo |
| **Convidar terceiros** | **Read** | ❌ Inativo | ✅ Ativo | ✅ Ativo | ✅ Ativo |
| | **Update** | ❌ Inativo | ✅ Ativo | ✅ Ativo | ✅ Ativo |
| | **Write** | ❌ Inativo | ✅ Ativo | ✅ Ativo | ✅ Ativo |
| **Ver auditoria** | **Read** | ❌ Inativo | ❌ Inativo | ✅ Ativo | ✅ Ativo |
| **Administrar plugin** | **Read** | ❌ Inativo | ❌ Inativo | ❌ Inativo | ✅ Ativo |
| | **Update** | ❌ Inativo | ❌ Inativo | ❌ Inativo | ✅ Ativo |
| | **Write** | ❌ Inativo | ❌ Inativo | ❌ Inativo | ✅ Ativo |

### 💡 Justificativas das Configurações

* **Solicitante (ex: Self-Service)**: Precisa apenas do canal público (`Read` + `Write` em *Canal publico*). O restante deve permanecer inativo para manter o sigilo das conversas técnicas internas e evitar que gere convites indesejados.
* **Técnico (ex: Technician)**: Deve interagir com o solicitante, ter acesso ao canal interno (`Canal interno`), e gerenciar participantes e links de convites para consultores externos (`Convidar terceiros` completo).
* **Líder Técnico (ex: Supervisor)**: Além dos acessos operacionais do Técnico, necessita da permissão de leitura de auditoria (`Read` em *Ver auditoria*) para monitorar acessos externos e auditar incidentes de conformidade.
* **Gerente / TI (ex: Admin)**: Acesso completo para configuração do plugin e administração geral da plataforma, com todos os checkboxes ativos.

---

## 🛠️ Documentação Técnica Detalhada

Para desenvolvedores, administradores de infraestrutura e técnicos que precisam entender ou alterar o código interno do plugin, consulte os guias avançados localizados na pasta `docs/`:

* 📖 [**Índice de Guias Técnicos (`docs/README.md`)**](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/docs/README.md)
* 💾 [**Modelagem do Banco de Dados (`docs/database_schema.md`)**](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/docs/database_schema.md)
* 🔄 [**Mecanismo de Polling e Sincronização (`docs/polling_mechanism.md`)**](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/docs/polling_mechanism.md)
* 🎨 [**Arquitetura do Frontend e UX (`docs/frontend_architecture.md`)**](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/docs/frontend_architecture.md)
* 🔑 [**Implementação de Segurança e Permissões (`docs/permissions_technical.md`)**](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/docs/permissions_technical.md)
