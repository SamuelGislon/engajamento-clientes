---
name: engagement-automation
description: Implemente ou revise gatilhos de jornadas, lembretes por inatividade, jobs e geração idempotente de tarefas no CRM comercial.
---

# Jornada de engajamento confiável

1. Documente evento de entrada, sujeito da jornada (empresa/pessoa), dono da tarefa, condições e regra de saída.
2. Distinga última compra de última interação; trate ausência de histórico como estado próprio, não como compra antiga.
3. Especifique estados da jornada/etapa e transições válidas; não crie regras duplicadas em controllers ou UI.
4. Calcule `due_at` com fuso horário conhecido e política explícita de dias úteis, caso exista.
5. Faça jobs idempotentes com chave persistida e restrição de unicidade no banco para evento/sujeito/etapa/ocorrência.
6. Proteja contra concorrência, retry e reprocessamento do Scheduler; efeitos colaterais só após commit, quando aplicável.
7. Ao registrar venda, contato ou mudança de responsável, reavalie tarefas futuras sem apagar o histórico concluído.
8. Teste entrada, retry duplicado, corrida concorrente (quando viável), pausa, retomada, cancelamento, troca de tenant e mudança de estado.
9. Notificação é projeção/alerta; não é a fonte de verdade da tarefa ou da jornada.

Saída: tabela das transições, implementação focada, testes de idempotência e limitações restantes.
