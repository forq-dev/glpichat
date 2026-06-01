# GLPI Chat

Plugin de chat contextual para GLPI 11.0.7+, vinculado a chamados.

O MVP implementa duas salas por chamado: uma sala pública para atendimento com solicitante/convidado e uma sala interna para discussão técnica. As mensagens, eventos, convites e auditoria são persistidos em banco.

## Widget de chat (UX atual)

- O launcher `Chat` fica fixo no rodapé e abre em formato chatbox.
- Cada chamado aberto vira um chatbox próprio ao lado, no estilo barra inferior.
- No bloco superior de notificações, cada chamado exibe:
  - bolinha verde para não lidas do canal `Solicitante`;
  - bolinha roxa para não lidas do canal `Discussão técnica`.
- Abaixo das notificações pendentes, o launcher lista todos os chamados vinculados ao usuário (solicitante, técnico ou observador).
- Esse bloco possui filtros por status:
  - `Novo` (status GLPI `INCOMING` = 1);
  - `Processando` (status GLPI `ASSIGNED` = 2 e `PLANNED` = 3);
  - `Pendente` (status GLPI `WAITING` = 4).
