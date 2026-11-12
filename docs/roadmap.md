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
- [x] Validar migrations e suíte completa em PostgreSQL, worker Redis, Scheduler e Mailpit.
- [x] Exercitar concorrência PostgreSQL em progressão de jornada, geração idempotente de tarefa e Scheduler de lembretes.

## Fase 3 — Inteligência comercial

- [x] Vendas, histórico, filtros, agregados e jornada pós-venda configurável.
- [x] Inatividade comercial e de relacionamento, cooldown idempotente e contatos recomendados.
- [x] Classificação por pontuação, critérios manuais, histórico de faixas e filtros.
- [x] Dashboard acionável e dados demonstrativos fictícios protegidos por ambiente.
- [x] Integração de jornadas pós-venda e reativação com o motor da Fase 2.
- [x] Validar migrations e suíte completa em PostgreSQL, worker Redis, Scheduler e Mailpit.
- [x] Exercitar concorrência PostgreSQL em alertas de inatividade, compra durante varredura, cancelamento durante recálculo e transferência durante progressão; verificar retentativa Redis e deduplicação.

## Fase 4 — Equipes e gestão

- [x] Equipes, memberships e escopo hierárquico para gestores.
- [x] Convites com token seguro, expiração, reenvio e aceite transacional.
- [x] Distribuição e transferência auditável de carteiras, incluindo lote transacional.
- [x] Dashboard gerencial e relatórios paginados por atividade, relacionamento e venda.
- [x] Validar migrations e suíte completa em PostgreSQL, aceite concorrente de convite, worker Redis, Scheduler e convite por e-mail no Mailpit.
- [x] Validar seis fluxos E2E no Chromium real, incluindo equipes, convite por Mailpit, carteira, tarefa, negação de acesso e relatórios.

## Fase 5 — Adoção e preparação comercial

- [x] Base tenant-first de lotes privados para CSV/XLSX, prévia, mapeamento de empresas, validação parcial e retenção de 24 horas.
- [x] Importação confirmada de empresas válidas, com detecção conservadora de duplicidades, resultado persistido e proteção contra repetição.
- [ ] Importação de contatos, vínculos e vendas; escolhas de deduplicação; processamento assíncrono e controle configurável de jornadas.
- [ ] Onboarding retomável e templates de adoção.
- [ ] Configuração explicável de pontuação e inatividade.
- [ ] Exportações CSV/XLSX autorizadas e downloads temporários privados.
- [ ] Auditoria consultável de operações comerciais e administrativas.
- [ ] Medições reprodutíveis de performance e estrutura para planos/limites sem gateway de pagamento.
- [ ] Suítes PostgreSQL, concorrência, Redis, Scheduler, Mailpit e E2E de ponta a ponta da Fase 5.

## Pendências técnicas

- A Fase 5 está em andamento e o corte de importação atual é somente de empresas, síncrono e sem iniciar jornadas. Consulte os itens pendentes antes de tratar a fase como concluída.
