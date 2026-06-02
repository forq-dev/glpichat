# Decisoes Tecnicas Atuais

## 2026-06-01 - Base de contexto do projeto

- Decisao: Tratar o plugin como GLPI 11.x.
- Motivo: evidencia direta no core local (`src/autoload/constants.php`) com `GLPI_VERSION = 11.0.7`.
- Impacto: manter uso de controllers com atributos e padroes de seguranca do GLPI 11.

## 2026-06-01 - Escopo do `.agents`

- Decisao: Persistir contexto tecnico em 5 arquivos:
  - `glpi-context.md`
  - `plugin-context.md`
  - `design-patterns-glpi.md`
  - `decisions.md`
  - `inspection-notes.md`
- Motivo: formato recomendado pela skill `glpi-plugin-dev` para reduzir redescoberta.
- Impacto: aceleracao de analise em tarefas futuras sem depender de memoria fora do repo.

## 2026-06-01 - Fonte de verdade de padroes

- Decisao: priorizar evidencias do core local e do plugin local em vez de suposicoes.
- Motivo: evitar deriva de implementacao e respeitar padroes reais do checkout atual.
- Impacto: qualquer nova interface/fluxo deve partir de referencias concretas ja registradas.

## 2026-06-02 - Rejeição do Server-Sent Events (SSE)
- Decisão: Manter o Polling Incremental Dinâmico e descartar o uso de SSE.
- Alternativas consideradas: SSE (Server-Sent Events) via PHP persistent connections.
- Motivo: SSE em PHP bloqueia workers FPM e consome muita memória RAM, causando esgotamento e lentidão geral no GLPI. O polling indexado incremental atual (2,5s) é leve, escalável e compatível com qualquer servidor tradicional de produção.

## 2026-06-02 - Expiração Configurável e Countdown de Convidados
- Decisão: Definir expiração automática de sessões de convidados com tempo limite configurável no painel administrativo (tempo padrão: 1 hora) e exibir um contador regressivo (countdown) visual no frontend.
- Alternativas consideradas: Manter sessões abertas indefinidamente (como estava no MVP), ou usar expiração estática hardcoded no PHP.
- Motivo: Garantir que sessões externas não fiquem ativas permanentemente por razões de conformidade e segurança, dando clareza ao convidado de quanto tempo resta de sessão.

## 2026-06-02 - Herança de Regras de Upload do GLPI
- Decisão: Herdar nativamente as extensões permitidas e limite de tamanho configurados no core do GLPI ($CFG_GLPI['document_types']).
- Motivo: Centralizar a política de segurança de arquivos no painel do administrador do GLPI, evitando duplicar regras estáticas no plugin.

## 2026-06-02 - Integração de Ciclo de Vida e Hooks
- Decisão: Bloquear envios no chat de chamados Solucionados/Fechados e registrar o hook `item_update` para notificar mudanças do chamado na timeline do chat.
- Motivo: Respeitar o fluxo de auditoria oficial do GLPI e enriquecer o contexto da conversação técnica e de atendimento.

## 2026-06-02 - Lógica de Presença e Digitação no Polling (Piggybacking)
- Motivo: Evitar novas conexões HTTP no servidor. Armazenar no banco de dados via chave primária (lookup <1ms) ou em cache na RAM (se Redis/APCu ativo) é muito mais seguro e consome menos recursos de I/O do que criar arquivos temporários (no caso de caches de arquivos).

- Decisão: Manter as mensagens sendo lidas direto do banco MySQL/MariaDB durante o polling.
- Motivo: A consulta de mensagens utiliza o índice composto `(rooms_id, id)`, processando a resposta em menos de 1ms de RAM (InnoDB Buffer Pool) na grande maioria das vezes. Caching de mensagens em Redis traria alta complexidade de invalidamento de cache e escrita dupla sem benefícios reais de desempenho.

## 2026-06-02 - Otimizações de Performance e Estrutura de Banco de Dados
- Decisão: Reordenar o índice composto de participantes para `(users_id, rooms_id)` e registrar uma rotina automática de limpeza (`CronTask`) para deltas antigos de eventos.
- Motivo: Permitir indexação em consultas isoladas de contagem de não lidas por usuário (`users_id`), evitando scans de tabela inteira. A limpeza automática evita o crescimento desnecessário da tabela de eventos de sincronização que perdem a utilidade após o término da sessão do usuário.



