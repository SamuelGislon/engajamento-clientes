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
- [x] Importação de contatos e vínculos com empresas existentes acessíveis; deduplicação de linhas da mesma pessoa e preservação dos papéis específicos por vínculo.
- [x] Importação histórica de vendas com empresa e vendedor autorizados, validação de valores/datas/status, detecção de repetição e recálculo comercial.
- [ ] Escolhas explícitas de deduplicação (criar, atualizar, ignorar, resolução manual) e extensão da seleção de jornadas aos fluxos aplicáveis. Importação de empresas já permite não iniciar, usar a jornada padrão ou escolher um modelo de prospecção, somente para novos prospectos elegíveis.
- [x] Gravação assíncrona retomável após confirmação para arquivos com mais de 250 linhas, com staging cifrado, progresso, blocos transacionais, retries, heartbeat de worker, reenvio limitado de lotes presos na fila, retomada idempotente, autorização tenant-aware e expurgo seguro.
- [ ] Avaliar enfileirar também leitura e validação de arquivos grandes; hoje a validação ainda percorre até 5.000 linhas na requisição.
- [x] Onboarding guiado e retomável por organização: dados, alertas iniciais, jornada padrão e autoinscrição; convites, equipes e carteira opcionais; organizações existentes não são redirecionadas. Testes de feature, revalidação de owner sob lock e fluxo E2E no Chromium.
- [ ] Templates de adoção para iniciar a configuração com diferentes perfis comerciais.
- [ ] Configuração explicável de pontuação e inatividade (pesos, bandas, nomes/cores e limites são editáveis e validados; recálculo em fila com snapshot, retomada e polling, mais apresentação no dashboard, foram implementados; auditoria administrativa central e E2E específico de fila ainda pendentes).
- [ ] Exportações CSV/XLSX autorizadas e downloads temporários privados.
- [ ] Auditoria consultável de operações comerciais e administrativas.
- [ ] Medições reprodutíveis de performance e estrutura para planos/limites sem gateway de pagamento.
- [ ] Suítes PostgreSQL, concorrência, Redis, Scheduler, Mailpit e E2E de ponta a ponta da Fase 5.

## Pendências técnicas

- A Fase 5 está em andamento. A gravação de arquivos de até 250 linhas permanece na requisição; arquivos maiores usam Redis em blocos retomáveis de até 100 linhas após confirmação. A validação continua percorrendo até 5.000 linhas na requisição. `composer test` executou 260 testes no PostgreSQL: 258 passaram e dois condicionais da fila Redis foram verificados separadamente com Redis real (2/2 passaram). A suíte E2E completa passou com 14 fluxos no Chromium; `npm run check`, `npm run types:check`, `npm run build` e PHPStan também passaram. O teste PostgreSQL de onboarding confirma que revogar a membership após a autorização inicial impede a mutação subsequente. Vendas confirmadas atualizam indicadores e automações comerciais, e a gravação revalida a membership atual do vendedor e da equipe. Identificadores externos estáveis evitam reimportação entre lotes; sem eles, as linhas são importadas individualmente para não descartar compras legítimas com atributos coincidentes. Confirmação repetida do mesmo lote é idempotente. O onboarding retomável por organização foi implementado para owners novos, com configuração de organização, alertas, jornada e autoinscrição; convites, equipes e carteira podem ser adiados. Empresas importadas elegíveis podem iniciar nenhuma jornada, a padrão ou um modelo de prospecção escolhido; escolhas explícitas de resolução permanecem pendentes. Há também um sinal residual de existência quando um identificador único colide com empresa fora da carteira do vendedor; definir um fluxo de revisão que preserve a unicidade sem expor essa correspondência está pendente antes de fechar a importação. Exportações, auditoria consultável, templates de adoção, métricas de performance e estrutura mínima de planos continuam pendentes; a Fase 5 ainda não atende ao critério final. Consulte os itens pendentes antes de tratar a fase como concluída.
