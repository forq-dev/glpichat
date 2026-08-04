# Contribuindo com o GLPI Chat

Obrigado por considerar contribuir com o **GLPI Chat**! Este documento descreve como propor mudanças, reportar problemas e configurar seu ambiente de desenvolvimento.

---

## 📋 Antes de começar

* Leia o [README.md](README.md) para entender o propósito e as funcionalidades do plugin.
* Verifique as [issues abertas](https://github.com/forq-dev/glpichat/issues) para evitar duplicidade — sua ideia ou bug pode já estar em discussão.
* Mudanças estruturais (novos fluxos, endpoints, controllers, permissões) tendem a ser mais bem recebidas se discutidas antes numa issue, para alinhar escopo e abordagem.

---

## 🐛 Reportando bugs

Abra uma [issue de bug](https://github.com/forq-dev/glpichat/issues/new?template=bug_report.md) incluindo:

* Versão do GLPI e do plugin (`plugin.xml` → `<version><num>`).
* Versão do PHP e do banco de dados (MySQL/MariaDB).
* Passos para reproduzir, comportamento esperado e comportamento observado.
* Logs relevantes (GLPI, PHP, navegador) — **sem dados sensíveis** (tokens, senhas, e-mails reais).

## 💡 Sugerindo funcionalidades

Abra uma [issue de feature](https://github.com/forq-dev/glpichat/issues/new?template=feature_request.md) descrevendo o problema que a funcionalidade resolveria, não apenas a solução proposta.

---

## 🛠️ Configurando o ambiente de desenvolvimento

### Requisitos

* GLPI `11.0.7` ou superior, rodando localmente ou em container.
* PHP `8.2` ou superior.
* Node.js (para os testes unitários de frontend com Vitest) e `npm`.
* MySQL ou MariaDB compatível com o GLPI.
* `composer` para dependências PHP de desenvolvimento.

### Passos

1. Faça um fork do repositório e clone-o dentro de `plugins/glpichat` da sua instalação GLPI:

   ```bash
   cd /var/www/html/glpi/plugins
   git clone https://github.com/<seu-usuario>/glpichat.git glpichat
   cd glpichat
   ```

2. Instale as dependências:

   ```bash
   composer install
   npm install
   ```

3. Copie `.env.example` para `.env` (ou exporte as variáveis diretamente no shell/CI) e ajuste os valores conforme seu ambiente local. Veja os comentários de cada variável no próprio arquivo.

4. Ative o plugin na interface do GLPI (**Configurar > Plugins**) ou via CLI, conforme descrito na seção "Instalação Rápida" do [README](README.md).

---

## ✅ Rodando os testes

A suíte de testes está documentada em detalhe em [`tests/README.md`](tests/README.md). Resumo rápido:

```bash
# Testes unitários PHP (não dependem de banco/kernel do GLPI)
composer test:unit:php

# Roteador principal de suítes (unit-php, unit-js, smoke-php, smoke-http, e2e)
./tests/run_unit_php.sh [suite] [base_url] [ticket_id] [room_type]

# Esteira completa (abre um ticket dedicado, roda tudo, faz teardown)
./tests/run_full_suite.sh [base_url]
```

Para as camadas de integração/smoke/E2E você precisa de uma instância GLPI rodando com o plugin instalado e os usuários `glpi`/`self` configurados — veja o pré-requisito de ambiente em [`tests/README.md`](tests/README.md#pré-requisitos-de-ambiente).

**Antes de abrir um PR:**

* Toda mudança em código PHP deve passar sem achados de debug residual (`var_dump`/`dd`/`die`), `phpcs` e `phpstan`, quando essas ferramentas estiverem configuradas no seu ambiente.
* Novas regras de negócio devem vir acompanhadas de testes unitários (`tests/unit/php`); fluxos sensíveis (guest, upload, permissões) devem ter cobertura de smoke/E2E quando aplicável.
* Rode a suíte relevante localmente antes de submeter — o CI roda `ci-js.yml` e `ci-full.yml` automaticamente no PR, mas falhas detectadas cedo economizam ciclos de review.

---

## 🔀 Enviando um Pull Request

1. Crie uma branch a partir de `main` com um nome descritivo (`fix/...`, `feat/...`, `docs/...`).
2. Faça commits pequenos e coesos, com mensagens no formato `tipo: descrição` (`feat`, `fix`, `refactor`, `docs`, `test`, `chore`, `perf`, `ci`).
3. Garanta que os testes relevantes passam localmente (veja seção acima).
4. Abra o PR descrevendo **o quê** mudou e **por quê** — inclua um plano de teste quando a mudança tocar em fluxos críticos (guest flow, uploads, autenticação, permissões).
5. Mudanças em áreas sensíveis de segurança (controllers, uploads, autenticação, checagem de direitos) recebem revisão extra antes de merge — espere perguntas ou pedidos de ajuste nesses casos.

---

## 🔒 Segurança

Não abra issues públicas para vulnerabilidades de segurança. Reporte de forma responsável através dos canais de contato do repositório (ver perfil da organização/mantenedores em https://github.com/forq-dev) antes de divulgar publicamente, para dar tempo de correção.

Nunca inclua segredos, tokens, credenciais ou dados pessoais reais em issues, PRs, commits ou logs anexados.

---

## 📜 Licença

Ao contribuir, você concorda que sua contribuição será licenciada sob a mesma licença do projeto: **GPL v3+** (ver [LICENSE](LICENSE)).

---

## 🎨 Estilo de código

* PHP: siga o estilo já usado no plugin (PSR-12 como base, conforme `composer.json`); rode `phpcs`/`phpstan` se disponíveis no seu ambiente antes de submeter.
* JavaScript: siga os padrões existentes em `public/` e a configuração de lint/format do projeto, quando presente.
* Evite reformatar arquivos inteiros em um PR que não seja dedicado a isso — mantenha os diffs focados na mudança proposta.
