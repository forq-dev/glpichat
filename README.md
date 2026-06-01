# GLPI Chat

Plugin de chat contextual para GLPI 11.0.7+, vinculado a chamados.

O MVP implementa duas salas por chamado: uma sala pública para atendimento com solicitante/convidado e uma sala interna para discussão técnica. As mensagens, eventos, convites e auditoria são persistidos em banco.

## Widget de chat (UX atual)

- O launcher `Chat` fica fixo no rodapé e abre em formato chatbox.
- Cada chamado aberto vira um chatbox próprio ao lado, no estilo barra inferior.
- No bloco superior de notificações, cada chamado exibe:
  - bolinha verde para não lidas do canal `Solicitante`;
  - bolinha roxa para não lidas do canal `Discussão técnica`.
- Abaixo das notificações pendentes, o launcher lista todos os chamados vinculados ao usuário (solicitante, técnico ou observador).
- Esse bloco possui filtros por status:
  - `Novo` (status GLPI `INCOMING` = 1);
  - `Processando` (status GLPI `ASSIGNED` = 2 e `PLANNED` = 3);
  - `Pendente` (status GLPI `WAITING` = 4).

---

---

## Gestão de Permissões (Profile Rights)

O GLPI Chat se integra ao sistema nativo de Perfis do GLPI. A configuração é feita em **Administração > Perfis**, sob a aba **GLPI Chat**. 

Para configurar o acesso dos usuários, marque ou desmarque as opções de acordo com as colunas **Read** (Leitura), **Update** (Atualização) e **Write** (Escrita/Gravação) na matriz exibida no profile.

> [!TIP]
> Se você deseja entender como o sistema de direitos funciona a nível de banco de dados, ciclo de vida da sessão global do GLPI e checagem de código PHP, consulte o documento técnico [docs/permissions_technical.md](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/docs/permissions_technical.md).

---

### 🔍 Explicação das Opções e Colunas do Profile

A matriz de direitos na aba do plugin exibe exatamente as colunas e opções descritas abaixo:

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

---

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

---

### 💡 Justificativas das Configurações

* **Solicitante (ex: Self-Service)**: Precisa apenas ler a sala pública (`Read` em *Ler chats*) e enviar suas mensagens (`Write` em *Enviar no chat publico*). O restante deve permanecer inativo para manter o sigilo das conversas técnicas internas e evitar que gere convites indesejados.
* **Técnico (ex: Technician)**: Deve interagir com o solicitante, ter acesso à sala técnica interna (`Discussão técnica`) e gerenciar participantes e links de convites para consultores externos (`Convidar terceiros` completo).
* **Líder Técnico (ex: Supervisor)**: Além dos acessos operacionais do Técnico, necessita da permissão de leitura de auditoria (`Read` em *Ver auditoria*) para monitorar acessos externos e auditar incidentes de conformidade.
* **Gerente / TI (ex: Admin)**: Acesso completo para configuração do plugin e administração geral da plataforma, com todos os checkboxes ativos.



