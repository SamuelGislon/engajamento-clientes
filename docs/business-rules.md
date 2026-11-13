# Regras de negócio implementadas e planejadas

## Implementadas na Fase 1

- O cadastro cria uma organização para o usuário e concede membership `owner` na mesma transação.
- Um usuário pode pertencer a várias organizações; o workspace ativo precisa estar entre suas memberships.
- Os papéis válidos são `owner`, `admin`, `manager` e `salesperson`.
- Na base inicial, owner, admin e manager consultavam a carteira completa; a Fase 4 restringe o manager às equipes que administra. Salesperson consulta apenas empresas atribuídas a si.
- Um contato pertence a uma única organização, mas pode se relacionar com várias empresas daquela organização.
- Papéis profissionais e contato principal pertencem ao vínculo empresa-contato.
- Interações são registradas manualmente, atribuídas ao usuário autenticado e ligadas a uma empresa; contato é opcional.
- A data do último contato é derivada do histórico, sem escrita duplicada na empresa.

## Implementadas na Fase 2

- Tarefas possuem responsável, autor, vencimento, prioridade, estado e origem manual ou automática. Relações de empresa, contato e usuários são validadas dentro da organização.
- Até a Fase 3, owner, admin e manager mantinham o escopo da organização; na Fase 4, manager passa a consultar tarefas comerciais de suas equipes. Salesperson só consulta tarefas atribuídas a si em empresas da própria carteira.
- Concluir uma tarefa registra conclusão e observações, sem criar interação fictícia.
- Criar prospecto, inscrição padrão e primeira tarefa é atômico. A autoinscrição fica ligada por padrão e pode ser alterada por owner, admin ou manager.
- Inscrições ativas e pausadas não podem se duplicar para a mesma empresa e modelo. Jornadas concluídas ou canceladas podem ser iniciadas novamente.
- A jornada padrão começa com atrasos de 1, 7, 15 e 30 dias. O primeiro atraso conta da inscrição; cada atraso seguinte conta da conclusão anterior. Dias são corridos no fuso da organização; horários são armazenados em UTC.
- A conclusão da etapa ativa gera a próxima tarefa uma única vez. Adiar não avança a jornada. Pausar preserva as tarefas e impede ativar a próxima etapa até retomar. Cancelar fecha tarefas automáticas em aberto e preserva histórico e tarefas manuais.
- A alteração do responsável da empresa reatribui as tarefas automáticas abertas e inscrições ativas ou pausadas. Tarefas manuais e concluídas preservam seu responsável e histórico.
- Atribuição e ativação de etapa, conclusão de jornada, proximidade de vencimento e atraso geram notificações internas. Avisos de proximidade e atraso são deduplicados por marcadores persistidos. E-mail é enviado na atribuição após o commit.
- Notificações são filtradas pelo workspace atual; abrir um aviso verifica a policy do recurso e marca como lido.

## Implementadas na Fase 3

### Vendas

- Vendas são registradas manualmente para uma empresa da organização e um vendedor membro dela. Vendedores só podem registrar vendas para sua própria carteira e atribuí-las a si; papéis de gestão seguem o escopo atual da organização.
- Vendedores podem consultar vendas históricas que registraram, mas só podem editar ou cancelar uma venda enquanto a empresa permanecer em sua carteira atual. Managers seguem o escopo atual das equipes; owner/admin seguem o escopo da organização.
- Valores são positivos, armazenados em `decimal(14,2)` e a moeda inicial é BRL. Datas são interpretadas no fuso da organização, não podem estar no futuro e são persistidas em UTC.
- Somente vendas confirmadas entram no faturamento, ticket médio, frequência e última compra. Cancelar preserva a linha, autor do cancelamento e data; não apaga histórico e não conta em agregados.
- O primeiro registro confirmado de um prospecto muda sua situação para cliente, cancela a inscrição ativa/pausada de prospecção e inicia uma jornada pós-venda ativa selecionada pela organização, se houver. Cancelar a venda não reverte automaticamente a situação nem reabre jornadas históricas.

### Inatividade e reativação

