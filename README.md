# Engajamento Clientes

Plataforma SaaS B2B para organizar o relacionamento comercial, acompanhar empresas e contatos, registrar interações e orientar as próximas ações das equipes.

## Funcionalidades implementadas

### Fase 1 — Fundação e primeiros cadastros

- Autenticação, criação de organização e memberships com papéis por workspace.
- Seleção de organização ativa e isolamento de dados por tenant.
- Cadastro de empresas, contatos e vínculos entre eles.
- Registro de atividades comerciais e dashboard com dados persistidos.

### Fase 2 — Tarefas e jornadas

- Criação e gestão de tarefas comerciais, com filtros e paginação no servidor.
- Templates de jornadas com etapas configuráveis, inscrição automática de prospectos e início manual.
- Progressão idempotente das etapas, com pausa, retomada, cancelamento e datas calculadas no fuso da organização.
- Notificações internas, e-mail de atribuição após commit e Scheduler para lembretes de tarefas.
- Telas de jornadas, detalhe da empresa, dashboard e central de notificações.

### Fase 3 — Inteligência comercial

- Registro de vendas confirmadas e canceladas, com histórico por empresa, filtros, paginação e totais calculados a partir de compras confirmadas.
- Identificação separada de clientes sem compras recentes e empresas sem contato registrado; tarefas automáticas deduplicadas com intervalo configurável.
- Classificação comercial com critérios automáticos e manuais, composição explicada e histórico de evolução.
- Jornadas configuráveis de pós-venda e reativação integradas ao motor existente.
- Dashboard com indicadores comerciais persistidos, distribuição de faixas e lista de empresas que precisam de atenção.

### Fase 4 — Equipes e gestão

- Equipes com gestores e vendedores, memberships preservadas e escopo hierárquico sem compartilhamento de carteira entre vendedores.
- Convites com token armazenado em hash, expiração, reenvio, revogação e aceite por usuário existente ou por novo cadastro verificado.
- Distribuição e transferência individual ou em lote da carteira, com histórico e atualização de tarefas automáticas aplicáveis.
- Dashboard de gestores e relatórios paginados de atividades, relacionamento, classificação comercial e vendas confirmadas.

As migrations e a suíte completa foram validadas em PostgreSQL. A Fase 4 também validou um worker Redis com retentativa de e-mail, Scheduler idempotente, aceite concorrente de convite, entrega no Mailpit e seis fluxos E2E no Chromium. Consulte [docs/roadmap.md](docs/roadmap.md) para o estado atualizado.

### Fase 5 — Adoção (bloco parcial)

- Importação privada de empresas por CSV e XLSX, com prévia, mapeamento de colunas, validação de linhas, detecção conservadora de duplicidades, confirmação idempotente e relatório de resultado.
- Uploads ficam no disco privado e são limpos após 24 horas pelo Scheduler. Nesta entrega, a importação cria empresas novas; contatos, vínculos e vendas ainda não são suportados.
- Jornadas, tarefas e notificações não são iniciadas em massa. Linhas inválidas e duplicadas são ignoradas; registros existentes não são atualizados automaticamente.
- A Fase 5 permanece em andamento: onboarding, importação dos demais registros, exportação, auditoria e regras comerciais configuráveis ainda estão pendentes.

## Tecnologias

- Laravel 13, PHP 8.3+ e Eloquent.
- React 19, TypeScript, Inertia 3, Tailwind CSS 4 e shadcn/ui.
- PostgreSQL 16 para dados da aplicação.
- Redis 7 para filas e cache.
- OpenSpout 4.32 para leitura de CSV e XLSX (`ext-zip` e `ext-xmlreader` habilitadas no PHP).
- Vite+ para desenvolvimento, verificações e build dos ativos.
- Docker Compose com PostgreSQL, Redis e Mailpit para serviços locais.

## Requisitos de desenvolvimento

- PHP 8.3+ e Composer 2.
- Node.js 22+ e npm.
- Docker Desktop com Docker Compose v2; ou PostgreSQL 16+ e Redis compatíveis executados localmente.
- Git para acompanhar o projeto e trabalhar nas branches de cada fase.

## Instalação local no Windows

No PowerShell, na pasta do projeto:

```powershell
Copy-Item .env.example .env
docker compose up -d --wait
composer install
php artisan key:generate
npm ci
php artisan migrate --seed
npm run build
```

Para iniciar o ambiente de desenvolvimento:

```powershell
composer run dev
```

Esse comando usa o fluxo de desenvolvimento configurado pelo Artisan. Para encerrar, interrompa o processo no terminal. O Compose fornece PostgreSQL na porta `5432`, Redis na `6379` e Mailpit em `http://localhost:8025` (SMTP na porta `1025`). Se usar serviços fora do Docker, ajuste as conexões no arquivo `.env` local.

O seeder cria somente dados fictícios para desenvolvimento. A conta de demonstração local é `owner@example.test`, senha `password`; não utilize essas credenciais em ambientes públicos ou de produção. Nunca versione `.env`.

## Testes e verificações

```powershell
composer test
npm run check
npm run types:check
npm run build
npm run test:e2e
```

`composer test` executa Pint em modo de verificação, Larastan e Pest. `npm run check` executa as verificações de frontend configuradas pelo Vite+. `composer ci:check` combina as verificações de frontend e backend.

Os testes E2E usam Chromium e um banco SQLite temporário, criado e removido pelo harness. Instale o navegador uma vez com `npx playwright install chromium`; os convites de teste são enviados ao Mailpit local.

Para validar apenas o fluxo de importação implementado:

```powershell
php artisan test --compact tests/Feature/DataImportTest.php tests/Feature/PurgeExpiredDataImportsTest.php
npx playwright test tests/e2e/phase-5-import.spec.ts
```

## Fluxo de desenvolvimento

`main` representa versões estáveis. A Fase 5 está sendo desenvolvida em `feature/phase-5-saas-readiness` e deve passar por revisão antes de qualquer merge. Para retomar este trabalho:

```powershell
git switch feature/phase-5-saas-readiness
git pull --ff-only
```

Fases futuras devem partir da `main` atualizada em uma branch própria, por exemplo:

```powershell
git switch main
git pull --ff-only origin main
git switch -c feature/phase-6-next-capability
git push -u origin feature/phase-6-next-capability
```

## Documentação

- [Arquitetura](docs/architecture.md)
- [Banco de dados](docs/database.md)
- [Regras de negócio](docs/business-rules.md)
- [Roadmap](docs/roadmap.md)
- [Uso do Codex no projeto](docs/uso-do-codex.md)
