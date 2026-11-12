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
- **Dashboard**: indicadores calculados a partir de empresas, vendas, tarefas, ocorrências e inscrições persistidas.
- **Auth/Settings**: autenticação e configurações herdadas do starter kit oficial.

Equipes e relatórios hierárquicos de gestão permanecem nas fases correspondentes.

## Multi-tenancy

Uma conta de usuário se relaciona a várias organizações através de `organization_user`. A função (`owner`, `admin`, `manager` ou `salesperson`) pertence à membership, não à conta global. O registro cria atomicamente a primeira organização e sua membership `owner`.

Após autenticar, `EnsureOrganizationSelected` resolve uma organização entre as memberships do usuário e coloca seu ID no `TenantContext` da requisição. O contexto é scoped pelo container para não vazar entre requisições em workers persistentes. Modelos de domínio aplicam um global scope que falha fechado quando não há organização ativa e preenchem `organization_id` na criação. Trocas de workspace só aceitam IDs presentes nas memberships da conta.

Form Requests verificam responsáveis e vínculos no escopo da organização. Policies aplicam visibilidade de carteira: owner, admin e manager veem os registros da organização; salesperson vê somente registros ligados a empresas próprias e tarefas atribuídas a ele. Vendas, scores, ocorrências e configurações usam chaves estrangeiras compostas ou consultas tenant-scoped. A camada web é uma experiência, nunca a fronteira de segurança.

## Autenticação e autorização

O starter kit Laravel React usa Fortify e sessões Laravel. O cadastro requer nome da organização e cria conta, organização e membership em transação. Policies usam papel e ownership em cada operação. O e-mail corporativo e a identidade do usuário são globais; dados comerciais permanecem por organização.

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

## Decisões e limites atuais

- Banco compartilhado e `organization_id` em cada tabela de domínio, com escopo Laravel e validação de relações.
- As pessoas são únicas por organização e podem estar associadas a diversas empresas; funções são propriedades do vínculo empresa-pessoa.
- Última interação é derivada das atividades e não mantida em coluna duplicada.
- Vendas, pós-venda, inatividade comercial e de relacionamento, classificação e dashboard comercial estão implementados na Fase 3.
- A primeira versão mantém uma política de vendedor simples por responsável. Escopos por equipe virão na Fase 4.

## Tarefas, jornadas e notificações

`TaskController` centraliza a leitura e as ações autorizadas de tarefa. `JourneyService` mantém inscrição, ativação de etapas, conclusão e cancelamento dentro de transações; cada execução de etapa possui uma tarefa automática única. Ao cancelar inscrição, suas tarefas automáticas abertas e etapas ativas são marcadas como canceladas, mantendo o histórico. A conclusão de tarefa não cria uma interação comercial.

Os prazos são calculados como dias corridos no fuso da organização e persistidos em UTC. A próxima etapa começa a contar da conclusão da etapa anterior. Tarefas manuais permanecem independentes do ciclo de vida da jornada.

`TaskEventNotification` registra avisos no canal de banco de dados. `TaskAssignmentNotifier` enfileira e-mail de atribuição depois do commit. O Scheduler processa lembretes de tarefas e inatividade comercial por organização, com `TenantContext` ativo e deduplicação persistida. A troca de responsável reatribui tarefas automáticas de jornada e de inatividade; tarefas manuais preservam seu responsável. As telas consultam notificações e recursos usando o tenant e as policies vigentes.
