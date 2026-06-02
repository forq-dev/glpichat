# Contexto do Plugin glpichat

## Identidade

- Nome: `GLPI Chat`
- Chave: `glpichat`
- Versao declarada: `1.0.0`
- Arquivo fonte da versao: `setup.php` (`PLUGIN_GLPICHAT_VERSION`)

## Requisitos declarados

- GLPI minimo: `11.0.7`
- PHP minimo: `8.2`
- Evidencia: `setup.php`, funcao `plugin_version_glpichat()`

## Estrutura principal do plugin

- Bootstrap e metadados:
  - `setup.php`
  - `hook.php`
- Regras e servicos funcionais:
  - `src/functions.php`
  - `src/HookHandler.php`
- Controllers HTTP:
  - `src/Controller/WidgetController.php`
  - `src/Controller/SendController.php`
  - `src/Controller/UploadController.php`
  - `src/Controller/SyncController.php`
  - `src/Controller/InviteController.php`
  - `src/Controller/InviteManageController.php`
  - `src/Controller/GuestController.php`
- Integracoes de UI em abas:
  - `src/TicketTab.php`
  - `src/ProfileTab.php`
- Frontend:
  - `public/js/glpichat.js`
  - `public/css/glpichat.css`

## Hooks e registros detectados

- CSRF compliance: `$PLUGIN_HOOKS['csrf_compliant']['glpichat'] = true`
- Assets globais do plugin:
  - CSS via `Hooks::ADD_CSS` e `add_css`
  - JS via `Hooks::ADD_JAVASCRIPT` e `add_javascript`
- Hook de evento:
  - `Hooks::ITEM_ADD` => `[HookHandler::class, 'itemAdd']`
- Registro de tabs:
  - `Plugin::registerClass(TicketTab::class, ['addtabon' => Ticket::class])`
  - `Plugin::registerClass(ProfileTab::class, ['addtabon' => Profile::class])`
- Caminho stateless:
  - `SessionManager::registerPluginStatelessPath('glpichat', '#^/Guest/.*#')`

## Tabelas e direitos do plugin

- Tabelas:
  - `glpi_plugin_glpichat_rooms`
  - `glpi_plugin_glpichat_participants`
  - `glpi_plugin_glpichat_messages`
  - `glpi_plugin_glpichat_events`
  - `glpi_plugin_glpichat_invites`
  - `glpi_plugin_glpichat_readstates`
  - `glpi_plugin_glpichat_auditlogs`
- Direitos:
  - `plugin_glpichat_read`
  - `plugin_glpichat_send_public`
  - `plugin_glpichat_send_internal`
  - `plugin_glpichat_invite`
  - `plugin_glpichat_view_audit`
  - `plugin_glpichat_admin`

## Endpoints principais detectados

- Widget:
  - `GET /Widget/Channels`
  - `POST /Widget/Poll`
  - `GET /Widget/History`
  - `POST /Widget/MarkRead`
- Mensagens:
  - `POST /Send`
  - `POST /Upload`
  - `GET /Sync`
- Convites:
  - `POST /Invite`
  - `GET /Invite/List`
  - `POST /Invite/Revoke`
  - `POST /Invite/Reissue`
- Guest:
  - `GET /Accept/{token}`
  - `GET /Guest/Sync`
  - `POST /Guest/Send`
  - `POST /Guest/Upload`
