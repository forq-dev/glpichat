# Gestão de Convidados — Documentação Técnica

> **Audiência:** Administrador técnico
> **Relacionado:** [Seção no README principal](../../README.md#-gestão-de-convidados--guia-completo)

## O que faz

Permite que técnicos gerem links seguros de uso único para convidar terceiros externos a participar do canal público de um chamado, sem que o convidado precise ter conta no GLPI.

## Fluxo completo

1. Técnico abre o chamado, acessa a aba **"Gestão de invites"** e preenche o nome (obrigatório) e email (opcional) do convidado.
2. O sistema gera um token SHA-256, armazena seu hash na tabela `glpi_plugin_glpichat_invites` e retorna o link de aceite com validade de 1 hora.
3. Técnico copia o link e envia ao convidado por qualquer meio externo (email, WhatsApp, etc.).
4. Convidado clica no link — o sistema valida o token, cria um registro em `glpi_plugin_glpichat_participants` e emite um cookie seguro de sessão (`glpichat_guest_session`).
5. Convidado vê a página do chat com o nome do chamado, um contador regressivo ("Expira em MM:SS") e o histórico do canal público.
6. Convidado envia mensagens — cada mensagem cria um `ITILFollowup` no chamado usando o usuário bot configurado em **Configuração > Plugins > GLPI Chat**.
7. Se o convidado fechar a aba, a saída é registrada imediatamente via `beforeunload`.
8. Após 1 hora de inatividade, a sessão expira automaticamente.

## Permissões — detalhamento

### `plugin_glpichat_invite`

| Coluna | O que libera | Contexto |
|--------|--------------|---------|
| Read | Abre a aba "Gestão de invites" para visualizar convites existentes e participantes ativos | Necessário para qualquer interação com a aba |
| Write | Exibe e habilita o formulário de criação de novo convite | Sem Write, o técnico pode ver a aba mas não criar links |
| Update | Habilita os botões "Reemitir" e "Encerrar chat" | Sem Update, o técnico não pode revogar nem gerar novo link para um convite existente |

**Justificativa por perfil:**
- **Solicitante (Self-Service)**: Sem acesso — o convite é uma ferramenta operacional do técnico, não do solicitante.
- **Técnico (Technician)**: Read + Write + Update — precisa criar, reemitir e encerrar sessões de convidados externos.
- **Líder Técnico (Supervisor)**: Read + Write + Update — mesmas necessidades operacionais do técnico, com acesso adicional à auditoria.
- **Administrador**: Read + Write + Update — acesso completo.

## Tabelas de referência

### Status dos convites

| Status | Significado | Quando ocorre |
|--------|-------------|---------------|
| Ativo | Link criado, aguardando o convidado usar | Imediatamente após a criação |
| Expirado | Passou 1h sem ninguém usar o link | TTL de `expires_at` vencido antes do aceite |
| Cancelado | Técnico revogou antes do uso | Ação manual "Cancelar convite" |
| Aceito | Convidado usou o link (sessão ainda não iniciada) | Token consumido no aceite |
| Em sessão | Convidado está conectado e ativo no chat | Após aceite, antes do encerramento |
| Sessão encerrada | Convidado saiu ou foi desconectado pelo técnico | `beforeunload` ou ação "Encerrar chat" |
| Sessão expirada | Convidado ficou inativo por mais de 1h | `last_activity` ultrapassou o TTL |

### Ações disponíveis para o técnico

| Ação | Botão na tela | Quando usar | O que acontece no banco |
|------|---------------|-------------|------------------------|
| Criar convite | "Criar invite" | Sempre (se tem Write) | Insere linha em `glpi_plugin_glpichat_invites` com `expires_at = now() + 3600s` |
| Reemitir | "Reemitir" | Convite ativo, expirado ou cancelado | Cria novo registro — `revoked_at` do anterior é preenchido |
| Cancelar convite | "Cancelar convite" | Convite ainda não aceito | Preenche `revoked_at` no registro atual |
| Encerrar chat | "Encerrar chat" | Convidado em sessão ativa | Preenche `left_at` no participante e invalida o cookie de sessão |

## Comportamentos não óbvios

### O link é de uso único e não pode ser reutilizado

O token do convite é consumido no primeiro aceite: o campo `accepted_at` é preenchido e o link deixa de ser válido. Se o convidado perder a sessão (cookie expirado, troca de navegador, modo anônimo fechado), ele **não consegue reutilizar o mesmo link** — o técnico precisa usar "Reemitir" para gerar um novo.

### A sessão é baseada em cookie, não em conta GLPI

A autenticação do convidado é feita exclusivamente pelo cookie `glpichat_guest_session`, que contém o hash da sessão. Se o usuário limpar os cookies ou abrir em outro dispositivo, a sessão é perdida mesmo que o convite ainda esteja válido.

### Mensagens do convidado aparecem como followup no chamado GLPI

Cada mensagem enviada por um convidado externo cria um `ITILFollowup` no chamado usando o usuário bot configurado em **Configuração > Plugins > GLPI Chat**. Sem esse usuário configurado, o envio de mensagem falha com erro visível ao convidado.

## Limitações conhecidas

- O convite é sempre para o canal **público** (`Solicitante`) — não existe convite para o canal interno.
- Não há envio automático de email com o link — o técnico precisa copiar e enviar manualmente.
- Um convidado não pode acessar outros chamados além do chamado para o qual foi convidado.
- Não há suporte a múltiplos convidados com o mesmo link — cada convite é nominalmente vinculado a um convidado.
