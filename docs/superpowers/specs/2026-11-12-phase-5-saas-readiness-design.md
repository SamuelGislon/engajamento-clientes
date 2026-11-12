# Fase 5 — Adoção e preparação comercial do SaaS

## Intenção

Facilitar a adoção do CRM por organizações novas e existentes, reduzir cadastro manual com importações seguras, oferecer exportação autorizada e tornar regras comerciais e operações administrativas explicáveis. A aplicação continua em português brasileiro e preserva o isolamento por organização e equipe.

## Objetivos

- Importar CSV e XLSX de empresas, contatos, vínculos e vendas com prévia, mapeamento, validação, deduplicação, processamento retomável e relatório.
- Permitir onboarding retomável sem bloquear contas existentes.
- Melhorar a configuração de classificação e inatividade sem quebrar jornadas ou históricos já persistidos.
- Exportar dados filtrados para arquivos privados temporários e auditar operações administrativas/comerciais importantes.
- Medir consultas e cargas representativas, corrigindo problemas comprovados.
- Preparar limites e recursos de planos sem implementar cobrança ou reduzir o alcance das policies.

## Fora de escopo

- Gateway, cobrança recorrente, Stripe, Mercado Pago ou bloqueio de acesso por falta de pagamento.
- Fusão automática ambígua de pessoas ou empresas.
- Reprocessamento global de pontuação/jornadas sem operação explícita, medição e controle.
- Arquivos de importação/exportação públicos ou retenção indefinida de conteúdo bruto.
- Alteração das regras de negócio das Fases 1 a 4 sem teste de regressão e documentação.

## Abordagens consideradas

1. **Pipeline comum de importação com validadores por entidade (escolhida).** Compartilha limites, armazenamento temporário, mapeamento, resultados, tenant e idempotência; mantém regras de gravação e deduplicação específicas para cada tipo. O custo é uma pequena fundação inicial, compensada por segurança e consistência entre quatro importações.
2. **Um importador isolado por entidade.** Começa rápido, mas duplica validação de arquivo, autorização, retentativas, retenção e relatórios; aumenta o risco de diferenças de tenant e formatos.
3. **Importação síncrona direta na requisição.** Tem menos tabelas e jobs, porém não atende volumes maiores, retomada, acompanhamento, idempotência concorrente nem o controle de automações solicitado.

## Arquitetura proposta

- Usar um registro tenant-first de lote, linhas normalizadas temporárias e estados persistidos para preview, validação, confirmação, execução e resultado. O arquivo fica em armazenamento privado, com limite de tamanho/linhas e retenção definida; payloads de linha e arquivo são eliminados após a janela operacional, sem copiar dados brutos para auditoria.
- Usar `openspout/openspout` **4.32.0** como leitor/escritor streaming de CSV/XLSX. A versão 5.x exige PHP 8.4; a versão 4.32.0 suporta PHP 8.3 e tem licença MIT. CSV e XLSX passam pelo mesmo contrato de linhas. Nenhum parser XLSX manual.
- Validar organização, papel, owner, equipe, relações e status no servidor. Arquivos nunca autorizam por IDs próprios; relações são re-resolvidas dentro do tenant ativo. Opções de jornada são explícitas (`nenhuma`, padrão da organização ou template elegível) e a confirmação é idempotente.
- Separar o onboarding, as versões/configurações comerciais, exportações, downloads e auditorias por organização. Exportações usam a mesma autorização e filtros das consultas de origem, armazenamento privado e expiração.
- Manter a cobrança futura como documentação e, apenas se o modelo existente exigir, uma fundação de limites/recursos que não substitui `Policies` nem o escopo tenant.

## Restrições

- Laravel 13.42, PHP 8.3, React 19, TypeScript e Inertia 3 já instalados; confirmar versões efetivas antes de alterar dependências.
- Não adicionar dependências redundantes. O OpenSpout v4.32.0 é escolhido por suportar PHP 8.3, CSV/XLSX, streaming e licença MIT.
- Processamento por lote deve limitar memória, quantidade de linhas, tempo de retenção e efeitos colaterais; jobs devem transportar e revalidar `organization_id`.
- Não usar dados de produção em fixtures, seeds, logs, screenshots ou relatórios.
- PostgreSQL é necessário para validar concorrência real; SQLite não substitui esse aceite.

## Critérios de conclusão

Os critérios de aceite e testes das Partes 13, 14 e 16 do pedido da Fase 5 regem a implementação. Cada bloco é entregue como funcional e verificado ou permanece explicitamente pendente; não se marca requisito como concluído por estar apenas documentado ou parcialmente codificado.
