# Notificações — Documentação Técnica

> **Audiência:** Administrador técnico
> **Relacionado:** [Seção no README principal](../../README.md#-notificações-personalizadas)

## O que faz

Dispara notificações por email e por toast AJAX no navegador quando mensagens são enviadas no chat ou quando convidados entram e saem, usando o sistema de notificações nativo do GLPI.

## Fluxo completo

### Como configurar

1. Acesse **Administração > Notificações** no GLPI.
2. Filtre por "GLPI CHAT" na lista — os 8 eventos registrados aparecerão.
3. Para cada notificação: edite o modelo de email, adicione ou remova destinatários, ative ou desative o evento individualmente.
4. Salve. As alterações entram em vigor imediatamente para os próximos eventos.

### Como funciona a entrega

Quando um evento ocorre (ex: mensagem enviada no canal público):
1. O backend PHP chama `NotificationEvent::raiseEvent()` com o nome do evento e o objeto do chamado.
2. O GLPI avalia os destinatários configurados para aquele evento no perfil de notificação.
3. A notificação é entregue em dois modos **simultâneos**:
   - **Email**: enfileirado pelo sistema de notificações padrão do GLPI e enviado conforme as configurações de SMTP do sistema.
   - **AJAX toast**: o próximo ciclo de polling do widget detecta a notificação pendente e exibe um toast no navegador do destinatário (enquanto o GLPI estiver aberto).

## Permissões — detalhamento

As notificações não têm um direito próprio no GLPI Chat. A entrega segue os direitos dos canais:

- Notificações de `glpichat_public_message` e `glpichat_guest_message` só fazem sentido para usuários com `plugin_glpichat_public` (Read).
- Notificações de `glpichat_internal_message` só fazem sentido para usuários com `plugin_glpichat_internal` (Read).
- A **configuração** das notificações (criar/editar modelos, ativar/desativar eventos) exige o direito nativo do GLPI `config` (UPDATE) em **Administração > Notificações**.

## Tabelas de referência

### Eventos disponíveis

| Evento (código interno) | Disparado quando | Para quem |
|------------------------|------------------|-----------|
| `glpichat_public_message` | Alguém envia mensagem no canal Solicitante | Técnicos designados, grupos, observadores |
| `glpichat_internal_message` | Alguém envia mensagem no canal Discussão técnica | Técnicos designados, grupos, observadores |
| `glpichat_guest_message` | Um convidado externo envia mensagem | Técnicos designados, grupos, observadores |
| `glpichat_guest_joined` | Um convidado aceita o convite e entra no chat | Técnicos designados, grupos, observadores |
| `glpichat_guest_left` | Um convidado sai do chat (fecha a aba) | Técnicos designados, grupos, observadores |
| `glpichat_guest_left_timeout` | Sessão do convidado expira por inatividade | Técnicos designados, grupos, observadores |
| `glpichat_user_joined` | Registrado e instalado, mas nenhum ponto do código atual chama a função que dispararia este evento | — |
| `glpichat_user_left` | Registrado e instalado, mas nenhum ponto do código atual chama a função que dispararia este evento | — |

### Tags disponíveis no modelo de notificação

| Tag | O que retorna | Exemplo |
|-----|---------------|---------|
| `##glpichat.ticket_label##` | Nome e ID do chamado | `Helpdesk Ticket #42` |
| `##glpichat.sender_name##` | Nome de quem enviou a mensagem | `João Silva` / `Convidado #7` / `Sistema` |
| `##glpichat.message_preview##` | Preview da mensagem (máx. 180 caracteres) ou texto do evento | `Estou com problema no sistema...` |
| `##glpichat.unread_count##` | Total de mensagens não lidas (só aparece se >= 2) | `3` |
| `##glpichat.unread_badge##` | Badge formatado com total de não lidas | `(3)` |

## Comportamentos não óbvios

### Dois modos de entrega simultâneos

Cada evento dispara email **e** toast AJAX ao mesmo tempo — não é um ou outro. O toast depende de o destinatário estar com o GLPI aberto no navegador no momento do próximo ciclo de polling (até 2,5s de atraso para usuários com chat ativo, até 18s para usuários com aba em segundo plano). Se o GLPI estiver fechado, apenas o email é entregue.

### O toast não aparece para o próprio remetente

O sistema suprime a notificação AJAX para o usuário que acabou de enviar a mensagem — apenas os outros participantes do chamado recebem o toast.

### Notificações de convidado sempre aparecem como evento de sistema

As tags `##glpichat.sender_name##` para eventos de convidado retornam o nome informado no convite (ex: `João`), não um ID de usuário GLPI.

### `glpichat_user_joined` e `glpichat_user_left` nunca disparam atualmente

Os dois eventos são criados na instalação (`glpichat_install_default_notifications()`) e aparecem normalmente na lista de notificações filtrada por "GLPI CHAT" — mas as funções PHP responsáveis por chamar `NotificationEvent::raiseEvent()` para eles (`glpichat_raise_user_joined_notification_event()` / `glpichat_raise_user_left_notification_event()`, em `src/functions.php`) não são invocadas por nenhum outro ponto do plugin. Configurar destinatários para esses dois eventos não terá efeito prático até que algum fluxo do plugin passe a chamá-los.

## Limitações conhecidas

- Não há notificação por SMS ou webhook — apenas email e toast AJAX nativo do GLPI.
- As notificações seguem as mesmas regras de throttling e fila do sistema de notificações do GLPI — emails podem atrasar em instâncias com alta carga.
- Não é possível configurar destinatários diferentes por chamado — a configuração de destinatários é global por tipo de evento.
- O evento `glpichat_guest_left_timeout` pode demorar até o próximo ciclo de detecção de inatividade para ser disparado (não é instantâneo).
- `glpichat_user_joined` e `glpichat_user_left` estão registrados e instalados, mas nenhuma parte do plugin os dispara hoje — ver "Comportamentos não óbvios" acima.

<br>

<p align="center">
  <sub>━━━━━━━━━━━━━━━━━━━━━━━</sub><br>
  <sub>Curtiu o plugin? Deixe uma ⭐ no repositório para ajudar outros administradores a encontrá-lo.</sub><br>
  <sub>Desenvolvido e mantido com apoio de <a href="https://www.forq.com.br/">Forq</a></sub>
</p>
