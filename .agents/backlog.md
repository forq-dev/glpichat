# Backlog Técnico e Próximos Passos — GLPI Chat

Este documento registra as ideias de evolução, melhorias de segurança e análise de novas funcionalidades discutidas durante o brainstorming de arquitetura.

---

## 📊 1. Análise Aprofundada: Server-Sent Events (SSE) vs Polling

### Como o SSE se comporta em PHP (Apache/PHP-FPM)
Ao contrário do Node.js, Go ou Elixir, que usam loops de eventos assíncronos de thread única ou corrotinas leves para manter milhares de conexões abertas com consumo irrisório de RAM, o PHP tradicional (modelo síncrono por processo) é altamente ineficiente para conexões persistentes como SSE:

* **Esgotamento de Workers**: Cada conexão SSE aberta consome **um worker inteiro do PHP-FPM**.
* **Consumo de Memória**: Um worker do PHP-FPM carregando o core do GLPI consome entre **30MB e 80MB de RAM**. Se 50 usuários estiverem com o chat aberto, o servidor consumirá entre **1.5GB e 4GB de RAM** apenas para manter as conexões SSE ociosas.
* **Bloqueio Geral (Gargalo de Concorrência)**: Se a configuração do PHP-FPM tiver um limite de, por exemplo, 40 workers simultâneos (`pm.max_children`), e 40 usuários abrirem o chat, **nenhum outro usuário conseguirá navegar no GLPI**. Todos receberão erro `504 Gateway Timeout` ou lentidão extrema.
* **Escalabilidade**: Para rodar SSE de forma escalável em PHP, seria necessário acoplar servidores assíncronos (Swoole, RoadRunner ou ReactPHP) ou um servidor de mensageria externo (Pushpin, Mercure), o que inviabiliza a instalação simples do plugin em servidores tradicionais de clientes.

### Comparativo Técnico

| Métrica | Polling Incremental (Atual) | Server-Sent Events (SSE) |
| :--- | :---: | :---: |
| **Latência** | Média de 1.2s (intervalo de 2.5s) | Praticamente instantânea (~50ms) |
| **Carga no Servidor** | Transiente (requisições curtas de 15ms) | Contínua (mantém processo PHP preso) |
| **Consumo de Memória** | Baixo e intermitente | Altíssimo (multiplica workers de FPM) |
| **Compatibilidade** | 100% universal (qualquer Apache/Nginx) | Requer ajustes de timeout e buffering |
| **Facilidade de Escalar** | Muito Alta | Muito Baixa |

* **Veredito**: **O ganho real de latência do SSE (reduzir de 2s para 0.05s) NÃO justifica o peso nos workers e a perda de escalabilidade.** Para manter o plugin **leve, robusto e compatível** com qualquer servidor de produção do GLPI, **devemos manter o Polling Incremental Dinâmico**.

---

## 🔒 2. Próximos Passos de Segurança e Sessões

### A. Segurança de Uploads (Herança do GLPI)
* **Objetivo**: Em vez de codificar uma validação estática no plugin, devemos fazer o plugin **herdar nativamente as configurações globais do GLPI** (formatos permitidos e tamanho máximo configurados em *Configurar > Geral > Documentos*).
* **Implementação**: 
  * O método `glpichat_upload_document()` já utiliza a classe nativa `Document` do GLPI para criar o registro.
  * No entanto, antes de mover o arquivo no backend, o plugin deve chamar os validadores internos do GLPI ou consultar as propriedades:
    * `$CFG_GLPI['document_types']` para validar se a extensão é permitida.
    * A diretiva do PHP/GLPI para limite máximo de tamanho de upload.

### B. Validade e Expiração de Sessões de Convidados
* **Expiração Automática e Configurável**:
  * O tempo padrão inicial é de **1 hora** após o convidado entrar.
  * O tempo limite (TTL) da sessão deve ser configurável na tela de administração do plugin (valores armazenados na configuração do plugin).
  * O backend rastreará a atividade do convidado com a coluna `last_activity` na tabela `glpi_plugin_glpichat_participants`.
