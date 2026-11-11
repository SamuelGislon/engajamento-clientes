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

## Planejadas

- Uma venda atualizará o estado do relacionamento e poderá iniciar a jornada pós-venda.
- Regras de inatividade usarão limites configurados por organização e não duplicarão alertas ativos.
- Classificação comercial será calculada a partir de atividade, compras e critérios explícitos, com histórico de transições.
- Gestores verão o escopo das equipes depois que equipes e memberships estiverem implementadas.
