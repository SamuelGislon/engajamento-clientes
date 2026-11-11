---
name: tenant-boundary-audit
description: Audite isolamento multi-tenant e autorização ao alterar consultas, policies, relações, jobs, imports, exports ou notificações com dados de organizações.
---

# Auditoria de fronteiras entre tenants

1. Identifique tenant ativo, membership e papel real do usuário na rota/comando/job.
2. Trace origem de cada ID recebido por request; nunca confie apenas em ID de cliente.
3. Revise queries, scopes, relacionamentos e eager loading: `organization_id` deve ser validado no servidor.
4. Valide relações cruzadas (ex.: `contact_id`, `company_id`, `assignee_id`, `journey_id`) pertencentes à organização correta.
5. Verifique autorizações de leitura, escrita, exclusão, dashboards agregados, importações, exportações, filas e notificações.
6. Em jobs, transporte referência explícita de tenant e revalide o escopo ao executar; não confie em contexto global de request.
7. Crie ou ajuste testes com Organização A, Organização B, gerente e vendedor; tente acesso por ID, troca de vínculo e agregações.
8. Relate riscos por severidade e caminhos de código; não substitua verificação por `organization_id` apenas no frontend.

Saída: alterações pontuais ou relatório de riscos + testes executados e suas evidências. Não expandir automaticamente para auditoria do repositório inteiro.