* **Contador Regressivo (Countdown)**:
  * O frontend do chat do convidado (`GuestController` / `glpichat.js`) exibirá um contador regressivo na tela, atualizado dinamicamente via JS, informando ao convidado quanto tempo de sessão ainda resta antes de expirar.
* **Revogação Manual**:
  * Permitir que o técnico, na aba de **Participantes**, clique em um botão para "Desconectar" ou "Revogar Sessão" de um convidado ativo, o que imediatamente marca `left_at` e limpa o hash de token dele no banco, invalidando as chamadas subsequentes de `/Guest/Sync`.

---

## 🔄 3. Ciclo de Vida do Chamado e Hooks

### A. Integração com o Ciclo de Vida do Chamado
* **Objetivo**: Bloquear qualquer envio de mensagem em chats vinculados a chamados que atingiram os status de **Solucionado** (status GLPI = 5) ou **Fechado** (status GLPI = 6).
* **Comportamento**:
  * **Frontend**: O formulário de envio de mensagens é ocultado ou desabilitado e exibe uma mensagem informativa: *"Este chamado está solucionado/fechado. O chat foi arquivado."*
  * **Backend**: Validar o status do chamado nas controllers de envio (`SendController`, `UploadController`, `GuestController`) e impedir inserções se o chamado não estiver ativo.

### B. Notificação no Chat e Feed Operacional via Hook
* **Status do Hook**: O GLPI já disponibiliza o hook nativo `item_update`, que é disparado sempre que um chamado (ou qualquer outro item) é atualizado no banco.
* **Tarefa**:
  1. Registrar o hook no arquivo `setup.php`:
     ```php
     $PLUGIN_HOOKS['item_update']['glpichat'] = [HookHandler::class, 'itemUpdate'];
     ```
  2. Implementar `HookHandler::itemUpdate()`:
     * Verificar se o item é uma instância de `Ticket`.
     * Comparar as mudanças nos campos importantes (técnico atribuído, grupo atribuído, status, prioridade).
     * Se houver mudanças, gravar um evento do sistema na tabela `glpi_plugin_glpichat_events` com o payload json correspondente (ex: `{'old_status': 2, 'new_status': 4}`).
  3. No frontend, adicionar renderizadores no JavaScript para processar esses eventos de sistema na lista de mensagens (exibindo avisos como: *"O status do chamado foi alterado para Pendente"*).

---

## 🕒 4. Presença e Indicador de Digitação (Typing Indicator)

### Análise de Custo do `$GLPI_CACHE` vs Banco de Dados
Se o GLPI não tiver um driver de cache em memória configurado (como Redis ou APCu), o `$GLPI_CACHE` nativo do core cai automaticamente para a escrita de arquivos no disco (Filesystem Cache no diretório `files/_cache`). 
* **Impacto**: O Filesystem Cache é lento para escritas frequentes (a cada 2,5s por cliente), gerando concorrência de travas de arquivo no sistema operacional (file locking).
* **Solução**: Usar colunas de banco de dados diretamente na tabela existente `glpi_plugin_glpichat_participants` (`last_seen` e `is_typing_until`).
* **Performance**: A atualização de dados via Chave Primária (`UPDATE ... WHERE id = ?`) no MySQL/MariaDB é otimizada pelo Buffer Pool da InnoDB, rodando em menos de **1ms** diretamente na RAM do servidor de banco de dados antes de persistir em disco. Isso a torna muito mais rápida e estável que o cache em arquivos.

