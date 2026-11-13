# Arquitetura

## Resumo

Aplicação monolítica modular em Laravel, organizada por domínios e servida por Inertia. Controllers recebem requests validados, policies autorizam ações e modelos Eloquent persistem os dados no PostgreSQL. React cuida das telas e não decide permissões nem escopo de dados.

## Módulos

- **Organizations**: workspaces e memberships com função por organização.
- **Companies**: carteira de empresas e responsáveis comerciais.
- **Contacts**: pessoas e seus vínculos comerciais com uma ou mais empresas.
- **Activities**: histórico manual de interações por empresa e, opcionalmente, por contato.
- **Tasks**: tarefas comerciais manuais e tarefas geradas por jornadas, com central filtrada no servidor.
- **Journeys**: modelos, etapas configuráveis, inscrições, execução idempotente e progresso transacional.
- **Notifications**: avisos internos isolados por organização e e-mail de atribuição enviado pela fila após commit.
- **Sales**: registro manual e auditável de vendas confirmadas ou canceladas, com consultas agregadas por empresa e período.
- **Commercial intelligence**: pontuação, histórico de faixas, ocorrências de inatividade, tarefas acionáveis e configurações por organização.
- **Teams**: equipes, memberships de gestores/vendedores, escopo comercial centralizado, transferências auditáveis de carteira e convites.
- **Reports**: agregações paginadas de atividades, relacionamento e vendas, com filtros de período/equipe/vendedor sob o mesmo escopo comercial.
- **Imports (Fase 5 parcial)**: lotes privados de CSV/XLSX, prévia OpenSpout, mapeamento e validação de empresas, contatos/vínculos e vendas históricas, confirmação transacional/idempotente e limpeza agendada. Contatos podem se associar a várias empresas acessíveis. Vendas exigem empresa e vendedor autorizados, e reutilizam os recálculos comerciais da aplicação. A entrega atual continua síncrona e limitada a 5.000 linhas por arquivo; escolhas explícitas de resolução, jornadas selecionáveis e execução em fila permanecem pendentes.
- **Dashboard**: indicadores calculados a partir de empresas, vendas, tarefas, ocorrências e inscrições persistidas; gestores recebem desempenho das equipes que administram.
- **Auth/Settings**: autenticação e configurações herdadas do starter kit oficial.

Equipes e relatórios hierárquicos de gestão permanecem nas fases correspondentes.

## Multi-tenancy

Uma conta de usuário se relaciona a várias organizações através de `organization_user`. A função (`owner`, `admin`, `manager` ou `salesperson`) pertence à membership, não à conta global. O registro cria atomicamente a primeira organização e sua membership `owner`.

Após autenticar, `EnsureOrganizationSelected` resolve uma organização entre as memberships do usuário e coloca seu ID no `TenantContext` da requisição. O contexto é scoped pelo container para não vazar entre requisições em workers persistentes. Modelos de domínio aplicam um global scope que falha fechado quando não há organização ativa e preenchem `organization_id` na criação. Trocas de workspace só aceitam IDs presentes nas memberships da conta.

Form Requests verificam responsáveis e vínculos no escopo da organização. Policies e `CommercialAccessScope` aplicam visibilidade de carteira: owner/admin veem os registros da organização; manager vê empresas e dados comerciais das equipes ativas que administra; salesperson vê somente suas empresas e tarefas atribuídas a si. Vendedores mantêm leitura das próprias vendas históricas, mas edição e cancelamento exigem que a empresa ainda esteja em sua carteira atual. Pertencer à mesma equipe não compartilha a carteira entre vendedores. Vendas, scores, ocorrências e configurações usam chaves estrangeiras compostas ou consultas tenant-scoped. A camada web é uma experiência, nunca a fronteira de segurança.

## Autenticação e autorização

O starter kit Laravel React usa Fortify e sessões Laravel. O cadastro comum cria conta, organização e membership owner em transação. Convites concedem inicialmente apenas manager ou salesperson; guardam hash de token com uso único e expiração, exigem e-mail verificado correspondente e são aceitos em transação. A conta global permanece independente das memberships em cada organização.

## Organização do código

