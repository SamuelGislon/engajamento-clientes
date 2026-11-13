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
- Duplicidades de empresas por CNPJ ou nome normalizado com cidade e UF são relatadas quando o ator pode consultar o registro. Para uma linha duplicada de empresa, o usuário pode escolher criar, atualizar o único correspondente, selecionar manualmente um correspondente visível quando houver ambiguidade ou ignorar. Criar é recusado quando o CNPJ já está usado na organização; atualizar grava somente campos mapeados e não vazios, preservando proprietário, equipe e campos não enviados. A decisão é salva e revalidada antes da confirmação, inclusive em processamento assíncrono. Até 100 ocorrências são apresentadas por rodada; as decisões anteriores são preservadas e ignorar remove a linha da lista de revisão para que as próximas ocorrências possam ser tratadas. A tela exibe o identificador interno e CNPJ mascarado dos candidatos visíveis, filtra candidatos pela carteira atual e a gravação verifica novamente tenant e escopo. A membership de gestor na equipe do candidato é bloqueada e revalidada imediatamente antes de atualizar. Se um candidato sair da carteira depois da validação, a tela substitui a duplicidade antiga por erro genérico de escopo; para atores com escopo restrito, duplicidades excedentes sem detalhe após o limite de 100 erros também não aparecem como duplicidade. Não há fusão automática. Sem identificador/contexto suficiente, nomes iguais não são tratados como prova de identidade.
- Correspondências de empresas fora da carteira recebem erro genérico sem nome ou identificador. Como a unicidade de CNPJ é organizacional, a contagem de linhas inválidas ainda pode refletir colisão fora da carteira; a mitigação desse sinal permanece pendente no roadmap.
- Na importação de empresas, o ator pode escolher nenhuma jornada, a jornada padrão ou um modelo ativo de prospecção da organização e alterar a opção antes de confirmar. Se o modelo se tornar inativo antes da confirmação, a confirmação solicita outra escolha; se a confirmação já enfileirou o lote, o processamento conclui usando a escolha registrada. A seleção explícita vale mesmo com a autoinscrição desligada; somente empresas novas elegíveis como prospectos são inscritas. A inscrição e a primeira tarefa usam o motor de jornadas existente. Contatos e vendas não iniciam jornadas.
- Empresas novas de salesperson são atribuídas a esse vendedor. Manager precisa escolher uma equipe ativa que administra; owner/admin podem importar sem equipe ou selecionar equipe da própria organização.
- Contatos exigem nome e vínculo com uma empresa já existente, identificada por CNPJ ou nome exato não ambíguo. A empresa precisa pertencer ao tenant ativo e estar visível ao ator, incluindo o escopo de carteira de vendedores e gestores.
- Linhas com o mesmo e-mail ou nome e telefone normalizados podem representar a mesma pessoa. Campos pessoais vazios podem ser complementados por outra linha; valores preenchidos divergentes invalidam o grupo para revisão. Um contato novo pode ser associado a várias empresas, com papéis, sinalizador de contato principal e observações próprios de cada vínculo.
- Um contato já existente e visível ao ator é apresentado como duplicidade e exige a escolha entre criar outro registro sem fusão, atualizar um único contato, resolver manualmente uma ambiguidade ou ignorar. A decisão é aplicada ao grupo inteiro de linhas da pessoa, persistida até a confirmação e preservada no staging assíncrono cifrado. Atualização altera apenas dados pessoais informados, preserva status e vínculos anteriores e associa as empresas importadas que ainda não estejam vinculadas. Candidatos mostram nome, ID interno e e-mail/telefone mascarados, são filtrados novamente pela carteira e a permissão do contato e das empresas de destino é revalidada ao gravar. Se o ator perder acesso depois da validação, a tela transforma duplicidades sem candidatos visíveis em erros genéricos de escopo e recalcula as contagens exibidas; em lotes restritos cujo limite de 100 erros foi atingido, duplicidades sem detalhe armazenado também são contadas como erros genéricos para não revelar correspondências fora do escopo.
- Correspondências que existem somente em contatos fora da carteira não são consultadas como candidatos: a linha é tratada como novo contato ligado à empresa visível selecionada. Isso evita divulgar, atualizar ou vincular um registro fora do escopo; essa política pode criar registros separados para a mesma pessoa quando a carteira não permite acessar o contato anterior.
- Linhas idênticas para a mesma pessoa e empresa dentro do arquivo geram um único vínculo e contam como duplicadas sem exigir nova decisão; vínculos repetidos com papéis ou observações divergentes são ambíguos e precisam ser corrigidos no arquivo.
- Papéis do vínculo usam os códigos da aplicação (`owner`, `partner`, `budget_manager`, `buyer`, `decision_maker`, `influencer`, `specifier`, `finance`, `operations`, `other`); papéis ausentes assumem `other`. E-mail é normalizado para minúsculas; telefone é preservado como informado e só é normalizado na comparação de identidade.
- Vendas históricas referenciam uma empresa visível ao ator no tenant ativo e um vendedor ativo da mesma organização. Vendedores só podem indicar a si mesmos; gestores precisam escolher vendedor da equipe da empresa que administram; owner/admin seguem a exigência de membership quando a empresa tem equipe.
- O valor importado aceita até duas casas decimais e deve caber em `decimal(14,2)`. `BRL` é a moeda inicial. Datas aceitas são `AAAA-MM-DD`, `AAAA-MM-DD HH:MM[:SS]`, ISO 8601 com frações de segundo, ou `DD/MM/AAAA` quando o dia é maior que 12; datas com dia e mês ambos até 12 são rejeitadas como ambíguas. Células de data XLSX sem fuso usam o fuso da organização; timestamps com offset preservam o instante. Todas as datas são armazenadas em UTC.
- Status importados são `confirmed` e `cancelled`. Vendas confirmadas atualizam estado de cliente, inatividade e pontuação; vendas canceladas são preservadas com autor e instante do cancelamento, não entram nos agregados e atualizam o estado de inatividade. Um identificador externo repetido é ignorado quando visível ao ator; opções explícitas de criação, atualização e resolução manual para vendas permanecem pendentes. Conflito com registro fora do escopo é rejeitado com mensagem genérica. Linhas sem identificador externo são mantidas separadamente, mesmo que seus atributos coincidam: atributos iguais não provam que compras sejam a mesma transação. Para impedir reimportação entre lotes, use um identificador externo estável. A confirmação repetida do mesmo lote continua idempotente.
- A confirmação repetida do mesmo lote é idempotente. A leitura e validação do arquivo ainda percorrem as linhas na requisição de validação; a gravação de arquivos com mais de 250 linhas segue por Redis após confirmação, em blocos de até 100 linhas transacionais. Payloads temporários ficam cifrados e são apagados por linha ao concluir. O progresso conta linhas totais, inclusive inválidas/duplicadas já resolvidas; falhas após três tentativas preservam blocos concluídos, e a retomada reenvia apenas linhas pendentes com número de tentativa incrementado. O heartbeat do bloco concluído mantém ativo o lease de 15 minutos; lotes sem heartbeat tornam-se retomáveis. Lotes presos em `queued` há 10 minutos são reenfileirados com a mesma tentativa, no máximo três vezes; depois disso, passam a `failed` com possibilidade de retomada manual, limitando o crescimento da fila. O Scheduler roda a cada cinco minutos e também remove arquivos privados residuais de lotes já marcados expirados. Confirmação e retomada grandes são recusadas com fila síncrona para não manter a requisição aberta. O tamanho do arquivo continua limitado a 5.000 linhas. A seleção de jornadas é oferecida apenas para importação de empresas. Sem identificador externo estável, a aplicação não consegue reconhecer uma venda reapresentada em outro lote; a resolução explícita de possíveis duplicidades permanece pendente.

## Onboarding da organização

- Depois que o owner verifica o e-mail, uma organização recém-criada inicia em `organization` e o dashboard direciona para o progresso salvo. O owner pode interromper e retomar; cada etapa avança somente depois de validação e gravação no servidor.
- A configuração inclui nome e fuso, limites iniciais para alertas de compra e contato, escolha do modelo padrão de prospecção e ativação opcional de autoinscrição. A seleção exige uma jornada ativa de prospecção do tenant atual.
- Convites, equipes e carteira são opcionais. Os links reutilizam os módulos atuais de equipes, usuários, cadastro de empresas e importação; a conclusão sem executar esses passos libera o dashboard.
- O estado fica em `organizations`, sem tabela paralela de progresso. Organizações existentes com `onboarding_step` nulo não recebem redirecionamento. Apenas owner ativo pode alterar ou concluir o fluxo, com revalidação da membership sob lock da organização antes das mutações.

### Planejadas

- Escolhas de tratamento de duplicidade (`criar`, `atualizar`, `ignorar`, resolução manual) e extensão das escolhas de jornadas aos fluxos aplicáveis.
- Templates de adoção além do onboarding inicial; exportação autorizada e auditoria consultável.
- Integrações com ERP, fora do MVP atual.
