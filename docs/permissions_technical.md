# Gestão de Permissões no GLPI Chat (Detalhes Técnicos)

Este documento detalha o funcionamento interno do sistema de permissões (rights) do plugin **GLPI Chat**, incluindo a modelagem no banco de dados, ciclo de vida das sessões no GLPI, persistência de permissões para perfis de interface simplificada e a verificação de acessos no código PHP.

---

## 💾 Modelagem no Banco de Dados e Relação com Perfis

No GLPI, as permissões de perfis são associadas aos direitos de plugins. Os direitos específicos do plugin **GLPI Chat** são definidos pela função `glpichat_right_names()` no arquivo [functions.php](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/src/functions.php):

* `plugin_glpichat_read`
* `plugin_glpichat_send_public`
* `plugin_glpichat_send_internal`
* `plugin_glpichat_invite`
* `plugin_glpichat_view_audit`
* `plugin_glpichat_admin`

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

Abaixo está o mapeamento exato de como e onde as permissões são validadas no backend do plugin:

### 1. `plugin_glpichat_read` (READ)
* **Objetivo**: Determina se o usuário pode ver a UI do chat e visualizar a sala pública.
* **Código PHP**:
  * Em [functions.php](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/src/functions.php#L224):
    ```php
    return (bool) Session::haveRight('plugin_glpichat_read', READ);
    ```

### 2. `plugin_glpichat_send_public` (CREATE)
* **Objetivo**: Permite enviar mensagens no canal público do chamado (`Solicitante`).
* **Código PHP**:
  * Em [functions.php](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/src/functions.php#L244):
    ```php
    Session::haveRight('plugin_glpichat_send_public', CREATE);
    ```

### 3. `plugin_glpichat_send_internal` (CREATE)
* **Objetivo**: Permite visualizar e interagir no canal privado/técnico do chamado (`Discussão técnica`).
* **Código PHP**:
  * Em [functions.php](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/src/functions.php#L244) e [functions.php](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/src/functions.php#L261):
    ```php
    Session::haveRight('plugin_glpichat_send_internal', CREATE);
    ```

### 4. `plugin_glpichat_invite` (READ, CREATE, UPDATE)
* **Objetivo**: Controla o acesso à aba de participantes e a criação/revogação de convites de convidados externos.
* **Código PHP**:
  * Visualização da aba de participantes (requer `READ`):
    ```php
    return glpichat_can_read_ticket($tickets_id) && (bool) Session::haveRight('plugin_glpichat_invite', READ);
    ```
  * Criação, reemissão ou revogação de convites (requer `CREATE` / equivalência a `Write`):
    ```php
    if (!Session::haveRight('plugin_glpichat_invite', CREATE)) {
        throw new RuntimeException('User cannot invite guests.');
    }
    ```

### 5. `plugin_glpichat_view_audit` (READ)
* **Objetivo**: Permite a visualização da aba de auditoria e logs de eventos.
* **Código PHP**:
  * Em [functions.php](file:///home/h1d4n/Documents/dev-glpi/glpi/plugins/glpichat/src/functions.php#L290):
    ```php
    return glpichat_can_read_ticket($tickets_id) && (bool) Session::haveRight('plugin_glpichat_view_audit', READ);
    ```

### 6. `plugin_glpichat_admin` (READ, CREATE, UPDATE)
* **Objetivo**: Administrar as configurações globais do plugin.
* **Código PHP**:
  * Verificação reservada para menus de administração global e edição de configurações de sistema do plugin.
