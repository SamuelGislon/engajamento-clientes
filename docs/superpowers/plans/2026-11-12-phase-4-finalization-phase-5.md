# Finalização da Fase 4 e Fase 5 — Plano de implementação

> **Para agentes de implementação:** seguir TDD em cada comportamento. Executar as tarefas em ordem; nenhum trabalho da Fase 5 começa antes de `main` conter a Fase 4.

**Objetivo:** Validar e integrar a Fase 4 com histórico preservado; depois desenvolver a Fase 5 em `feature/phase-5-saas-readiness`, publicando somente uma branch revisável.

**Arquitetura:** Finalizar a Fase 4 em seu worktree, validar PostgreSQL/Redis/Mailpit e navegador real em isolamento, então fazer merge convencional em `main`. A Fase 5 usa módulos verticais sobre os modelos, policies, contexto multi-tenant e consultas existentes, com importação em lotes, arquivos privados e auditoria minimizada.

**Stack:** Laravel 13.42, PHP 8.3, React 19, TypeScript, Inertia 3, PostgreSQL 16, Redis 7, Pest 4.7, Vite+ 1.4; Playwright Test local e OpenSpout 4.32.0 somente após instalar/verificar e atualizar os lockfiles.

**Especificação:** [2026-11-12-phase-5-saas-readiness-design.md](../specs/2026-11-12-phase-5-saas-readiness-design.md)

## Restrições globais

- Preservar commits e alterações locais; não executar `reset --hard`, `clean -fd` ou force push.
- Não tocar em dados reais, credenciais, `.env` ou banco da porta 5432; serviços de teste são isolados e identificados por projeto.
- Não implementar regra de negócio sem teste Pest/Inertia relevante escrito antes do código e observado falhando.
- Validar escopo de organização, equipe, owner e papel no servidor em rotas, jobs, importações, exportações, auditoria e downloads.
- Não instalar framework redundante; revisar versões e compatibilidade antes de dependências.
- Manter `README.md`, `AGENTS.md` apenas se necessário, `docs/architecture.md`, `docs/database.md`, `docs/business-rules.md` e `docs/roadmap.md` coerentes.
- `main` representa a Fase 4 integrada; Fase 5 permanece em branch própria, sem merge automático.
- Não deixar servidor de desenvolvimento, worker, Scheduler ou Compose de validação em execução após concluir testes.

## Foco de revisão

- XLSX malformado, arquivo ZIP com conteúdo inesperado, MIME/extensão divergente, limite de bytes/linhas e retenção após expiração.
- Cabeçalhos repetidos, planilha vazia, datas ambíguas, valores financeiros inválidos e linhas parciais precisam gerar erros localizados sem gravação inesperada.
- Deduplicação concorrente por tenant e opção escolhida; dados do arquivo nunca servem como autorização.
- Filtros de exportação e auditoria não podem ampliar escopo de vendedor, gerente ou organização por parâmetros manipulados.
- Retry/concorrência de jobs não pode duplicar registro, tarefa, notificação, e-mail, auditoria ou jornada.

---

## Etapa A — Fase 4

### Tarefa 1 — Playwright isolado

**Arquivos:** `package.json`, `package-lock.json`, `.gitignore`, `playwright.config.ts`, `tests/e2e/**`, seeder exclusivo de E2E e inicializador/limpeza do ambiente de teste.

- [x] Instalar `@playwright/test` 1.65.0 como dependência local e Chromium no cache do usuário; conferir a versão efetiva antes de configurar.
- [x] Configurar TypeScript, `webServer`, retry/trace/screenshot de falha e isolamento por arquivo SQLite exclusivo, nunca reutilizando banco local.
- [x] Criar fixtures sintéticas para owner, gestor, vendedor, equipe, empresas e tarefas, sem dados reais.
- [x] Rodar uma spec de fumaça no Chromium real e confirmar falha se a página ou servidor não iniciar.

### Tarefa 2 — Fluxos E2E da Fase 4

**Arquivos:** `tests/e2e/phase-4.spec.ts`, fixtures/seeder de E2E e componentes seletor acessíveis se estritamente necessários.

- [x] Cobrir equipe, gestor, convite e aceite, atribuição de carteira e confirmação visual.
- [x] Cobrir carteira do vendedor, interação e criação/conclusão de tarefa com persistência.
- [x] Cobrir gestor fora do seu time e vendedor fora da carteira com acesso negado.
- [x] Cobrir dashboard gerencial, filtro de período/equipe e abertura do detalhe.
- [x] Executar seis fluxos no Chromium, sem mocks de HTTP das rotas de negócio.
- [x] Cobrir convite/aceite por SMTP no Mailpit, job Redis com falha e retentativa, e efeito idempotente do comando Scheduler no PostgreSQL isolado.

### Tarefa 3 — Concorrência das Fases 2 e 3

**Arquivos:** testes de integração PostgreSQL em `tests/Feature/Journeys`, `CommercialInactivityTest`, `SaleManagementTest` e serviços/jobs tocados por correções.

