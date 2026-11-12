# Regras de negócio implementadas e planejadas

## Implementadas na Fase 1

- O cadastro cria uma organização para o usuário e concede membership `owner` na mesma transação.
- Um usuário pode pertencer a várias organizações; o workspace ativo precisa estar entre suas memberships.
- Os papéis válidos são `owner`, `admin`, `manager` e `salesperson`.
- Owner, admin e manager podem consultar a carteira completa da organização. Salesperson consulta apenas empresas atribuídas a si.
- Um contato pertence a uma única organização, mas pode se relacionar com várias empresas daquela organização.
- Papéis profissionais e contato principal pertencem ao vínculo empresa-contato.
- Interações são registradas manualmente, atribuídas ao usuário autenticado e ligadas a uma empresa; contato é opcional.
- A data do último contato é derivada do histórico, sem escrita duplicada na empresa.

## Implementadas na Fase 2

- Tarefas possuem responsável, autor, vencimento, prioridade, estado e origem manual ou automática. Relações de empresa, contato e usuários são validadas dentro da organização.
- Owner, admin e manager mantêm o escopo da organização; salesperson só consulta tarefas atribuídas a si em empresas da própria carteira.
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

- A pontuação usa compras confirmadas nos últimos 12 meses e os pesos padrão volume 40, frequência 20, potencial 20 e relacionamento 20. Não compara organizações diferentes.
- Faixa de volume inicial: R$ 0 = 0 pontos; até R$ 5.000 = 8; até R$ 25.000 = 16; até R$ 75.000 = 24; até R$ 200.000 = 32; acima = 40.
- Frequência inicial: 0 compras = 0 pontos; 1 = 5; 2 = 10; 3 = 15; 4 ou mais = 20. O intervalo conta as compras confirmadas na mesma janela de 12 meses.
- Potencial comercial e facilidade de acesso/relacionamento são avaliações manuais inteiras de 0 a 20. Campo desconhecido permanece nulo; a faixa final fica incompleta até ambos terem valor.
- Faixas padrão sem sobreposição: D 0–24, C 25–49, B 50–74 e A 75–100. As bandas são armazenadas por organização; na interface atual, o administrador ajusta limites de inatividade, repetição, frequência de contato e jornadas selecionadas.
- O histórico registra a primeira composição e cada mudança de critério, total, completude ou faixa. Recalcular sem mudança não cria outro snapshot.
- A tela de classificação filtra faixa, responsável, segmento, situação e período de compra; vendedores veem apenas a própria carteira.

### Demonstração local

O seeder apresenta os cenários fictícios de cliente estratégico, cliente inativo, prospecto sem compras, cliente reativado e cliente em evolução. Ele é restrito a `local` e `testing`, usa datas relativas e é idempotente. Nunca roda em produção.

## Planejadas

- Equipes e escopo hierárquico de carteira para gestores.
- Importação de vendas e integrações com ERP, fora do MVP atual.
