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
- `sales`: compras manuais com `decimal(14,2)`, moeda BRL, vendedor, autor, identificador externo opcional, timestamp UTC, estado confirmado/cancelado e metadados de cancelamento.
- `commercial_settings`: uma configuração por organização para limites de inatividade, repetição, frequência por faixa, regras de pontuação e modelos de jornada selecionados.
- `customer_scores`: composição atual do score por empresa, critérios manuais opcionais, completude, faixa e horário de cálculo.
- `customer_score_histories`: snapshots auditáveis da composição, faixa, justificativa e data de alteração.
- `commercial_alert_occurrences`: ocorrência idempotente de inatividade comercial ou de relacionamento, com chave, período, motivo, tarefa e contato recomendados, estado e resolução.
- `notifications`: notificações Laravel por usuário; o `organization_id` fica no payload para filtrar cada workspace sem expor avisos de outros tenants.

## Integridade e isolamento

Todas as tabelas comerciais possuem `organization_id`. Os relacionamentos são filtrados pelo tenant ativo e validados também na entrada. Índices combinados começam por `organization_id` para consultas da carteira. `company_contacts` possui unicidade por organização, empresa e contato. `activities` referencia empresa e contato e impede associações cruzadas na camada de aplicação.

`organization_user` usa unicidade em `(organization_id, user_id)`. O papel está nessa associação para evitar que alternar workspace altere privilégios de outra organização. Tarefas e jornadas usam chaves estrangeiras compostas com `organization_id`, índices tenant-first e vínculos que impedem empresa, contato, usuário, modelo ou etapa de outro tenant. Uma inscrição ativa ou pausada é única por empresa e modelo. Uma execução possui no máximo uma tarefa gerada. Um template ativo padrão é único por organização.

Vendas têm chaves compostas para empresa, vendedor, criador e usuário que cancelou; `(organization_id, external_id)` é único quando há identificador externo. Configurações têm unicidade por organização e chaves compostas para os modelos pós-venda e reativação selecionados. Score atual é único por empresa/organização. Histórico, ocorrências, tarefas e contatos recomendados permanecem vinculados ao mesmo tenant. Ocorrências usam unicidade em `(organization_id, company_id, type, occurrence_key)` para deduplicar reprocessamentos e concorrência.

## Relacionamentos

- Organization N:N User (membership).
- Organization 1:N Company, Contact, Activity, Task e JourneyTemplate.
- User 1:N Company responsável e Activity autor.
- Company N:N Contact via `company_contacts`.
- Company 1:N Activity, Task e JourneyEnrollment; Contact 1:N Activity e Task.
- JourneyTemplate 1:N JourneyStep e JourneyEnrollment; JourneyEnrollment 1:N JourneyStepExecution e Task.
- JourneyStep 1:N JourneyStepExecution; JourneyStepExecution 1:1 Task automática.
- Organization 1:N Sale, CommercialSetting, CustomerScore, CustomerScoreHistory e CommercialAlertOccurrence.
- Company 1:N Sale, CustomerScore, CustomerScoreHistory e CommercialAlertOccurrence; ocorrências podem apontar para uma tarefa e um contato recomendado.
- CommercialSetting N:1 JourneyTemplate para os modelos pós-venda e reativação opcionais.

## Atualização dos dados derivados

Totais, ticket médio, frequência de compra, última compra e indicadores do dashboard são agregados das vendas confirmadas; vendas canceladas permanecem no histórico e são excluídas dos cálculos. Score atual é recalculado quando vendas ou critérios manuais mudam. A janela dos critérios automáticos cobre os últimos 12 meses. As ocorrências guardam snapshots do motivo e tarefa para auditoria; a condição atual é reavaliada pelo Scheduler e após venda, cancelamento ou interação relevante.

O seeder de demonstração contém somente empresas e dados fictícios e só executa nos ambientes `local` e `testing`. Usa identificadores `demo-*` para garantir que reexecuções não dupliquem compras.

Cada tarefa tem marcadores UTC distintos para o aviso de proximidade (janela de 24 horas) e o aviso de atraso. O Scheduler protege atualização dos marcadores e gravação da notificação com bloqueio de linha e transação. O vencimento de cada etapa mantém UTC no banco, após cálculo de dias corridos no fuso da organização.
