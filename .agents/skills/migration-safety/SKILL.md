---
name: migration-safety
description: Planeje e valide migrations PostgreSQL ao criar ou modificar tabelas, constraints, relacionamentos e índices do SaaS.
---

# Migrações PostgreSQL seguras

1. Inspecione schema, convenções, volume aproximado de dados e consultas que serão afetadas.
2. Defina foreign keys, índices compostos adequados e regras de unicidade por organização.
3. Revise nulabilidade, defaults, constraints e possíveis registros existentes incompatíveis.
4. Para alterações arriscadas, proponha migração em etapas (expand/backfill/contract), sem apagar dados por padrão.
5. Verifique impacto de locks, performance e rollback; não pressupor que DDL PostgreSQL é sempre sem risco.
6. Teste migração e consultas relacionadas em banco descartável, com dados de tenants diferentes.
7. Documente decisão, riscos, plano de execução e o que não foi verificado.

Nunca aplicar migração destrutiva em produção sem autorização explícita.
