# Histórico e Auditoria — Documentação Técnica

> **Audiência:** Administrador técnico
> **Relacionado:** [Seção no README principal](../../README.md#-histórico-e-auditoria-do-chat)

## O que faz

Registra e exibe dois tipos de histórico distintos: uma linha do tempo unificada no widget de chat (timeline, com limpeza automática de eventos após 30 dias) e um log de auditoria permanente em aba dedicada no chamado (sem limpeza automática).

## Fluxo completo

### Timeline (linha do tempo no widget)

A timeline é o feed principal de mensagens do chat. Ela exibe em ordem cronológica:

- Mensagens enviadas por técnicos e convidados
- Eventos de sistema: mudanças de status, prioridade, urgência, impacto, atribuições, acompanhamentos adicionados fora do chat, entrada e saída de participantes

Ao abrir um chatbox, o widget carrega os últimos 50 itens. Conforme o usuário rola para cima, novos lotes de 50 itens são carregados automaticamente (paginação infinita via `after_id` decrescente).

Eventos de sistema mais antigos que 30 dias são removidos automaticamente por uma CronTask do plugin. **Mensagens nunca são apagadas pela CronTask** — apenas eventos de sistema (mudanças de campo, entradas/saídas).

### Aba de Auditoria (log permanente)

A aba **"Auditoria do Chat"** aparece no chamado para usuários com permissão `plugin_glpichat_view_audit` (Read). Ela exibe uma tabela com todos os eventos de auditoria registrados na tabela `glpi_plugin_glpichat_auditlogs`, que **nunca é limpa automaticamente**.

## Permissões — detalhamento

### `plugin_glpichat_view_audit`

| Coluna | O que libera | Contexto |
|--------|--------------|---------|
| Read | Exibe a aba "Auditoria do Chat" no chamado com o log completo de eventos | Única coluna com efeito neste direito |

**Justificativa por perfil:**
- **Solicitante (Self-Service)**: Sem acesso — a auditoria é uma ferramenta de supervisão, não de operação.
- **Técnico (Technician)**: Sem acesso por padrão — o técnico não precisa auditar acessos externos no dia a dia.
- **Líder Técnico (Supervisor)**: Read ativo — precisa monitorar acessos de convidados e auditar incidentes de conformidade.
- **Administrador**: Read ativo — acesso completo para administração e diagnóstico.

## Tabelas de referência

### Colunas da aba de Auditoria

| Coluna | Descrição |
|--------|-----------|
| Data/Hora | Momento exato do evento |
| Ação | O que foi feito (ex: "Mensagem Enviada", "Convite Criado") |
| Ator | Quem fez (técnico, convidado, sistema) |
| Detalhes | Informações complementares do evento |

### Ações registradas na auditoria

| Ação registrada | Quando acontece | Detalhes incluídos |
|-----------------|-----------------|-------------------|
| Mensagem Enviada | Técnico ou convidado envia mensagem | Sala (público/interno) e conteúdo |
| Convite Criado | Técnico gera novo link de convite | Nome do convidado |
| Convite Cancelado | Técnico revoga link antes do uso | ID do convite |
| Convite Reemitido | Técnico gera novo link para substituir existente | Nova data de expiração |
| Convidado Entrou | Convidado aceita o link e acessa o chat | Nome do convidado |
| Convidado Saiu | Convidado fecha a aba ou é desconectado | Nome do convidado |
| Sessão Expirada | Sessão do convidado expira por inatividade | Nome do convidado |

## Comportamentos não óbvios

### Log de auditoria é permanente; eventos da timeline não são

O `glpi_plugin_glpichat_auditlogs` **nunca é limpo automaticamente** — cresce indefinidamente. Já os eventos de sistema na timeline (`glpi_plugin_glpichat_events`) são apagados após 30 dias pela CronTask do plugin. Isso significa que um evento de "Convidado Entrou" visível na timeline pode desaparecer após 30 dias, mas o mesmo evento na aba de Auditoria permanece para sempre.

### A timeline mostra eventos de fora do chat

Mudanças de status, prioridade e atribuições feitas diretamente no formulário do chamado (fora do widget de chat) também aparecem na timeline do chat. Isso dá ao convidado externo visibilidade sobre o andamento do chamado sem que o técnico precise comunicar manualmente.

### A paginação não carrega eventos futuros, apenas passados

A paginação infinita carrega mensagens e eventos **mais antigos** conforme o usuário rola para cima. Novos eventos chegam pelo mecanismo de polling normal — não pelo scroll.

## Limitações conhecidas

- O log de auditoria não tem paginação na aba — exibe todos os registros de uma vez. Em chamados com histórico muito extenso, a aba pode demorar para carregar.
- Não é possível exportar o log de auditoria diretamente pela interface — é necessário consultar a tabela `glpi_plugin_glpichat_auditlogs` no banco de dados.
- Eventos de sistema mais antigos que 30 dias não são exibidos na timeline, mesmo que o chamado ainda esteja aberto. O log de auditoria é a fonte de verdade para eventos históricos.
