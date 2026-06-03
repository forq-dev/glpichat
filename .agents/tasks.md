# Checklist de Evolução — GLPI Chat

Checklist de próximas implementações de segurança, ciclo de vida e monitoramento operacional.

## 🔒 1. Segurança de Uploads
- [x] Implementar validação de extensão de arquivos herdando as regras de `$CFG_GLPI['document_types']` em `glpichat_upload_document()`.
- [x] Validar o tamanho do arquivo no backend contra o limite global configurado no GLPI.

## 🕒 2. Validade e Gestão de Sessões de Convidados
- [ ] Adicionar coluna `last_activity` na tabela `glpi_plugin_glpichat_participants`.
- [ ] Criar opção de configuração na interface administrativa do plugin (em `glpi_configs`) para definir o TTL da sessão de convidados (padrão inicial: 1 hora).
- [ ] Implementar lógica de expiração automática da sessão de convidados no backend se a inatividade superar o TTL configurado.
- [ ] Adicionar um contador regressivo (countdown) visual no frontend do chat do convidado mostrando quanto tempo resta de sessão.
- [ ] Adicionar botão de "Desconectar" ou "Revogar Acesso" manual para convidados na aba **Participantes**.
- [ ] Atualizar o status do participante para `left_at` e limpar o token de sessão hash ao expirar ou revogar.

## 🔄 3. Ciclo de Vida do Chamado
- [ ] Ocultar/Desabilitar o formulário de envio de mensagens no frontend quando o chamado estiver nos status `Solucionado` (5) ou `Fechado` (6).
- [ ] Validar o status do chamado no backend (`SendController`, `UploadController` e `GuestController`) e recusar novas inserções se o chamado estiver concluído.


## 🔔 4. Feed Operacional (Hooks)
- [ ] Registrar o hook nativo `item_update` em `setup.php` apontando para o manipulador de eventos.
- [ ] Implementar método `itemUpdate()` no `HookHandler` para capturar atualizações de status, prioridade e atribuições do chamado.
- [ ] Gerar eventos de sistema (`glpi_plugin_glpichat_events`) correspondentes às alterações capturadas pelo hook.
- [ ] Adicionar renderizadores visuais no frontend (`glpichat.js`) para mostrar os eventos do sistema na timeline de mensagens.

## 🕒 5. Presença e Indicador de Digitação
- [ ] Adicionar colunas `last_seen` e `is_typing_until` na tabela `glpi_plugin_glpichat_participants`.
- [ ] Modificar a chamada de polling no frontend para passar o parâmetro `is_typing` baseado em eventos de digitação do textarea.
- [ ] Implementar a renderização visual do status de presença e "digitando..." no cabeçalho ou rodapé do chat do chamado correspondente.

## ⚡ 6. Otimizações de Banco de Dados
- [x] Inverter a ordem do índice composto `participants_room_user` de `(rooms_id, users_id)` para `(users_id, rooms_id)` no método `glpichat_add_indexes()` em `src/functions.php`.
- [x] Implementar a estrutura de Ação Automática (`CronTask`) nativa do GLPI no plugin (registrando em `setup.php` e executando no `hook.php`) para limpar registros de `glpi_plugin_glpichat_events` com mais de 30 dias.

## 🔑 7. Melhorias e Ajustes de Permissionamento
- [ ] Corrigir as validações de revogação (`glpichat_revoke_invite`) e reemissão (`glpichat_reissue_invite`) de convites em `src/functions.php` para exigir o direito `UPDATE` (`Session::haveRight('plugin_glpichat_invite', UPDATE)`) em vez de `CREATE`.
- [ ] Adicionar suporte ao direito `READ` (Leitura) para a permissão "Discussão interna" (`plugin_glpichat_send_internal`) em `src/ProfileTab.php` e ajustar a validação em `glpichat_can_read_room()` para verificar `READ` em vez de `CREATE`, permitindo perfis passivos de "Apenas Leitura" no canal técnico.
- [ ] Criar a interface administrativa de configuração do plugin (para controle visual do `external_followup_user_id` e o TTL da sessão de convidados) e protegê-la com a validação da permissão `plugin_glpichat_admin`.
- [ ] Envolver o carregamento de JavaScript e CSS do widget em `setup.php::plugin_init_glpichat()` com uma verificação condicional que valida se o perfil ativo possui a permissão `plugin_glpichat_read` (impedindo o carregamento e injeção do widget para usuários sem direitos de leitura).

## 🛡️ 8. Correções de Segurança (Auditoria)
- [x] Corrigir a função `sanitize()` em `public/js/glpichat.js` para escapar caracteres especiais HTML (`&`, `<`, `>`, `"`, `'`), mitigando Stored XSS generalizado no chat.
- [x] Sanitizar a variável `displayContent` na renderização otimista do chat do convidado (linha 1731) em `glpichat.js` usando a nova função `sanitize()`, mitigando Self XSS.
- [x] Criar função `escapeHtml()` no script inline de `src/TicketTab.php` e escapar o nome do convidado (`invite.guest_name`) antes de concatená-lo na linha HTML do painel administrativo.
- [x] Implementar verificação real de token CSRF em `glpichat_require_logged_csrf()` em `src/functions.php` chamando `\Session::validateCSRF($input)`, evitando CSRF nas controllers que alteram estado.
- [x] Validar a extensão dos arquivos enviados (`UploadedFile`) usando a classe nativa do GLPI (`DocumentType::isValidExtension()`) e checar o tamanho do arquivo antes de movê-lo para o disco em `glpichat_upload_document()`.
- [x] Garantir que arquivos órfãos de upload (em caso de falhas na persistência do documento no banco) sejam fisicamente deletados de `GLPI_UPLOAD_DIR` no backend.
- [x] Habilitar o atributo `Secure` no cookie de sessão do convidado (`GLPICHAT_GUEST_COOKIE`) quando o GLPI estiver rodando sob HTTPS em `GuestController.php`.
- [ ] Mitigar Bracket Object Notation inseguras no `glpichat.js`:
  - [ ] Whitelistar os valores aceitos de `payload.room_type` e `ticket.activeTab` antes de acessar propriedades de objetos com colchetes (ex: nas linhas 245, 247).
  - [ ] Substituir acessos por colchetes por propriedades fixas explícitas em rotinas de abas (ex: nas linhas 685, 720, 880, 1861).
  - [ ] Usar RegExp `/^\d+:(public|internal)$/` para validar a integridade da chave do canal (`key`) de retorno da API antes de acessar o patch em `pollData.channels` e `data.channels` (linhas 1420, 1508).
  - [ ] Substituir o acesso dinâmico de índice de array do tipo `array[array.length - 1]` usando a função moderna de JS `.at(-1)` (linhas 224, 819, 1402, 1429) para silenciar o analisador estático.

## 🧪 9. Validação Pendente
- [ ] Validar manualmente no navegador o envio normal de mensagem pelo chat com CSRF real.
- [ ] Validar manualmente no navegador o upload permitido pelo chat.
- [ ] Validar manualmente em HTTPS o cookie `GLPICHAT_GUEST_COOKIE` com atributo `Secure`.
- [ ] Validar manualmente a rejeição de `POST /plugins/glpichat/Send` sem CSRF pelo fluxo real do widget.
- [ ] Validar manualmente se o `403` atual vem do `CheckCsrfListener` do core ou de token inválido no widget.
