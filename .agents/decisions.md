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
