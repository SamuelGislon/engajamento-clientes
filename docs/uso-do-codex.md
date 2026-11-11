# Orientações para uso do Codex neste projeto

## Prioridade de ferramentas
1. Laravel Boost: documentação, código real, schema, logs e skills Inertia/Pest.
2. Context7: consulta pontual a docs de bibliotecas, se Boost não cobrir.
3. Vercel React Best Practices e Web Design Guidelines: revisão de React, UX e acessibilidade.
4. Playwright CLI + skill: inspeção e testes práticos da UI local.
5. `codex review`: revisão de diffs.
6. Codex Security: revisões de segurança em marcos importantes.

## Comandos de instalação (executar manualmente após revisar)
No diretório de um Laravel já inicializado:

```bash
composer require laravel/boost --dev
php artisan boost:install
```

No terminal onde o Codex CLI está disponível:

```bash
codex mcp list
codex plugin list
```

Context7, opção via configuração oficial:

```bash
npx ctx7 setup --codex
```

Playwright CLI e skills no padrão `.agents/skills`:

```bash
npm install -g @playwright/cli@latest
playwright-cli install --skills=agents
```

Skills Vercel (instalador interativo; selecione apenas as úteis):

```bash
npx skills add vercel-labs/agent-skills
```

Leia README dos fornecedores antes de instalar; não automatize scripts remotos sem revisão.

## Fluxo de tarefa recomendado
1. Definir entrega verificável em 1–2 frases e escopo não incluído.
2. Codex inspeciona apenas arquivos relevantes e faz plano curto para mudanças complexas.
3. Implementação de um corte vertical pequeno.
4. Testes focados, análise estática e teste de navegador quando houver UI.
5. Revisor independente para mudanças de isolamento, auth e jornadas.
6. `codex review` e inspeção humana do diff antes do merge.

## Observações
- Scripts de `npm run lint` ou PHPStan só devem ser executados se estiverem configurados.
- Skills locais ficam em `.agents/skills`; o exemplo global vai para `~/.codex/AGENTS.md`, fora do repositório.
- `Laravel Boost` pode gerar seu próprio bloco de orientações no AGENTS.md. Preserve o conteúdo manual e evite duplicar instruções.
