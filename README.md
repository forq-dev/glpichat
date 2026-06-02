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
  * 🟢 Bolinha verde: mensagens não lidas no canal público (`Solicitante`).
  * 🟣 Bolinha roxa: mensagens não lidas no canal privado (`Discussão técnica`).
* **Gestão de Convidados**:
  * Geração de links seguros com expiração automática para convidar terceiros externos a participarem do chat público.
* **Histórico e Auditoria**:
  * Todas as mensagens, uploads e ações administrativas são salvas e auditadas em banco.

---

## 📋 Requisitos Mínimos

* **GLPI**: versão `11.0.7` ou superior.
* **PHP**: versão `8.2` ou superior.
* **Banco de Dados**: MySQL ou MariaDB compatível com GLPI.

---

## ⚙️ Instalação Rápida

1. Extraia/Clone os arquivos do plugin dentro da pasta de plugins do seu GLPI:
   ```bash
   glpi/plugins/glpichat/
   ```
2. Acesse o painel administrativo do GLPI: **Administração > Plugins**.
3. Localize o plugin **GLPI Chat** na lista, clique em **Instalar** e depois em **Ativar**.

---

## 🔑 Gestão de Permissões (Profile Rights)

O GLPI Chat se integra ao sistema nativo de Perfis do GLPI. A configuração é feita em **Administração > Perfis**, sob a aba **GLPI Chat**. 

Para configurar o acesso dos usuários, marque ou desmarque as opções de acordo com as colunas **Read** (Leitura), **Update** (Atualização) e **Write** (Escrita/Gravação) na matriz exibida no perfil.

### 🔍 Explicação das Opções e Colunas do Profile

1. **Ler chats** (Mapeia para `plugin_glpichat_read`)
   * **Read**: Habilita a visualização do widget de chat flutuante no rodapé do GLPI e dá acesso de leitura ao histórico de conversas do canal público (`Solicitante`).
   * *Nota*: O usuário só consegue visualizar conversas de chamados nos quais ele já possui acesso nativo de leitura no GLPI.
2. **Enviar no chat publico** (Mapeia para `plugin_glpichat_send_public`)
   * **Write**: Habilita a caixa de digitação de texto e envio de arquivos/anexos na sala pública (`Solicitante`). Permite a interação com a outra parte.
3. **Enviar na discussao interna** (Mapeia para `plugin_glpichat_send_internal`)
   * **Write**: Exibe a aba secundária **Discussão técnica** no chat e permite ler e enviar mensagens internas de apoio da equipe técnica.
4. **Convidar terceiros** (Mapeia para `plugin_glpichat_invite`)
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
| **Ler chats** | **Read** | ✅ Ativo | ✅ Ativo | ✅ Ativo | ✅ Ativo |
| **Enviar no chat publico** | **Write** | ✅ Ativo | ✅ Ativo | ✅ Ativo | ✅ Ativo |
| **Enviar na discussao interna** | **Write** | ❌ Inativo | ✅ Ativo | ✅ Ativo | ✅ Ativo |
| **Convidar terceiros** | **Read** | ❌ Inativo | ✅ Ativo | ✅ Ativo | ✅ Ativo |
| | **Update** | ❌ Inativo | ✅ Ativo | ✅ Ativo | ✅ Ativo |
| | **Write** | ❌ Inativo | ✅ Ativo | ✅ Ativo | ✅ Ativo |
| **Ver auditoria** | **Read** | ❌ Inativo | ❌ Inativo | ✅ Ativo | ✅ Ativo |
| **Administrar plugin** | **Read** | ❌ Inativo | ❌ Inativo | ❌ Inativo | ✅ Ativo |
| | **Update** | ❌ Inativo | ❌ Inativo | ❌ Inativo | ✅ Ativo |
| | **Write** | ❌ Inativo | ❌ Inativo | ❌ Inativo | ✅ Ativo |

### 💡 Justificativas das Configurações

* **Solicitante (ex: Self-Service)**: Precisa apenas ler a sala pública (`Read` em *Ler chats*) e enviar suas mensagens (`Write` em *Enviar no chat publico*). O restante deve permanecer inativo para manter o sigilo das conversas técnicas internas e evitar que gere convites indesejados.
* **Técnico (ex: Technician)**: Deve interagir com o solicitante, ter acesso à sala técnica interna (`Discussão técnica`) e gerenciar participantes e links de convites para consultores externos (`Convidar terceiros` completo).
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