- Inatividade comercial exige ao menos uma venda confirmada. Prospectos sem compras são exibidos separadamente e nunca recebem alerta por tempo desde o cadastro.
- O limite inicial é 90 dias sem compra confirmada; inatividade de relacionamento começa após 30 dias sem uma interação real registrada. Concluir uma tarefa não cria interação. Os valores são configuráveis por organização.
- Dias são dias corridos do calendário no fuso da organização. Timestamps permanecem em UTC.
- A ocorrência salva o motivo e contato recomendado. A recomendação segue uma ordem estável: responsável por orçamentos, comprador, decisor e contato principal; dentro da mesma prioridade, vence o menor ID. Sem candidato, o sistema informa que nenhum profissional específico foi definido.
- Ao ultrapassar o limite, é criada tarefa automática atribuída ao responsável. Ocorrências comerciais só podem reutilizar tarefas abertas de uma jornada `reactivation` / `sales_inactivity`; tarefas de prospecção ou pós-venda não substituem a ação de reativação. Se a organização selecionou esse modelo, o motor existente inicia ou reutiliza a inscrição e associa sua tarefa; sem modelo, cria-se tarefa avulsa. Para inatividade de relacionamento, uma tarefa de outra jornada só é reutilizada quando já está vencida ou vence hoje; tarefas futuras ou de reativação não substituem o contato que precisa ser feito nem compartilham seu ciclo de vida.
- O Scheduler roda a cada 15 minutos. Uma chave única por tenant/empresa/tipo/ocorrência evita duplicatas em reexecuções e concorrência. A conclusão ou cancelamento da tarefa fecha a ocorrência e começa a contar o intervalo de repetição configurado (60 dias inicialmente). Uma nova tarefa só pode surgir após esse prazo enquanto a mesma condição persistir. Um marco novo de compra/interação encerra a ocorrência anterior.
- Uma compra confirmada encerra imediatamente ocorrências comerciais sem justificativa e cancela tarefas automáticas ainda abertas que não sejam mais necessárias. Quando a tarefa pertence a uma jornada de reativação, a inscrição também é cancelada e suas etapas ativas recebem estado cancelado; tarefas manuais e o histórico concluído são preservados. Uma tarefa de reativação cancelada manualmente encerra sua inscrição na próxima reavaliação do Scheduler. Jornadas canceladas podem ser iniciadas novamente quando surgir um novo período de inatividade. Cancelar ou editar uma venda reavalia a última compra confirmada e a condição de inatividade.
- A troca de responsável reatribui tarefas automáticas abertas de jornada e de ocorrência. Tarefas manuais e tarefas concluídas/canceladas preservam responsável e histórico.

### Classificação comercial

- A pontuação usa compras confirmadas nos últimos 12 meses. Pesos padrão: volume 40, frequência 20, potencial 20 e relacionamento 20; a administração pode personalizá-los, e a soma precisa ser 100. Os pontos das faixas de volume/frequência e os critérios manuais não podem ultrapassar seus respectivos pesos.
- Faixas padrão de volume: R$ 0 = 0 pontos; até R$ 5.000 = 8; até R$ 25.000 = 16; até R$ 75.000 = 24; até R$ 200.000 = 32; acima = 40. Os limites precisam crescer, somente a última faixa pode ser ilimitada, e os pontos não podem diminuir entre faixas.
- Frequência padrão: 0 compras = 0 pontos; 1 = 5; 2 = 10; 3 = 15; 4 ou mais = 20. O intervalo conta as compras confirmadas na mesma janela de 12 meses; limites mínimos e pontos precisam crescer sem exceder o peso.
- Potencial comercial e facilidade de acesso/relacionamento são avaliações manuais inteiras limitadas pelo peso configurado. Campo desconhecido permanece nulo; a faixa final fica incompleta até ambos terem valor.
- Faixas padrão sem sobreposição: D 0–24, C 25–49, B 50–74 e A 75–100. A organização pode personalizar nome, cor e limites, que devem cobrir de 0 a 100 sem lacunas ou sobreposições.
- A mudança em pesos, bandas ou limites cria uma operação de fila com snapshot das regras e recalcula somente empresas que já têm classificação, em lotes transacionais de até 50. O cursor e o progresso ficam persistidos; falhas preservam lotes concluídos e permitem retomada sem repeti-los. Alterações salvas durante um recálculo substituem a operação anterior e iniciam outra para todas as classificações sob as regras novas. Critérios manuais são limitados aos novos pesos, e novo histórico é gravado somente quando o resultado muda. Snapshots anteriores permanecem preservados. A interface informa a atualização e confirma antes de reduzir pesos manuais. Mudanças apenas de nomes/cores e regras de inatividade não recalculam pontuação nem alteram jornadas ativas ou tarefas existentes.
- O histórico registra a primeira composição e cada mudança de critério, total, completude ou faixa, além do nome e da cor vigentes naquele instante. Recalcular sem mudança não cria outro snapshot; alterar somente nome/cor não cria avaliação histórica.
- A tela de classificação filtra faixa, responsável, segmento, situação e período de compra; vendedores veem apenas a própria carteira.

### Demonstração local

O seeder apresenta os cenários fictícios de cliente estratégico, cliente inativo, prospecto sem compras, cliente reativado e cliente em evolução. Ele é restrito a `local` e `testing`, usa datas relativas e é idempotente. Nunca roda em produção.

