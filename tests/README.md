# Testes Automatizados do Plugin

Este diretório contém a suíte de testes do plugin organizada por camada de confiança.

## Estrutura Atual

```text
tests/
├── bootstrap/
│   └── unit.php                # Bootstrap mínimo para testes unitários puros
├── helpers/                    # Helpers operacionais de desenvolvimento e manutenção
│   └── patch_animations.py
├── e2e/
│   └── python/
│       └── chat_security_e2e_test.py
├── fixtures/                   # Arquivos de apoio compartilhados
│   ├── allowed_upload.txt
│   ├── blocked_upload.html
│   └── blocked_upload.php
├── smoke/
│   ├── http/
│   │   └── http_security_smoke_test.sh
│   └── php/
│       ├── event_cleanup_smoke_test.php
│       ├── security_smoke_test.php
│       └── ticket_lifecycle_smoke_test.php
├── unit/
│   ├── js/
│   │   ├── setup.js
│   │   ├── vitest.config.js
│   │   └── run_vitest.sh       # Runner padrão da suíte unitária JS
│   └── php/
│       ├── access_test.php     # Decisões puras de autorização e acesso
│       └── domain_test.php     # Núcleo puro inicial
│       ├── guest_accept_test.php # Aceitação de invite e sessão guest
│       └── guest_flow_test.php # Fluxo sensível de guest send/sync/upload
│       └── invite_manage_flow_test.php # Listagem, revogação e reemissão
│       └── invite_flow_test.php # Criação administrativa de invite
│       └── message_flow_test.php # Orquestração pura de envio e upload
│       └── readstate_test.php  # Regras puras de unread/readstate
└── run_unit_php.sh             # Roteador principal das suítes de teste
```

## Camadas

1. **Unitário**
   - Cobre regras puras e determinísticas.
   - Não depende de banco, sessão ou kernel do GLPI.
   - Exemplos atuais: `tests/unit/php/domain_test.php`, `tests/unit/php/access_test.php`, `tests/unit/php/message_flow_test.php`, `tests/unit/php/guest_flow_test.php`, `tests/unit/php/guest_accept_test.php`, `tests/unit/php/invite_flow_test.php` e `tests/unit/php/invite_manage_flow_test.php`.
2. **Integração / Smoke PHP**
   - Valida comportamento real do plugin no runtime do GLPI.
   - Usa banco, sessão, hooks, controllers e filesystem reais.
3. **Browser / E2E**
   - Valida fluxos visíveis no frontend e no guest flow.

## Execução

### Roteador principal

Executa todas as suítes por padrão ou uma camada específica por parâmetro:

```bash
./tests/run_unit_php.sh
./tests/run_unit_php.sh unit
./tests/run_unit_php.sh unit-php
./tests/run_unit_php.sh unit-js
./tests/run_unit_php.sh smoke
./tests/run_unit_php.sh smoke-php
./tests/run_unit_php.sh smoke-http http://localhost:8080 2 public
./tests/run_unit_php.sh e2e http://localhost:8080
```

### Unitário PHP

Executa apenas os testes unitários PHP no container `glpi`:

```bash
./tests/run_unit_php.sh unit-php
```

### Smoke PHP existente

Exemplos de execução direta no container:

```bash
./tests/run_unit_php.sh smoke-php
```

### Smoke HTTP autenticado

```bash
./tests/run_unit_php.sh smoke-http http://localhost:8080 2 public
```

### E2E Browser

```bash
./tests/run_unit_php.sh e2e http://localhost:8080
```

## Diretrizes

1. **Refatoração guiada por testes**: cada extração nova deve ganhar primeiro um teste unitário ou de integração compatível com o tipo de risco.
2. **Teardown obrigatório**: qualquer teste que escreva em banco ou disco deve limpar seus artefatos.
3. **Sem downgrade artificial**: regras puras vão para a camada unitária; integrações reais continuam protegidas por smoke/integration.
4. **Comportamento externo preservado**: testes devem validar o contrato real do plugin, não o detalhe interno da implementação.
5. **Helpers não são suíte**: scripts em `tests/helpers/` apoiam manutenção local, mas não fazem parte da taxonomia de unit/smoke/e2e.
