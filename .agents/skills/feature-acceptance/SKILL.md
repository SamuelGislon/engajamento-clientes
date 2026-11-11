---
name: feature-acceptance
description: Entregue uma funcionalidade Laravel/React de ponta a ponta quando o pedido exigir uma nova tela, formulário, fluxo operacional ou correção verificável.
---

# Entrega vertical completa

1. Resuma comportamento observável, usuário afetado e casos de erro.
2. Localize rotas, controllers, Form Requests, Policies, models e componentes existentes antes de inventar abstrações.
3. Planeje um corte vertical: migration se necessária, persistência, validação, autorização, interface e feedback.
4. Implemente estados vazios, loading, falha, sucesso e navegação acessível.
5. Adicione testes para regras não triviais e isolamento/autorização; reutilize testes existentes quando fizer sentido.
6. Rode os comandos relevantes da stack instalada e, para UI importante, verifique no navegador com Playwright.
7. Entregue resumo dos arquivos alterados, caminho para testar manualmente e evidências dos testes.

Não entregar interface com botões inertes, dados mockados ocultos ou sucesso afirmado sem verificação.
