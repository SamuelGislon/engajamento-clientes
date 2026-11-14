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
- [x] Escolhas explícitas de deduplicação para empresas: criar quando não há colisão de CNPJ, atualizar campos mapeados de um único candidato, resolver correspondências ambíguas e ignorar. As decisões são preservadas ao percorrer grupos de até 100 erros, os candidatos exibem identificador/CNPJ mascarado e a gravação revalida tenant, carteira e membership do gestor sob lock.
- [x] Escolhas explícitas de duplicidade para contatos: criar sem fusão, atualizar um candidato único, resolver manualmente uma ambiguidade ou ignorar; decisões abrangem todas as linhas da pessoa, preservam vínculos anteriores/status e são revalidadas no tenant/carteira antes da gravação, inclusive em staging assíncrono cifrado. Identidades somente visíveis fora da carteira são tratadas como novo contato sem revelar o registro oculto; após perda de acesso, erros e contagens obsoletos na tela viram avisos genéricos de escopo, inclusive o excedente quando o limite de 100 erros foi atingido. Locks consultivos transacionais PostgreSQL serializam criações concorrentes da mesma identidade.
- [x] Escolhas explícitas de duplicidade para vendas com identificador externo: atualizar uma venda visível ou ignorar; unicidade impede criação duplicada, uma única linha do arquivo pode atualizar cada venda e candidatos são revalidados no tenant/carteira e sob lock. Análise e gravação de cada bloco bloqueiam empresas de origem/destino por ID crescente antes das equipes; a gravação bloqueia também a membership atual do gestor da empresa de origem para serializar revogações e transferências. A análise carrega os campos necessários para a policy permitir atualização da venda própria do vendedor. Conflitos inacessíveis retornam erro genérico sem revelar duplicidade do banco, enquanto repetições adicionais no próprio arquivo são contadas sem expor o registro. Importação de empresas permite não iniciar jornadas, usar a jornada padrão ou escolher um modelo de prospecção, somente para novos prospectos elegíveis.
- [x] Gravação assíncrona retomável após confirmação para arquivos com mais de 250 linhas, com staging cifrado, progresso, blocos transacionais, retries, heartbeat de worker, reenvio limitado de lotes presos na fila, retomada idempotente, autorização tenant-aware e expurgo seguro.
- [ ] Validação assíncrona de arquivos grandes (WIP): prévia limitada, decisão por amostra de 251 linhas, job tenant-aware, status/progresso, retentativa e recuperação pelo Scheduler estão codificados; aguarda as verificações listadas no checkpoint abaixo antes de ser marcada como concluída.
- [x] Onboarding guiado e retomável por organização: dados, alertas iniciais, jornada padrão e autoinscrição; convites, equipes e carteira opcionais; organizações existentes não são redirecionadas. Testes de feature, revalidação de owner sob lock e fluxo E2E no Chromium.
- [ ] Templates de adoção para iniciar a configuração com diferentes perfis comerciais.
- [ ] Configuração explicável de pontuação e inatividade (pesos, bandas, nomes/cores e limites são editáveis e validados; recálculo em fila com snapshot, retomada e polling, mais apresentação no dashboard, foram implementados; auditoria administrativa central e E2E específico de fila ainda pendentes).
- [ ] Exportações CSV/XLSX autorizadas e downloads temporários privados.
- [ ] Auditoria consultável de operações comerciais e administrativas.
- [ ] Medições reprodutíveis de performance e estrutura para planos/limites sem gateway de pagamento.
- [ ] Suítes PostgreSQL, concorrência, Redis, Scheduler, Mailpit e E2E de ponta a ponta da Fase 5.

## Pendências técnicas

