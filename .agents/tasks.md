# Checklist de Evolução — GLPI Chat

Checklist de próximas implementações de segurança, ciclo de vida e monitoramento operacional.

## 🔒 1. Segurança de Uploads
- [ ] Implementar validação de extensão de arquivos herdando as regras de `$CFG_GLPI['document_types']` em `glpichat_upload_document()`.
- [ ] Validar o tamanho do arquivo no backend contra o limite global configurado no GLPI.
- [ ] Bloquear o processamento de uploads perigosos (ex: `.php`, `.sh`, `.phtml`, `.js`, `.htaccess`) diretamente no início da requisição.

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
- [ ] Inverter a ordem do índice composto `participants_room_user` de `(rooms_id, users_id)` para `(users_id, rooms_id)` no método `glpichat_add_indexes()` em `src/functions.php`.
- [ ] Implementar a estrutura de Ação Automática (`CronTask`) nativa do GLPI no plugin (registrando em `setup.php` e executando no `hook.php`) para limpar registros de `glpi_plugin_glpichat_events` com mais de 30 dias.


