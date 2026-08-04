# Documentação Técnica — GLPI Chat

Guias para administradores técnicos e desenvolvedores.
Para instalação e configuração geral, consulte o [README principal](../README.md).

---

## Features
*Para administradores técnicos que precisam de profundidade em configuração ou diagnóstico.*

| Doc | Quando consultar |
|-----|-----------------|
| [Gestão de Convidados](features/invites.md) | Ao configurar convites externos, diagnosticar sessão expirada ou entender o ciclo de vida de um convite |
| [Notificações](features/notifications.md) | Ao configurar templates de email, diagnosticar notificação não entregue ou entender os eventos disponíveis |
| [Histórico e Auditoria](features/audit.md) | Ao entender o que é registrado, diagnosticar eventos ausentes ou configurar permissão de auditoria |

---

## Arquitetura
*Para desenvolvedores que vão debugar ou modificar o código.*

### Database
| Doc | Quando consultar |
|-----|-----------------|
| [Schema de Banco](architecture/database/schema.md) | Ao criar uma migration, entender relacionamentos entre tabelas ou diagnosticar crescimento de dados |

### Fluxos
| Doc | Quando consultar |
|-----|-----------------|
| [Polling e Sincronização](architecture/flows/polling.md) | Ao debugar mensagens não entregues, latência, CSRF expirado ou comportamento de segundo plano |

### Frontend
| Doc | Quando consultar |
|-----|-----------------|
| [Widget de Chat](architecture/frontend/widget.md) | Ao modificar o widget, depurar estado do cliente ou entender gerenciamento de DOM e localStorage |

### Integração com GLPI
| Doc | Quando consultar |
|-----|-----------------|
| [Sistema de Permissões](architecture/glpi-integration/permissions.md) | Ao adicionar um novo direito, diagnosticar permissão não funcionando no Helpdesk ou entender a migração de direitos |
| [Ambiente e Configuração](architecture/glpi-integration/environment-and-config.md) | Pré-requisitos de deploy (url_base, usuário de followup externo, direitos, notificações) e tabela de configs do plugin |

### Performance
| Doc | Quando consultar |
|-----|-----------------|
| [Testes de Carga (k6)](architecture/performance/load-testing.md) | Ao avaliar quantos usuários simultâneos o sistema suporta, rodar/interpretar a suíte k6 de novo, ou investigar degradação de latência sob carga |

<br>

<p align="center">
  <sub>━━━━━━━━━━━━━━━━━━━━━━━</sub><br>
  <sub>Curtiu o plugin? Deixe uma ⭐ no repositório para ajudar outros administradores a encontrá-lo.</sub><br>
  <sub>Desenvolvido e mantido com apoio de <a href="https://www.forq.com.br/">Forq</a></sub>
</p>