### Arquitetura do Fluxo no Polling
* **Sem Requisições Extras (Piggybacking)**:
  * Quando o chat está aberto, o frontend já dispara o loop de polling para buscar novas mensagens.
  * Capturamos o evento `input` ou `keydown` na caixa de texto. Se o usuário estiver digitando, marcamos um estado local `isTyping = true`.
  * No corpo da chamada `POST /Widget/Poll` (que já é enviada a cada 2,5s), injetamos o campo `is_typing`.
  * O backend recebe a requisição, atualiza `last_seen = NOW()` e `is_typing_until = (is_typing ? NOW() + 4s : NULL)` do participante correspondente no banco.
  * O frontend recebe e exibe o indicador visual na tela (ex: *"Técnico Silva está digitando..."*).

### C. Persistência de Mensagens (Sem Cache em Redis)
* **Decisão**: As mensagens continuarão sendo lidas diretamente do MySQL/MariaDB durante o polling.
* **Justificativa**: 
  * A consulta de polling utiliza o índice composto `(rooms_id, id)`, gastando menos de 1ms no banco de dados e sendo servida direto da RAM do MySQL (InnoDB Buffer Pool) na maioria das vezes.
  * O Redis deve ser restrito a dados altamente efêmeros (como presença e indicador de digitação).

---

## ⚡ 5. Otimizações de Banco de Dados

### A. Otimização do Índice de Participantes
* **Ação**: Inverter a ordem das colunas do índice composto `participants_room_user` de `(rooms_id, users_id)` para `(users_id, rooms_id)`.
* **Motivo**: A consulta nativa para contar unreads isola o filtro em `users_id`. Como a tabela armazena a união de participantes, a inversão permite indexação rápida tanto para buscas exclusivas de usuários quanto para a busca combinada.

### B. Limpeza de Eventos Obsoletos (Ação Automática / CronTask)
* **Ação**: Registrar uma rotina de cron no GLPI para executar periodicamente a limpeza da tabela `glpi_plugin_glpichat_events` (removendo registros mais antigos que 30 dias).
* **Motivo**: A tabela serve apenas para deltas visuais do chat ativo no navegador, acumulando milhões de dados mortos rapidamente. Limpá-la previne inchaço de armazenamento e mantém as consultas de polling rápidas a longo prazo.

---

## 🔑 6. Ajustes de Permissionamento e Segurança

### A. Alinhamento da Permissão de Convites (`plugin_glpichat_invite`)
* **Problema**: O backend valida `glpichat_revoke_invite()` e `glpichat_reissue_invite()` usando `CREATE` (direito de escrita), ignorando o direito de atualização (`UPDATE`) que está configurável na matriz de direitos de perfil.
* **Ação**: Mudar a checagem no PHP para exigir `UPDATE` (`Session::haveRight('plugin_glpichat_invite', UPDATE)`), respeitando a separação de controle configurada no perfil.

### B. Granularidade do Canal Técnico Interno (`plugin_glpichat_send_internal`)
* **Problema**: Atualmente a visualização da aba Discussão Técnica exige o direito `CREATE`. Isso impede perfis que precisam apenas visualizar (auditores e observadores) de acessar o chat técnico passivamente.
* **Ação**: Implementar suporte a `READ` em `ProfileTab.php`. Mudar a checagem em `glpichat_can_read_room()` para exigir `READ` para visualizar a aba técnica, e manter a checagem `CREATE` apenas para o ato de postar mensagens.

### C. Ativação da Permissão Administrativa (`plugin_glpichat_admin`)
* **Problema**: O direito `plugin_glpichat_admin` não é verificado em nenhuma parte do código do backend porque o plugin carece de uma interface de configurações.
* **Ação**: Desenvolver um controller administrativo ou página de configuração para salvar variáveis do plugin (ex: `external_followup_user_id`, TTL de sessão de convidados) e protegê-lo com `Session::haveRight('plugin_glpichat_admin', READ/UPDATE)`.