- [x] Usar processos/conexões PostgreSQL independentes para disputa de mesma execução de etapa, Scheduler concorrente e criação da tarefa automática.
- [x] Cobrir geração concorrente de alerta de inatividade, compra durante reativação e cancelamento de venda durante recálculo.
- [x] Cobrir transferência de carteira durante progressão, retry de job após falha e deduplicação de notificações.
- [x] Reproduzir antes de corrigir; a correção da reativação obsoleta foi validada RED→GREEN e preserva constraints transacionais.

### Tarefa 4 — Aceite e merge da Fase 4

**Arquivos:** `.gitignore`, `README.md`, `docs/roadmap.md`, testes/fixtures da Fase 4; revisar todo o diff.

- [x] Rodar `composer test`, `composer ci:check`, `npm run check`, `npm run types:check`, `npm run build`, Playwright e suítes de concorrência.
- [x] Validar migrations/testes em Compose isolado; executar job e retentativa com worker Redis; executar comando do Scheduler e confirmar efeito/deduplicação; entregar convite sintético no Mailpit.
- [ ] Encerrar o projeto Compose de validação identificado após concluir todas as verificações; não tocar no PostgreSQL local da porta 5432.
- [ ] Auditar staged/untracked/ignorados e secrets; adicionar somente caminhos revisados.
- [ ] Criar commit descritivo, push da branch Fase 4 e integrar `main` sem força, preservando a edição idêntica do teste de contatos no worktree principal.
- [ ] Verificar hash remoto e testes do resultado integrado; sincronizar o checkout principal sem descartar alterações.

## Etapa B — Fase 5 (somente após Tarefa 4)

### Tarefa 5 — Criar branch e fundação de importações

**Arquivos:** novas classes sob `app/Domain/Imports`, `app/Models/DataImport.php`, migration tenant-first, factory, policy, requests, controller e rotas; testes em `tests/Feature/Imports`.

- [ ] Criar `feature/phase-5-saas-readiness` a partir da `main` integrada.
- [ ] Definir teste para criação autorizada de lote e negar cruzamento de tenant antes de implementar.
- [ ] Adicionar `openspout/openspout:^4.32` após confirmar extensões ZIP/XML e atualizar `composer.lock`.
- [ ] Implementar estado persistido de lote, opções de jornada, contagens e expiração; arquivo somente em storage privado e sem cópia bruta para auditoria.

### Tarefa 6 — Prévia, mapeamento, validação e deduplicação

**Arquivos:** leitores CSV/XLSX, DTOs de linha/cabeçalho, validadores por tipo, resolvers de duplicidade e páginas Inertia de importação.

- [ ] Testar CSV/XLSX real com fixtures pequenas, arquivo inválido, tamanho/linha excedidos, cabeçalhos repetidos e mapeamento incompleto.
- [ ] Implementar prévia e validação para empresas, contatos, vínculos e vendas; identificar duplicidade sem fusão automática ambígua.
- [ ] Testar CNPJ/e-mail/telefone/identificador externo, relações de empresa e vendedor pertencentes ao mesmo tenant.
- [ ] Testar vendedor tentando atualizar empresa de outra carteira e gestor tentando atualizar empresa de equipe alheia, na prévia e no job após troca/revogação da membership.
- [ ] Implementar opções por duplicidade: ignorar, atualizar apenas correspondência não ambígua ou criar novo quando permitido.

### Tarefa 7 — Confirmação, processamento e jornadas

**Arquivos:** jobs de importação por lote/chunk, serviços por entidade, página de status/resultado e limpeza agendada.

- [ ] Testar lote parcial, retry, concorrência, reprocessamento idempotente, vendas financeiras e relação cross-tenant.
- [ ] Testar dois lotes concorrentes da mesma organização que tentam incluir a mesma empresa/contato determinístico; implementar lock por identidade normalizada ou constraint tenant-first sem fusão ambígua.
- [ ] Implementar gravação por transação de linha e chave única por lote/linha; revalidar tenant, carteira, equipe, membership e policy no job, além de limitar memória e controlar estados.
- [ ] Testar `nenhuma`, template selecionado e padrão da organização; evitar notificações/e-mails em massa e gerar jornada/tarefa apenas conforme opção confirmada.
- [ ] Remover arquivo e payload temporário após janela configurada e manter apenas resumo operacional mínimo.

### Tarefa 8 — Onboarding retomável

**Arquivos:** persistência de onboarding por organização, policy/service, controllers/requests, páginas Inertia e testes em `tests/Feature/Onboarding`.

- [ ] Testar cadastro novo, membership existente, interrupção, retomada, conclusão, configuração opcional de equipe e permissões.
- [ ] Implementar etapas de organização, equipe, carteira, jornadas/alertas e início de trabalho sem redirecionar indefinidamente usuários existentes.
- [ ] Criar templates iniciais por serviço idempotente usando o motor de jornadas existente; não duplicar progressão.