## Implementadas na Fase 4

### Equipes e acesso

- Cada empresa tem uma equipe principal opcional e um vendedor principal opcional. Quando os dois são informados, o vendedor deve ter membership ativa como salesperson na equipe e na organização.
- Uma pessoa pode participar de várias equipes. A associação de equipes, sozinha, não dá a um vendedor acesso a empresas de colegas.
- Owner/admin veem a organização e gerenciam equipes, convites e memberships. Só owner concede/revoga papéis de owner/admin; admin não pode alterar membros administrativos. O último owner ativo não pode ser desativado ou rebaixado.
- Manager vê somente equipes ativas em que tem membership ativa de manager e dados comerciais das empresas dessas equipes. Manager sem equipe gerenciada não recebe acesso geral. Transferências ficam dentro de equipes administradas.
- Seller vê apenas empresas em que é responsável e tarefas que pode gerenciar; managers veem tarefas de empresas de seu escopo e tarefas avulsas dos vendedores de suas equipes.
- Memberships inativas permanecem no banco para preservar o vínculo. Vendedores não podem ser retirados de equipe enquanto mantiverem empresas atribuídas àquela equipe; transfira a carteira antes.
- Uma equipe com empresas atribuídas ou convites pendentes/expirados ainda não aceitos ou revogados não pode ser desativada. Transfira a carteira e revogue os convites antes de arquivá-la.

### Convites e carteiras

- Convites são destinados a manager ou salesperson, normalizam o e-mail, expiram em sete dias, armazenam apenas hash SHA-256 do token e são de uso único. Aceite exige autenticação, e-mail verificado igual ao destinatário e conclusão transacional.
- Reenvio substitui o token e invalida o link anterior. Revogação invalida o token. Usuários existentes podem aceitar convites sem criar outra conta ou organização.
- Transferências auditam equipe/responsável anterior e novo, autor, data UTC e justificativa opcional. Transferência em lote inteira ocorre numa transação; IDs de outra organização rejeitam o lote antes de persistir alterações.
- Tarefas automáticas abertas seguem as regras de reatribuição do motor de jornadas/inatividade; tarefas manuais e concluídas preservam seu responsável e histórico.

### Dashboard e relatórios

- O dashboard gerencial usa dados persistidos e limita sellers, tarefas, jornadas, alertas e empresas ao escopo autorizado. A tela de relatórios agrega no servidor e pagina os vendedores e a carteira de relacionamento.
- Filtros aceitam período de até 366 dias sem datas futuras, equipe e vendedor autorizados, status e tipo de tarefa.
- Taxa de conclusão = (tarefas elegíveis concluídas no prazo + concluídas com atraso) / total de tarefas elegíveis × 100. São elegíveis tarefas com vencimento no período até o instante atual; tarefas futuras e canceladas não entram no denominador. Pendentes vencidas continuam elegíveis.
- Faturamento, quantidade, ticket e compradores usam somente vendas confirmadas. Vendas novas guardam equipe no momento da venda; registros legados sem snapshot usam a equipe atual da empresa.
- Desempenho por vendedor usa a carteira atual e seller histórico da venda. O relatório informa explicitamente a política usada para vendas antigas.

## Implementadas parcialmente na Fase 5 — Importação de empresas, contatos e vendas