### D. Ocultação Total do Widget por Permissão
* **Problema**: Atualmente, os arquivos CSS e JS do widget são carregados e o HTML básico do dock é injetado no DOM por padrão para todos os usuários logados, mesmo que o perfil do usuário não possua o direito de leitura do chat (`plugin_glpichat_read` com `READ` inativo). Isso causa injeção inútil de scripts no navegador e um ícone vazio ou quebrado na tela.
* **Ação**: No arquivo `setup.php`, na função `plugin_init_glpichat()`, envolver o carregamento dos arquivos CSS e JS em uma checagem condicional de sessão e permissão:
  ```php
  if (isset($_SESSION['glpiactiveprofile']) && Session::haveRight('plugin_glpichat_read', READ)) {
      $PLUGIN_HOOKS[Hooks::ADD_CSS]['glpichat'] = ['public/css/glpichat.css'];
      $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['glpichat'] = ['public/js/glpichat.js'];
      // e os hooks legados de CSS/JS correspondentes
  }
  ```
* **Ganho**: Segurança a nível de carregamento de recursos. Para quem não tem a permissão configurada no perfil, o widget de chat desaparece completamente do DOM e nenhum asset do chat (CSS/JS) é baixado pelo navegador, otimizando o carregamento de páginas do GLPI.

---

## 🛡️ 7. Auditoria de Vulnerabilidades Específicas

### A. Vulnerabilidade Crítica de Stored XSS (Cross-Site Scripting)
* **Vulnerabilidade**: No frontend (`glpichat.js`), a função `sanitize(text)` foi declarada de forma incompleta como `const sanitize = (text) => String(text || "")`, retornando a string original sem qualquer codificação. Além disso, em `TicketTab.php`, nomes de convidados (`invite.guest_name`) são injetados diretamente em `row.innerHTML`.
* **Risco**: Ataques de execução de script arbitrário persistente. Se um usuário enviar uma mensagem ou criar um convite com tags de script (ex: `<script>` ou handlers `onerror`), o código será executado na sessão de outros usuários, incluindo administradores do GLPI (permitindo roubo de privilégios e alteração de dados).
* **Ação de Correção**:
  * Redefinir a função `sanitize()` no JS para codificar caracteres como `&`, `<`, `>`, `"`, `'` em entidades HTML equivalentes.
  * Criar e aplicar uma função helper similar de escape no Javascript de renderização do `TicketTab.php`.

### B. Proteção CSRF Incompleta no Backend
* **Vulnerabilidade**: A função auxiliar `glpichat_require_logged_csrf(array $input)` em `src/functions.php` apenas valida se o usuário está logado via `Session::checkLoginUser()`, sem efetivamente validar o token CSRF (`_glpi_csrf_token`) que é enviado de forma consistente pelo frontend nas chamadas de escrita.
* **Risco**: Uma requisição forjada a partir de um site malicioso de terceiros pode enviar mensagens ou criar/revogar convites na conta de um usuário do GLPI ativo.
* **Ação de Correção**: Invocar o método oficial do GLPI `Session::validateCSRF($input)` na validação da função em `src/functions.php`.

### C. Segurança de Uploads de Arquivos (RCE e Uploads Órfãos)
* **Vulnerabilidade**: Arquivos enviados por usuários ou convidados são gravados diretamente no disco via `$file->move(GLPI_UPLOAD_DIR, $safe_name)` antes de validar a permissão ou a validade da inserção no banco de dados. Caso a criação do documento no GLPI falhe, o arquivo malicioso permanece silenciosamente gravado no servidor.
* **Risco**: Upload de scripts maliciosos executáveis (RCE) e inchaço do armazenamento devido ao acúmulo de arquivos órfãos.
* **Ação de Correção**:
  * Verificar a validade da extensão do arquivo contra as extensões permitidas no GLPI com `DocumentType::isValidExtension()` e checar o tamanho do arquivo antes da movimentação física para o disco.
  * Garantir a exclusão imediata e segura do arquivo temporário criado caso ocorra alguma exceção no método `Document::add()`.