- `app/Models`: entidades e relações Eloquent.
- `app/Domain`: contexto de tenancy e serviços de aplicação.
- `app/Http/Controllers`: endpoints Inertia.
- `app/Http/Requests`: validação e autorização de entrada.
- `app/Policies`: autorização de domínio.
- `database/migrations`, `factories`, `seeders`: persistência e dados locais.
- `resources/js/pages`, `components`, `layouts`: interface React.

## Infraestrutura

No desenvolvimento local, Docker Compose fornece PostgreSQL 16, Redis 7 e Mailpit. Laravel, Vite e os processos de desenvolvimento rodam no Windows via Composer. A fila usa a conexão configurada pelo Laravel; e-mails de atribuição são enfileirados após commit. O comando `engagement:notify-task-deadlines` roda a cada 15 minutos pelo Scheduler e usa bloqueio persistente nas tarefas para não repetir avisos.

## Inteligência comercial

`SaleController` grava compras em transação, interpreta datas no fuso da organização e persiste timestamps em UTC. `SalesMetrics` calcula total, quantidade, ticket médio e última compra apenas a partir de vendas confirmadas. Cancelamentos preservam a linha e seus metadados; score e inatividade são reavaliados.

`CommercialInactivityService` processa organizações e empresas em lotes. Cada ocorrência possui chave estável única por tenant, empresa, tipo e período de repetição. O Scheduler roda a cada 15 minutos e pode ser reexecutado; vendas e interações resolvem ocorrências que perderam a justificativa. Vendas inexistentes não produzem inatividade comercial.

Quando configurada, uma jornada do tipo `reactivation` e gatilho `sales_inactivity` usa o `JourneyService` existente. Uma ocorrência comercial não se associa a tarefas de prospecção ou pós-venda; a ocorrência de relacionamento só reutiliza tarefa vencida ou para hoje de outra jornada, sem compartilhar tarefas de reativação. Sem modelo de reativação selecionado, a ocorrência cria uma tarefa automática independente. Quando uma compra resolve uma ocorrência vinculada à jornada de reativação, a inscrição e as etapas ainda ativas são canceladas junto com as tarefas automáticas abertas, preservando o histórico. Uma primeira venda confirmada pode encerrar a prospecção e iniciar o modelo pós-venda selecionado (`post_sale` / `first_sale`).

`CustomerScoreService` agrega vendas confirmadas dos últimos 12 meses, aplica as faixas de volume e frequência da organização, soma os critérios manuais e grava histórico somente quando a composição muda. Critérios manuais ausentes deixam a classificação incompleta, em vez de pontuar zero. Os valores padrão e fórmulas estão documentados em `docs/business-rules.md`.

## Equipes, carteiras e histórico

`CommercialAccessScope` resolve equipes e vendedores administrados. A empresa tem uma equipe principal explícita e um vendedor responsável opcional; o vendedor precisa ter membership ativa nessa equipe. Owners/admins distribuem carteiras na organização. Managers só transferem empresas entre equipes que administram. A alteração de responsável registra antes/depois, ator, motivo e data; tarefas automáticas abertas acompanham a transferência segundo o serviço existente, enquanto tarefas manuais e histórico concluído permanecem.

Vendas novas gravam `team_id_at_sale` para preservar a atribuição histórica da equipe. Vendas antigas sem snapshot são agregadas usando a equipe atual da empresa; essa compatibilidade deve ser considerada ao interpretar relatórios históricos. Relatórios usam agregações SQL e paginação e respeitam os mesmos scopes dos endpoints comerciais.

## Decisões e limites atuais

- Banco compartilhado e `organization_id` em cada tabela de domínio, com escopo Laravel e validação de relações.
- As pessoas são únicas por organização e podem estar associadas a diversas empresas; funções são propriedades do vínculo empresa-pessoa.
- Última interação é derivada das atividades e não mantida em coluna duplicada.
- Vendas, pós-venda, inatividade comercial e de relacionamento, classificação e dashboard comercial foram implementados na Fase 3.
- O escopo por equipe e os relatórios gerenciais pertencem à Fase 4. Equipes não criam compartilhamento geral entre vendedores.

## Tarefas, jornadas e notificações

`TaskController` centraliza a leitura e as ações autorizadas de tarefa. `JourneyService` mantém inscrição, ativação de etapas, conclusão e cancelamento dentro de transações; cada execução de etapa possui uma tarefa automática única. Ao cancelar inscrição, suas tarefas automáticas abertas e etapas ativas são marcadas como canceladas, mantendo o histórico. A conclusão de tarefa não cria uma interação comercial.

