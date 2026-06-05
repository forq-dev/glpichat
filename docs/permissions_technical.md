# Gestão de Permissões no GLPI Chat (Detalhes Técnicos)

Este documento detalha o funcionamento interno do sistema de permissões (rights) do plugin **GLPI Chat**, incluindo a modelagem no banco de dados, ciclo de vida das sessões no GLPI, persistência de permissões para perfis de interface simplificada e a verificação de acessos no código PHP.

---

## 💾 Modelagem no Banco de Dados e Relação com Perfis

No GLPI, as permissões de perfis são associadas aos direitos de plugins. Os direitos específicos do plugin **GLPI Chat** são definidos pela função `glpichat_right_names()` no arquivo [domain.php](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/src/domain/domain.php):

* `plugin_glpichat_public`
* `plugin_glpichat_internal`
* `plugin_glpichat_invite`
* `plugin_glpichat_view_audit`
* `plugin_glpichat_admin`

**Total: 5 direitos ativos.** Direitos legados (`plugin_glpichat_read`, `plugin_glpichat_send_public`, `plugin_glpichat_send_internal`, `plugin_glpichat_read_public`, `plugin_glpichat_read_internal`) foram consolidados nos 5 direitos acima via migração automática (`rights_model_version = 6`).

Quando o administrador altera os direitos na aba do plugin de um perfil, esses direitos são armazenados na tabela `glpi_profilerights` do banco de dados do GLPI (associando o `profiles_id`, o nome da permissão/direito e o valor numérico que representa a máscara de bits dos direitos concedidos, ex: `READ`, `CREATE`, `UPDATE`).

---

## 🔑 Carregamento e Ciclo de Vida da Sessão do GLPI

1. **Autenticação**: Ao fazer login ou alternar o perfil ativo, o GLPI lê a tabela `glpi_profilerights` e popula a sessão global do PHP com as permissões do perfil ativo em:
   `$_SESSION['glpiactiveprofile']`
2. **Método de Checagem**: O código PHP do plugin verifica as permissões chamando o método estático nativo do GLPI:
   `Session::haveRight(string $right_name, int $right_value)`
   * Onde `$right_value` pode ser as constantes nativas `READ` (Leitura), `CREATE` (Gravação/Write) ou `UPDATE` (Atualização/Update).

---

## ⚡ Registro de Permissões para Perfis de Interface Simplificada (Helpdesk)

Os perfis que utilizam a interface simplificada (Helpdesk/Self-Service) no GLPI passam por um processo de filtragem de direitos na sessão global PHP. Esse processo é gerenciado pelo método nativo `Profile::cleanProfile()`, executado pelo core do GLPI a cada carregamento de página.

Por padrão, essa filtragem remove da sessão os direitos customizados de plugins que não estejam declarados como válidos para a interface de helpdesk. Para garantir a persistência das permissões do plugin na sessão de usuários da interface simplificada, os direitos do **GLPI Chat** são registrados de forma dinâmica na inicialização do plugin.

Isso é feito na função `plugin_init_glpichat()` no arquivo [setup.php](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/setup.php#L31-L35), onde o plugin injeta seus direitos no array estático `\Profile::$helpdesk_rights`:

```php
foreach (glpichat_right_names() as $right) {
    \Profile::$helpdesk_rights[] = $right;
}
```

Dessa forma, o GLPI preserva os direitos definidos do plugin na sessão do usuário durante a navegação e carregamentos de página na interface simplificada.

---

## 🛠️ Mapeamento de Verificação nos Arquivos de Código

Abaixo está o mapeamento exato de como e onde as permissões são validadas no backend do plugin. As verificacoes estao centralizadas em [access.php](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/src/domain/access.php) e [domain.php](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/src/domain/domain.php), substituindo as funcoes que antes estavam em `functions.php`.

### 1. `plugin_glpichat_public` (READ, CREATE)
* **Objetivo**: Controla acesso ao canal público do chamado (`Solicitante`).
* **READ**: Habilita a visualização do widget e leitura da sala pública.
* **CREATE** (Write): Permite enviar mensagens no canal público.
* **Código PHP**:
  ```php
  glpichat_can_bootstrap_widget_from_state($can_read_public_room, $can_read_internal_room);
  glpichat_can_read_room_from_state($ticket_can_read, $can_read_public_room, 'public');
  glpichat_can_send_from_state($can_read_public_room, $has_public_create_right, 'public');
  ```

### 2. `plugin_glpichat_internal` (READ, CREATE)
* **Objetivo**: Controla acesso ao canal técnico interno (`Discussão técnica`).
* **READ**: Exibe a aba de discussão técnica e permite ler mensagens internas.
* **CREATE** (Write): Permite enviar mensagens no canal interno.
* **Código PHP**:
  ```php
  glpichat_can_read_room_from_state($ticket_can_read, $can_read_internal_room, 'internal');
  glpichat_can_send_from_state($can_read_internal_room, $has_internal_create_right, 'internal');
  ```

### 3. `plugin_glpichat_invite` (READ, CREATE, UPDATE)
* **Objetivo**: Controla o acesso à aba de participantes e a criação/revogação de convites de convidados externos.
* **Código PHP**:
  * Visualização da aba de participantes (requer `READ`):
    ```php
    glpichat_can_view_participants_from_state($can_read_ticket, $has_invite_read_right);
    ```
  * Criação de convites (requer `CREATE`):
    ```php
    glpichat_can_create_invites_from_state($can_read_public_room, $has_invite_create_right);
    ```
  * Revogação/reemissão de convites (requer `UPDATE`):
    ```php
    glpichat_can_update_invites_from_state($can_read_public_room, $has_invite_update_right);
    ```

### 4. `plugin_glpichat_view_audit` (READ)
* **Objetivo**: Permite a visualização da aba de auditoria e logs de eventos.
* **Código PHP**:
  ```php
  glpichat_can_view_audit_from_state($can_read_ticket, $has_audit_read_right);
  ```

### 5. `plugin_glpichat_admin` (READ, UPDATE)
* **Objetivo**: Administrar as configurações globais do plugin.
* **READ**: Permite visualizar as configurações do plugin na interface administrativa.
* **UPDATE**: Permite editar e salvar alterações nas configurações globais.
* **Código PHP**:
  * Verificação reservada para menus de administração global e edição de configurações de sistema do plugin.

---

## ⚙️ Migração de Direitos (rights_model_version = 6)

O plugin inclui migração automática de direitos legados para o modelo atual. A migração é orquestrada em [setup_helpers.php](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/src/db/setup_helpers.php):

| Versão | Ação |
|--------|------|
| 1→2 | Adiciona `plugin_glpichat_read` com READ |
| 2→3 | Adiciona `plugin_glpichat_invite` com READ/CREATE/UPDATE |
| 3→4 | Adiciona `plugin_glpichat_view_audit` com READ e `plugin_glpichat_admin` com READ/UPDATE |
| 4→5 | Consolida `plugin_glpichat_read`+`plugin_glpichat_send_public` → `plugin_glpichat_public`, migra `plugin_glpichat_send_internal` → `plugin_glpichat_internal` |
| 5→6 | Remove legados e recarrega sessão (`Session::reloadCurrentProfile()`) |
