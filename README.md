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

## Tecnologias

- Laravel 13, PHP 8.3+ e Eloquent.
- React 19, TypeScript, Inertia 3, Tailwind CSS 4 e shadcn/ui.
- PostgreSQL 16 para dados da aplicação.
- Redis 7 para filas e cache.
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

## Fluxo de desenvolvimento

A branch `main` representa o estado estável. Cada fase nova deve começar em uma branch `feature/` atualizada a partir de `main` e ser integrada de volta após revisão. Após integrar a Fase 4, inicie a próxima fase:

```powershell
git switch main
git pull --ff-only origin main
git switch -c feature/phase-5-saas-readiness
git push -u origin feature/phase-5-saas-readiness
```

Crie cada branch quando for iniciar a respectiva fase.

## Documentação

- [Arquitetura](docs/architecture.md)
- [Banco de dados](docs/database.md)
- [Regras de negócio](docs/business-rules.md)
- [Roadmap](docs/roadmap.md)
- [Uso do Codex no projeto](docs/uso-do-codex.md)
