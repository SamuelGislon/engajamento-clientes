# Roadmap

## Fase 1 — Fundação e primeiros cadastros

- [x] Starter kit oficial, autenticação e configuração de infraestrutura.
- [x] Organização e memberships com papéis por workspace.
- [x] Empresas, contatos, vínculos N:N e histórico manual.
- [x] Dashboard com dados persistidos e seeders fictícios.
- [x] Regras de tenant e políticas iniciais de carteira.
- [x] Documentação e testes críticos iniciais.

## Fase 2 — Tarefas e jornadas

- [x] CRUD e central de tarefas com filtros e paginação no servidor.
- [x] Modelos de jornada, etapas configuráveis, inscrição automática e início manual.
- [x] Progressão idempotente, pausa, retomada, cancelamento e fuso por organização.
- [x] Notificações internas, e-mail de atribuição após commit e Scheduler de prazos.
- [x] Telas de jornada, detalhe da empresa, dashboard e central de notificações.
- [ ] Validar migrations e concorrência em PostgreSQL, worker Redis, Scheduler ativo e Mailpit com Docker.

## Fase 3 — Inteligência comercial

- [x] Vendas, histórico, filtros, agregados e jornada pós-venda configurável.
- [x] Inatividade comercial e de relacionamento, cooldown idempotente e contatos recomendados.
- [x] Classificação por pontuação, critérios manuais, histórico de faixas e filtros.
- [x] Dashboard acionável e dados demonstrativos fictícios protegidos por ambiente.
- [x] Integração de jornadas pós-venda e reativação com o motor da Fase 2.
- [ ] Validar migrations e concorrência em PostgreSQL, worker Redis, Scheduler ativo, Mailpit e fluxo E2E.

## Fase 4 — Equipes e gestão

- [ ] Equipes, convites e escopo hierárquico de carteira.
- [ ] Painel do gestor e relatórios por vendedor.

## Fase 5 — Adoção e preparação comercial

- [ ] Importação, onboarding, configuração de regras e exportação.
- [ ] Auditoria, performance e preparação de cobrança.

## Pendências técnicas

- Validar a implantação em ambiente com Docker e PostgreSQL.
- Formalizar convites, verificação de e-mail obrigatória e recuperação de acesso operacional.
- Adicionar testes E2E após estabilizar os fluxos principais.
