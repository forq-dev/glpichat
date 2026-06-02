# Padroes GLPI Reutilizaveis (core + plugin)

## Baseline de versao

- Ambiente confirmado em GLPI `11.0.7` (core local).
- Controllers com `#[Route(...)]` sao padrao valido neste ambiente.

## Padroes de UI e extensao de abas

- Extensao de abas em itens GLPI:
  - Registro por `Plugin::registerClass(..., ['addtabon' => ...])` em `setup.php`.
  - Implementacao da aba com `displayTabNameForItem()` e `displayTabContentForItem()` em classes dedicadas.
  - Evidencias locais:
    - plugin: `src/TicketTab.php`, `src/ProfileTab.php`
    - core: `/home/h1d4n/Documents/dev-glpi/glpi/src/CommonGLPI.php` (assinatura e fluxo de tabs)
    - core: `/home/h1d4n/Documents/dev-glpi/glpi/src/Ticket.php:818` (`displayTabContentForItem`)

## Padroes de seguranca e sessao

- Sessao autenticada para endpoints privados:
  - `Session::checkLoginUser()` antes de operar.
  - Evidencia: `src/Controller/WidgetController.php`
- Token CSRF retornado e renovado no payload JSON:
  - `'_glpi_csrf_token' => Session::getNewCSRFToken()`
  - Evidencia: `src/Controller/WidgetController.php`
- Campo oculto para token em formularios:
  - `Html::hidden('_glpi_csrf_token', ...)`
  - Evidencia: `src/ProfileTab.php`
  - Corroboracao core: `/home/h1d4n/Documents/dev-glpi/glpi/src/Appliance_Item.php:173`

## Padrao de controllers GLPI 11

- Controllers do plugin estendem `Glpi\Controller\AbstractController`.
- Rotas via atributo Symfony:
  - `#[Route('/Widget/Channels', ...)]`, etc.
- Estrategia de firewall por atributo:
  - `#[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]`
- Evidencia: `src/Controller/WidgetController.php`

## Padroes de dados e consultas

- Nomenclatura de tabelas de plugin:
  - Prefixo `glpi_plugin_glpichat_...`
  - Evidencia: `src/functions.php` (`glpichat_table_names()` e DDL)
- Consultas com QueryBuilder/array style de GLPI DB:
  - Uso de `$DB->request([...])`, `$DB->insert(...)`, `$DB->update(...)`.
  - Evidencia: `src/functions.php`
- Indices criados em migration com `Migration::addKey`.
  - Evidencia: `src/functions.php` (`glpichat_add_indexes`)

## Padroes de frontend no contexto GLPI

- Asset loading via hooks de plugin (`ADD_CSS`, `ADD_JAVASCRIPT`).
- Reuso de classes GLPI/Tabler na UI renderizada:
  - exemplos no plugin: `table table-sm`, `btn`, `btn-outline-*`, `alert alert-warning`.
  - evidencia: script embutido em `src/TicketTab.php`.
- Polling adaptativo no widget em vez de refresh bruto de pagina.
  - evidencia: `public/js/glpichat.js` (`schedulePolling`, `/Widget/Poll`)

## Regras praticas para futuras mudancas

- Manter extensao em tabs e controllers do plugin, sem alterar core.
- Preservar fluxo de sessao + CSRF em qualquer endpoint mutavel.
- Reusar classes visuais nativas do GLPI antes de criar estruturas novas.