### Tarefa 9 — Regras comerciais configuráveis e explicáveis

**Arquivos:** requests/policy/controller/pages comerciais, migrations de histórico/versionamento somente se exigidas por comportamento demonstrado, testes Pest.

- [ ] Testar limites/pesos, intervalos sobrepostos, escalas, faixas, cores, frequência e recomendações.
- [ ] Testar limites de inatividade, cooldown, jornada de reativação e autorização.
- [ ] Implementar validação server-side e explicar o efeito antes de salvar; preservar score history, alertas, jornadas e tarefas existentes.
- [ ] Fazer recálculo explícito e observável, sem varredura global implícita; registrar versão/snapshot apenas quando necessário para explicar resultados.

### Tarefa 10 — Exportações autorizadas

**Arquivos:** export jobs/requests/policies/controllers, armazenamento privado, rotas assinadas com autorização/expiração, UI e testes em `tests/Feature/Exports`.

- [ ] Testar filtros, tenant, carteira, equipe, gerente/vendedor, IDOR de download e expiração.
- [ ] Implementar CSV e XLSX de empresas, contatos, tarefas, atividades, vendas, classificações e relatórios usando consultas e escopos existentes.
- [ ] Neutralizar conteúdo que possa ser fórmula de planilha e formatar datas/moeda de forma consistente.
- [ ] Limpar artefatos expirados e não publicar links/buckets públicos.

### Tarefa 11 — Auditoria consultável

**Arquivos:** migration tenant-first, modelo, serviço de eventos, policy, filtros/paginação, tela e testes em `tests/Feature/Audit`.

- [ ] Testar criação/alteração de empresa, vínculos, carteira, permissões, convites, jornadas/regras, venda, importação/exportação e ausência de secrets.
- [ ] Implementar ator, organização, recurso, evento, hora e diff sanitizado; excluir tokens, senhas, conteúdo bruto e dados desnecessários.
- [ ] Restringir consulta por papel/escopo e evitar duplicar históricos existentes de jornada, venda ou carteira.

### Tarefa 12 — Performance, planos e experiência

**Arquivos:** consultas sob carga, migrations de índices somente com query demonstrada, testes de carga reprodutíveis, componentes afetados e documentação.

- [ ] Fixar fixtures reprodutíveis para 10 organizações, 100 vendedores, 10.000 empresas, 30.000 contatos, 100.000 interações e milhares de tarefas/vendas sem executar toda a carga em toda suíte.
- [ ] Medir listagens, busca, dashboards, relatórios, importação e processamento de jornada; guardar comandos, ambiente, linhas e latência real.
- [ ] Corrigir N+1/consulta ou índice apenas a partir de evidência; testar isolamento dos agregados.
- [ ] Documentar planos, recursos, limites, trial e estados de assinatura sem gateway; qualquer bloqueio futuro permanece além de policies.
- [ ] Revisar teclado, contraste, estados vazios/erro/sucesso e responsividade nos fluxos novos.

### Tarefa 13 — E2E dos fluxos da Fase 5

**Arquivos:** specs sob `tests/e2e/phase-5-*.spec.ts`, fixtures isoladas, inicialização de banco/serviços e suporte de mailbox.

- [ ] Em navegador Chromium real e dados sintéticos, cobrir onboarding, CSV, dados importados, jornada/tarefa, interação, venda, classificação, dashboard gerencial, exportação e negação de acesso.
- [ ] Executar com banco e serviços isolados, sem simular respostas HTTP de negócio; conferir persistência e download autorizado.
- [ ] Guardar screenshots/traces somente nos diretórios Playwright ignorados e confirmar ausência desses artefatos no diff.

### Tarefa 14 — Revisão final e publicação da Fase 5

**Arquivos:** `README.md`, `docs/architecture.md`, `docs/database.md`, `docs/business-rules.md`, `docs/roadmap.md`, testes e diff final.

- [ ] Rodar suítes Pest/SQLite e PostgreSQL, PHPStan, Pint, lint, TypeScript, build, E2E das Fases 4 e 5, Redis, Scheduler e Mailpit.
- [ ] Rodar revisão de tenancy/segurança e revisar migration, concorrência, queries, retenção e dados sensíveis.
- [ ] Atualizar roadmap só para comportamentos implementados e verificados; listar limites reais.
- [ ] Revisar staged/ignored/secret scan, criar commits organizados e push somente para `feature/phase-5-saas-readiness`.
- [ ] Preparar resumo de PR; não fazer merge da Fase 5.

## Validação de fase e continuidade

- A Fase 4 não é considerada integrada até commit, push, merge não forçado e verificação de `origin/main`.
- A Fase 5 é concluída apenas quando as Tarefas 5–13 e os critérios de aceite do pedido são verificados. Se o trabalho exceder esta execução, registrar commits/blocos verdes e pendências na branch e no relatório sem marcar a Fase 5 como completa.
