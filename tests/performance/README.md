# Performance

Esta pasta contém a suíte de carga/performance baseada em k6.

## Execução

```bash
./tests/performance/run_k6.sh http://localhost:8080
```

## Variáveis de ambiente

- `GLPICHAT_WIDGET_COOKIE`: cookie autenticado do GLPI para `/Widget/Poll`
- `GLPICHAT_WIDGET_CSRF_TOKEN`: token CSRF válido para `/Widget/Poll`
- `GLPICHAT_WIDGET_TICKET_ID`: ticket usado na simulação do poll
- `GLPICHAT_GUEST_COOKIE`: cookie do convidado para `/Guest/Sync`
- `GLPICHAT_GUEST_LAST_MESSAGE_ID`: cursor inicial de mensagens do convidado
- `GLPICHAT_GUEST_LAST_EVENT_ID`: cursor inicial de eventos do convidado
- `GLPICHAT_WIDGET_RATE`, `GLPICHAT_WIDGET_DURATION`, `GLPICHAT_WIDGET_P95_MS`
- `GLPICHAT_GUEST_RATE`, `GLPICHAT_GUEST_DURATION`, `GLPICHAT_GUEST_P95_MS`