- A Fase 5 está em andamento. Arquivos pequenos continuam sendo confirmados na requisição; arquivos acima de 250 linhas usam Redis em blocos retomáveis de até 100 linhas após confirmação. A validação assíncrona de arquivos com mais de 250 linhas está em WIP; a situação atual está registrada no checkpoint abaixo. No PHP CLI 8.3.33, `C:\tools\php83\php.ini` carrega `ext-zip` 1.22.3; `php --ini`, `php -m` e `composer check-platform-reqs` confirmaram a configuração. `composer test` executou 269 testes em PostgreSQL: 266 passaram e três testes Redis condicionais foram pulados porque a suíte usa fila síncrona; os dois testes de importação e um de recálculo comercial foram executados separadamente com Redis real e passaram (3/3). A suíte E2E completa passou com 15 fluxos no Chromium. `npm run check`, `npm run types:check`, `npm run build`, Pint e PHPStan também passaram; o build informa apenas a ausência opcional do pacote `fontaine`. Importação de empresas agora oferece criar, atualizar, resolver manualmente e ignorar duplicidades, mantendo decisões ao revisar grupos de até 100 erros; testes cobrem alvos acessíveis, masking de CNPJ, concorrência de escopo e decisões alteradas durante análise. A importação de contatos agora oferece as mesmas decisões explícitas por grupo de pessoa, conserva vínculos anteriores, impede troca silenciosa do candidato salvo, mantém dados cifrados em Redis e serializa criação concorrente de identidade no PostgreSQL. As decisões anteriores são mantidas ao percorrer grupos com mais de 100 duplicidades; o sinal de identidade fora da carteira é evitado tratando-a como novo contato sem revelar o registro oculto. Importações de vendas agora permitem atualizar uma venda visível com o mesmo identificador externo ou ignorar; atualização preserva o autor original, revalida escopo e não duplica a linha em arquivo repetido. O teste PostgreSQL confirma atualização/cancelamento e o bloqueio de identificadores de vendas fora da carteira. O teste PostgreSQL de onboarding confirma que revogar a membership após a autorização inicial impede a mutação subsequente. Vendas confirmadas atualizam indicadores e automações comerciais, e a gravação revalida a membership atual do vendedor e da equipe. Identificadores externos estáveis evitam reimportação entre lotes; sem eles, as linhas são importadas individualmente para não descartar compras legítimas com atributos coincidentes. Confirmação repetida do mesmo lote é idempotente. O onboarding retomável por organização foi implementado para owners novos, com configuração de organização, alertas, jornada e autoinscrição; convites, equipes e carteira podem ser adiados. Ainda faltam templates de adoção, auditoria consultável, exportações, métricas de performance e estrutura mínima de planos; a validação assíncrona permanece incompleta até a checagem final descrita abaixo. A classificação genérica de colisões de CNPJ fora da carteira ainda permite um sinal residual pela contagem de linhas inválidas e precisa de uma revisão que preserve a unicidade sem revelar a empresa. A Fase 5 não atende ao critério final.

### Checkpoint WIP — validação assíncrona de importações

- **Estado:** checkpoint WIP desta implementação na branch `feature/phase-5-saas-readiness`; a validação final do bloco foi intencionalmente deixada para uma sessão posterior.
- **Implementado:** `SpreadsheetPreviewReader` suporta leitura limitada e informa quando a amostra é incompleta; a tela de prévia/mapeamento usa no máximo 11 linhas. Lotes acima de 250 linhas entram em `validation_queued`, são processados por `ValidateDataImport` no tenant correto e avançam a `validating`/`validated`; o job mantém heartbeat/progresso, staging cifrado, idempotência por tentativa, bloqueio contra sobreposição e erro seguro. `validation_failed` pode ser revalidado. O Scheduler protege o lease, recupera jobs de validação sem início e mantém arquivos de workers ativos até o fim do lease.
- **Verificações concluídas neste checkpoint:** testes focados PostgreSQL para `DataImportValidationQueueTest`, `PurgeExpiredDataImportsTest` e `DataImportTest` passaram (91 testes, 1.016 assertions); 5 testes de importação com worker Redis passaram (5/5); Pint passou; PHPStan passou com 0 erros; `npm run check` passou (115 arquivos formatados, 94 sem avisos); `npm run types:check` passou; `npm run build` passou com apenas o aviso do pacote opcional `fontaine`.
- **Pendente antes de marcar o item como concluído:** revisão independente QA/tenant (interrompida ao encerrar esta sessão), suíte completa, Chromium para a validação enfileirada e recuperação do Scheduler executada junto a Redis. A amostra HTTP para decisão é limitada por linhas de dados: um CSV muito esparso, com muitos registros em branco mas até 250 linhas preenchidas, ainda pode ser percorrido integralmente pela requisição. O job analisa até 5.000 linhas numa única execução; medir esse limite e decidir se a validação precisa de checkpoints/batches duráveis também fica para a próxima sessão.
- **Como retomar:** confirme a branch e o estado com `git status --short --branch`; leia este checkpoint e `docs/architecture.md`, `docs/database.md` e `docs/business-rules.md`; execute os testes focados `php artisan test --compact tests/Feature/DataImportValidationQueueTest.php tests/Feature/PurgeExpiredDataImportsTest.php`; para fila real, use PostgreSQL + Redis e rode `QUEUE_CONNECTION=redis php artisan test --compact tests/Feature/RedisDataImportTest.php` (no PowerShell, defina `$env:QUEUE_CONNECTION='redis'` antes do comando). Depois conclua as verificações pendentes e revise/atualize este checkpoint.