Os prazos são calculados como dias corridos no fuso da organização e persistidos em UTC. A próxima etapa começa a contar da conclusão da etapa anterior. Tarefas manuais permanecem independentes do ciclo de vida da jornada.

`TaskEventNotification` registra avisos no canal de banco de dados. `TaskAssignmentNotifier` enfileira e-mail de atribuição depois do commit. O Scheduler processa lembretes de tarefas e inatividade comercial por organização, com `TenantContext` ativo e deduplicação persistida. A troca de responsável reatribui tarefas automáticas de jornada e de inatividade; tarefas manuais preservam seu responsável. As telas consultam notificações e recursos usando o tenant e as policies vigentes.

## Importação de empresas, contatos e vendas (Fase 5 parcial)

`DataImportController` mantém os lotes no tenant ativo e armazena o arquivo em disco privado. `SpreadsheetPreviewReader` usa OpenSpout para CSV e XLSX, detecta separadores vírgula e ponto e vírgula em CSV e limita arquivos a 10 MB, 100 colunas e 5.000 linhas. O usuário mapeia colunas e valida as linhas antes de confirmar. A confirmação bloqueia o lote e a organização em uma transação, repete a autorização depois do lock e bloqueia equipes/memberships relevantes durante a revalidação e gravação; alterações concorrentes de acesso são serializadas com a confirmação. O arquivo é revalidado e somente linhas válidas são processadas; repetir a confirmação não duplica o lote. CNPJ válido e nome normalizado com cidade/UF identificam duplicidades de empresas para ignorar; linhas sem contexto suficiente não são fundidas automaticamente. Os registros existentes não são atualizados nesta entrega.

Owner/admin podem importar na organização; manager precisa selecionar uma equipe ativa que administra; salesperson só pode escolher equipe da própria membership e as empresas novas ficam atribuídas a si. Na importação de contatos, cada linha deve apontar para uma empresa existente e visível ao usuário no tenant ativo, por CNPJ ou nome exato sem ambiguidade. Linhas da mesma pessoa com e-mail igual ou nome e telefone iguais são agrupadas quando os valores pessoais preenchidos são compatíveis; um contato pode gerar vários vínculos e papéis/contato principal são gravados no vínculo. Conflitos e referências inacessíveis são reportados genericamente para revisão, sem fundir ou atualizar registros. A confirmação trava empresas, equipes e memberships que autorizam os vínculos do manager antes de gravar.

Na importação de vendas, cada linha deve referenciar empresa acessível por CNPJ ou nome exato e vendedor ativo da organização. Vendedores só podem importar para si; gestores só podem selecionar vendedores da equipe que administram; outros papéis seguem a regra de pertencimento do vendedor à equipe principal da empresa. Valores aceitam ponto decimal ou notação brasileira com vírgula e respeitam `decimal(14,2)`. Datas aceitas são ano-mês-dia, data e hora local sem offset, ISO 8601 com frações de segundo, ou dia/mês/ano somente quando não ambígua. Frações ISO são normalizadas à precisão de microssegundos disponível; datas sem fuso, inclusive células XLSX, usam o fuso da organização; timestamps com offset preservam o instante; tudo é persistido em UTC. `confirmed` atualiza cliente, score e inatividade; `cancelled` preserva autor/data de cancelamento e recalcula inatividade sem contar nos agregados. Identificadores externos repetidos são deduplicados no tenant. Sem identificador externo, linhas de mesmo conteúdo são preservadas como transações distintas, pois os atributos comerciais não demonstram identidade de uma venda; reimportar um novo lote sem ID pode duplicar uma venda. Conflitos inacessíveis recebem mensagem genérica. A importação atual não oferece atualização ou resolução manual e não inicia jornadas pós-venda por padrão.

A autorização usa contexto de tenant, policy do lote, escopo da carteira e chaves estrangeiras compostas. A limpeza agendada remove o arquivo privado e os dados temporários após 24 horas, mantendo apenas contagens e erros sem valores de linha. A confirmação segue síncrona e limitada a 5.000 linhas; lotes grandes ainda dependem do processamento em fila.