- O lote sempre pertence à organização ativa. O arquivo CSV/XLSX é privado, limitado a 10 MB, 100 colunas e 5.000 linhas, e expira após 24 horas.
- O usuário precisa mapear o campo obrigatório `name`, validar todas as linhas e confirmar explicitamente. A operação é transacional e a confirmação repetida de um lote concluído não cria empresas adicionais.
- Cada linha é validada antes da gravação. CNPJ deve conter 14 dígitos e passar nos dígitos verificadores. E-mail, site, UF, tamanhos e situação também são validados.
- Duplicidades por CNPJ ou nome normalizado com cidade e UF são ignoradas e relatadas quando o ator pode consultar o registro; correspondências fora de sua carteira recebem erro genérico sem nome ou identificador. Como a unicidade de CNPJ é organizacional, a contagem de linhas inválidas ainda pode indicar colisão fora da carteira; a mitigação desse sinal permanece pendente no roadmap. Não há atualização de registros existentes nem fusão automática. Sem identificador/contexto suficiente, nomes iguais não são tratados como prova de identidade.
- Na importação de empresas, o ator pode escolher nenhuma jornada, a jornada padrão ou um modelo ativo de prospecção da organização e alterar a opção antes de confirmar. Se o modelo se tornar inativo antes da confirmação, a confirmação solicita outra escolha; se a confirmação já enfileirou o lote, o processamento conclui usando a escolha registrada. A seleção explícita vale mesmo com a autoinscrição desligada; somente empresas novas elegíveis como prospectos são inscritas. A inscrição e a primeira tarefa usam o motor de jornadas existente. Contatos e vendas não iniciam jornadas.
- Empresas novas de salesperson são atribuídas a esse vendedor. Manager precisa escolher uma equipe ativa que administra; owner/admin podem importar sem equipe ou selecionar equipe da própria organização.
- Contatos exigem nome e vínculo com uma empresa já existente, identificada por CNPJ ou nome exato não ambíguo. A empresa precisa pertencer ao tenant ativo e estar visível ao ator, incluindo o escopo de carteira de vendedores e gestores.
- Linhas com o mesmo e-mail ou nome e telefone normalizados podem representar a mesma pessoa. Campos pessoais vazios podem ser complementados por outra linha; valores preenchidos divergentes invalidam o grupo para revisão. Um contato novo pode ser associado a várias empresas, com papéis, sinalizador de contato principal e observações próprios de cada vínculo.
- Um contato já existente e visível ao ator é relatado como duplicidade; não é alterado nem associado automaticamente. Se a identidade existe em empresa fora do escopo do ator, a linha é rejeitada com mensagem genérica para não revelar dados de outra carteira.
- Papéis do vínculo usam os códigos da aplicação (`owner`, `partner`, `budget_manager`, `buyer`, `decision_maker`, `influencer`, `specifier`, `finance`, `operations`, `other`); papéis ausentes assumem `other`. E-mail é normalizado para minúsculas; telefone é preservado como informado e só é normalizado na comparação de identidade.
- Vendas históricas referenciam uma empresa visível ao ator no tenant ativo e um vendedor ativo da mesma organização. Vendedores só podem indicar a si mesmos; gestores precisam escolher vendedor da equipe da empresa que administram; owner/admin seguem a exigência de membership quando a empresa tem equipe.
- O valor importado aceita até duas casas decimais e deve caber em `decimal(14,2)`. `BRL` é a moeda inicial. Datas aceitas são `AAAA-MM-DD`, `AAAA-MM-DD HH:MM[:SS]`, ISO 8601 com frações de segundo, ou `DD/MM/AAAA` quando o dia é maior que 12; datas com dia e mês ambos até 12 são rejeitadas como ambíguas. Células de data XLSX sem fuso usam o fuso da organização; timestamps com offset preservam o instante. Todas as datas são armazenadas em UTC.
- Status importados são `confirmed` e `cancelled`. Vendas confirmadas atualizam estado de cliente, inatividade e pontuação; vendas canceladas são preservadas com autor e instante do cancelamento, não entram nos agregados e atualizam o estado de inatividade. Um identificador externo repetido é ignorado quando visível ao ator; conflito com registro fora do escopo é rejeitado com mensagem genérica. Linhas sem identificador externo são mantidas separadamente, mesmo que seus atributos coincidam: atributos iguais não provam que compras sejam a mesma transação. Para impedir reimportação entre lotes, use um identificador externo estável. A confirmação repetida do mesmo lote continua idempotente.
- A confirmação repetida do mesmo lote é idempotente. A leitura e validação do arquivo ainda percorrem as linhas na requisição de validação; a gravação de arquivos com mais de 250 linhas segue por Redis após confirmação, em blocos de até 100 linhas transacionais. Payloads temporários ficam cifrados e são apagados por linha ao concluir. O progresso conta linhas totais, inclusive inválidas/duplicadas já resolvidas; falhas após três tentativas preservam blocos concluídos, e a retomada reenvia apenas linhas pendentes com número de tentativa incrementado. O heartbeat do bloco concluído mantém ativo o lease de 15 minutos; lotes sem heartbeat tornam-se retomáveis. Lotes presos em `queued` há 10 minutos são reenfileirados com a mesma tentativa, no máximo três vezes; depois disso, passam a `failed` com possibilidade de retomada manual, limitando o crescimento da fila. O Scheduler roda a cada cinco minutos e também remove arquivos privados residuais de lotes já marcados expirados. Confirmação e retomada grandes são recusadas com fila síncrona para não manter a requisição aberta. O tamanho do arquivo continua limitado a 5.000 linhas. A seleção de jornadas é oferecida apenas para importação de empresas. Sem identificador externo estável, a aplicação não consegue reconhecer uma venda reapresentada em outro lote; a resolução explícita de possíveis duplicidades permanece pendente.

### Planejadas

- Escolhas de tratamento de duplicidade (`criar`, `atualizar`, `ignorar`, resolução manual) e extensão das escolhas de jornadas aos fluxos aplicáveis.
- Onboarding, ajustes de regras de pontuação/inatividade, exportação autorizada e auditoria consultável.
- Integrações com ERP, fora do MVP atual.
