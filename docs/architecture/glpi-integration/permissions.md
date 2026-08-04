# Integração GLPI — Sistema de Permissões

> **Audiência:** Desenvolvedor
> **Relacionado:** [Índice de Documentação Técnica](../../README.md)

## O que é

Mecanismo de direitos de perfil do GLPI usado pelo plugin para controlar acesso aos canais de chat, convites e auditoria, incluindo a técnica de registro dinâmico em `Profile::$helpdesk_rights` para garantir que os direitos persistam em sessões de interface simplificada (Helpdesk).

## Como o plugin usa este mecanismo

### Registro dos direitos

Os 5 direitos do plugin são definidos pela função `glpichat_right_names()` em `src/domain/domain.php`:

```
plugin_glpichat_public
plugin_glpichat_internal
plugin_glpichat_invite
plugin_glpichat_view_audit
plugin_glpichat_admin
```

Na inicialização do plugin (`plugin_init_glpichat()` em `setup.php`), cada direito é injetado em `Profile::$helpdesk_rights`:

```php
foreach (glpichat_right_names() as $right) {
    \Profile::$helpdesk_rights[] = $right;
}
```

Sem essa injeção, o método `Profile::cleanProfile()` do GLPI core removeria os direitos do plugin da sessão `$_SESSION['glpiactiveprofile']` a cada carregamento de página para usuários na interface simplificada.

### Verificação de acesso

Todas as checagens de permissão do plugin passam por `Session::haveRight($right_name, $right_value)`, onde `$right_value` é uma das constantes nativas do GLPI: `READ`, `CREATE` ou `UPDATE`.

As verificações estão centralizadas em `src/domain/access.php` e `src/domain/domain.php`:

```php
// Exemplos de uso real no plugin
glpichat_can_read_room_from_state($ticket_can_read, $can_read_public_room, 'public');
glpichat_can_send_from_state($can_read_public_room, $has_public_create_right, 'public');
glpichat_can_create_invites_from_state($can_read_public_room, $has_invite_create_right);
glpichat_can_view_audit_from_state($can_read_ticket, $has_audit_read_right);
```

Essas funções recebem booleans já resolvidos (calculados uma vez por request) em vez de chamar `Session::haveRight()` múltiplas vezes — padrão de "estado pré-computado" para evitar leituras repetidas da sessão.

### Armazenamento no banco

Quando o administrador salva permissões em um perfil, o GLPI registra em `glpi_profilerights` o par `(profiles_id, name, rights)`, onde `rights` é uma máscara de bits dos valores `READ`, `CREATE`, `UPDATE` concedidos.

## Evidência no GLPI core

| API / Hook / Classe | Arquivo no core | Linha | Para que é usado no plugin |
|--------------------|-----------------|-------|---------------------------|
| `Profile::$helpdesk_rights` | `src/Profile.php` | `65` | Array estático onde o plugin injeta seus direitos para preservá-los na sessão de Helpdesk |
| `Profile::cleanProfile()` | `src/Profile.php` | `613` | Método do core que filtra direitos não-helpdesk da sessão — contornado pela injeção em `$helpdesk_rights` |
| `Session::haveRight()` | `src/Session.php` | `1473` | Método estático do core usado para verificar se o usuário tem um direito específico |

## Versão do GLPI

Validado para **GLPI 11.0.7+**. A técnica de injeção em `Profile::$helpdesk_rights` e a assinatura de `Session::haveRight()` estão presentes e funcionais nessa versão.

A migração de direitos legados é controlada pela variável de configuração `rights_model_version` (valor atual: `6`) armazenada em `glpi_plugin_glpichat_configs`. O histórico de migrações está documentado em `src/db/setup_helpers.php`.

## Riscos e pontos de atenção

**`Profile::$helpdesk_rights` é uma propriedade interna sem API pública oficial.** A injeção direta nesse array funciona no GLPI 11.x mas depende de comportamento interno do core que não é documentado como API estável. Uma atualização do GLPI pode renomear, encapsular ou remover essa propriedade. Monitorar em cada upgrade de versão do GLPI.

**`rights_model_version` desatualizado causa migração incorreta.** Se a versão registrada em `glpi_plugin_glpichat_configs` ficar inconsistente com o código (ex: por rollback manual de banco), a migração pode rodar novamente ou ser pulada, deixando direitos em estado inválido. O sintoma é: usuários perdem acesso a features sem motivo aparente após uma atualização do plugin.

**Direitos calculados como estado pré-computado podem mascarar mudanças de perfil em tempo real.** As funções em `access.php` calculam os booleans de permissão uma vez por request. Se o administrador alterar o perfil de um usuário enquanto ele estiver com o widget aberto, o usuário só perceberá a mudança na próxima recarga de página — não no próximo ciclo de polling.

**Perfis na interface Helpdesk dependem da inicialização do plugin para ter permissões.** Se `plugin_init_glpichat()` não for executado (ex: plugin desativado e reativado sem reload), os direitos não são injetados em `Profile::$helpdesk_rights` e usuários Helpdesk perdem acesso ao widget mesmo com os direitos configurados corretamente no perfil.

<br>

<p align="center">
  <sub>━━━━━━━━━━━━━━━━━━━━━━━</sub><br>
  <sub>Curtiu o plugin? Deixe uma ⭐ no repositório para ajudar outros administradores a encontrá-lo.</sub><br>
  <sub>Desenvolvido e mantido com apoio de <a href="https://www.forq.com.br/">Forq</a></sub>
</p>
