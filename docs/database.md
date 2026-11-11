# Banco de dados

## Entidades implementadas

- `users`: identidade global de autenticação.
- `organizations`: workspace SaaS e configurações básicas.
- `organization_user`: membership com função por workspace; uma pessoa pode ter memberships independentes.
- `companies`: empresas-clientes ou prospectos, com tenant, situação e responsável comercial opcional.
- `contacts`: profissionais por organização, sem exigir CNPJ ou dados pessoais não pertinentes.
- `company_contacts`: vínculo N:N, múltiplas responsabilidades comerciais, contato principal e observação do vínculo.
- `activities`: interações manuais ordenadas por data, autor, empresa e contato opcional.
- `tasks`: tarefas comerciais, responsável, vencimento, estado, origem e ligação opcional com empresa, contato e etapa de jornada.
- `journey_templates`: modelo configurável de prospecção, com indicação do padrão da organização.
- `journey_steps`: etapas ordenadas, atraso em dias e dados da tarefa que cada etapa cria.
- `journey_enrollments`: inscrição de empresa em modelo, estado, responsável e datas de ciclo de vida.
- `journey_step_executions`: execução única por inscrição e etapa, com vencimento planejado e conclusão.
- `notifications`: notificações Laravel por usuário; o `organization_id` fica no payload para filtrar cada workspace sem expor avisos de outros tenants.

## Integridade e isolamento

Todas as tabelas comerciais possuem `organization_id`. Os relacionamentos são filtrados pelo tenant ativo e validados também na entrada. Índices combinados começam por `organization_id` para consultas da carteira. `company_contacts` possui unicidade por organização, empresa e contato. `activities` referencia empresa e contato e impede associações cruzadas na camada de aplicação.

`organization_user` usa unicidade em `(organization_id, user_id)`. O papel está nessa associação para evitar que alternar workspace altere privilégios de outra organização. Tarefas e jornadas usam chaves estrangeiras compostas com `organization_id`, índices tenant-first e vínculos que impedem empresa, contato, usuário, modelo ou etapa de outro tenant. Uma inscrição ativa ou pausada é única por empresa e modelo. Uma execução possui no máximo uma tarefa gerada. Um template ativo padrão é único por organização.

## Relacionamentos

- Organization N:N User (membership).
- Organization 1:N Company, Contact, Activity, Task e JourneyTemplate.
- User 1:N Company responsável e Activity autor.
- Company N:N Contact via `company_contacts`.
- Company 1:N Activity, Task e JourneyEnrollment; Contact 1:N Activity e Task.
- JourneyTemplate 1:N JourneyStep e JourneyEnrollment; JourneyEnrollment 1:N JourneyStepExecution e Task.
- JourneyStep 1:N JourneyStepExecution; JourneyStepExecution 1:1 Task automática.

## Evolução

As fases seguintes acrescentarão `teams`, `sales`, regras de inatividade e histórico de classificação, com `organization_id` e índices próprios. Totais de vendas, último contato e status de inatividade serão calculados de dados de origem ou mantidos por jobs idempotentes com rastreabilidade, nunca editados manualmente como fatos independentes.

Cada tarefa tem marcadores UTC distintos para o aviso de proximidade (janela de 24 horas) e o aviso de atraso. O Scheduler protege atualização dos marcadores e gravação da notificação com bloqueio de linha e transação. O vencimento de cada etapa mantém UTC no banco, após cálculo de dias corridos no fuso da organização.
