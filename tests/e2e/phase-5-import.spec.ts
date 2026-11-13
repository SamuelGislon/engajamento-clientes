import { expect, test } from '@playwright/test';

test('owner imports a CSV company after mapping and reviewing validation', async ({
    page,
}) => {
    const companyName = 'Escritório Importado E2E';

    await signIn(page, 'owner@example.test');
    await page.goto('/imports');
    await expect(
        page.getByRole('heading', { name: 'Importar dados', exact: true }),
    ).toBeVisible();

    await page.getByLabel('Arquivo CSV ou Excel').setInputFiles({
        name: 'companies.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(`Name,City,State\n${companyName},Manaus,AM\n`),
    });
    await page.getByRole('button', { name: 'Enviar para prévia' }).click();

    await page.getByRole('link', { name: 'companies.csv' }).click();
    await expect(page.getByText(companyName)).toBeVisible();
    await page.getByLabel('Nome da empresa').selectOption('0');
    await page.getByLabel('Cidade').selectOption('1');
    await page.getByLabel('UF').selectOption('2');
    await page.getByRole('button', { name: 'Salvar mapeamento' }).click();

    await page.getByRole('button', { name: 'Validar todas as linhas' }).click();
    await expect(
        page.getByRole('heading', { name: 'Validar e confirmar' }),
    ).toBeVisible();
    await expect(page.getByText('1', { exact: true }).first()).toBeVisible();

    await page.getByRole('button', { name: 'Confirmar importação' }).click();
    await expect(
        page.getByRole('heading', { name: 'Resultado da importação' }),
    ).toBeVisible();
    await expect(page.getByText(companyName)).toBeVisible();

    await page.goto('/companies');
    await expect(
        page.getByRole('link', { name: `${companyName} Manaus`, exact: true }),
    ).toBeVisible();
});

test('owner can choose the default journey for imported eligible prospects', async ({
    page,
}) => {
    const companyName = 'Prospecto com Jornada E2E';

    await signIn(page, 'owner@example.test');
    await page.goto('/imports');
    await page
        .getByLabel('Jornadas para os prospectos importados')
        .selectOption('default');
    await page.getByLabel('Arquivo CSV ou Excel').setInputFiles({
        name: 'companies-journey.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(`Name\n${companyName}\n`),
    });
    await page.getByRole('button', { name: 'Enviar para prévia' }).click();

    await page.getByRole('link', { name: 'companies-journey.csv' }).click();
    await expect(page.getByText('Jornadas: jornada padrão')).toBeVisible();
    await page.getByLabel('Nome da empresa').selectOption('0');
    await page.getByRole('button', { name: 'Salvar mapeamento' }).click();
    await page.getByRole('button', { name: 'Validar todas as linhas' }).click();
    await page.getByRole('button', { name: 'Confirmar importação' }).click();
    await expect(
        page.getByRole('heading', { name: 'Resultado da importação' }),
    ).toBeVisible();

    await page.goto('/companies');
    await page
        .locator('a[href^="/companies/"]')
        .filter({ hasText: companyName })
        .first()
        .click();
    await expect(
        page.getByRole('heading', { name: 'Prospecção Comercial' }),
    ).toBeVisible();
    await expect(
        page.getByText('Primeiro contato', { exact: true }),
    ).toBeVisible();
});

test('owner imports one contact linked to multiple existing companies', async ({
    page,
}) => {
    const contactName = 'Contato Multiempresa E2E';
    const contactEmail = 'multiempresa@example.test';

    await signIn(page, 'owner@example.test');
    await page.goto('/imports');
    await page
        .getByLabel('O que você está importando?')
        .selectOption('contacts');
    await page.getByLabel('Arquivo CSV ou Excel').setInputFiles({
        name: 'contacts.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(
            [
                'Name,Email,Job title,Company name,Company roles,Is primary',
                `${contactName},${contactEmail},Diretora,Escritório Horizonte,decision_maker,sim`,
                `${contactName},${contactEmail},Diretora,Escritório Brisa,buyer,não`,
            ].join('\n'),
        ),
    });
    await page.getByRole('button', { name: 'Enviar para prévia' }).click();

    await page.getByRole('link', { name: 'contacts.csv' }).click();
    await expect(page.getByText(contactName).first()).toBeVisible();
    await page.getByLabel('Nome completo').selectOption('0');
    await page.getByLabel('E-mail').selectOption('1');
    await page.getByLabel('Cargo ou função').selectOption('2');
    await page.getByLabel('Nome da empresa').selectOption('3');
    await page.getByLabel('Papéis na empresa (; separados)').selectOption('4');
    await page.getByLabel('Contato principal (sim/não)').selectOption('5');
    await page.getByRole('button', { name: 'Salvar mapeamento' }).click();

    await page.getByRole('button', { name: 'Validar todas as linhas' }).click();
    await expect(
        page.getByRole('heading', { name: 'Validar e confirmar' }),
    ).toBeVisible();
    await expect(page.getByText('2', { exact: true }).first()).toBeVisible();

    await page.getByRole('button', { name: 'Confirmar importação' }).click();
    await expect(
        page.getByRole('heading', { name: 'Resultado da importação' }),
    ).toBeVisible();
    await expect(page.getByText(contactName).first()).toBeVisible();

    await page.goto('/contacts');
    await expect(page.getByText(contactName, { exact: true })).toBeVisible();
});

test('owner imports a historical sale with an authorized seller', async ({
    page,
}) => {
    const description = 'Venda histórica importada E2E';

    await signIn(page, 'owner@example.test');
    await page.goto('/imports');
    await page.getByLabel('O que você está importando?').selectOption('sales');
    await page.getByLabel('Arquivo CSV ou Excel').setInputFiles({
        name: 'sales.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(
            [
                'External ID,Company name,Seller email,Description,Amount,Currency,Sold at,Status',
                `e2e-historical-sale,Escritório Horizonte,ana@example.test,${description},1250.75,BRL,2026-01-20,confirmed`,
            ].join('\n'),
        ),
    });
    await page.getByRole('button', { name: 'Enviar para prévia' }).click();

    await page.getByRole('link', { name: 'sales.csv' }).click();
    await page.getByLabel('Identificador externo').selectOption('0');
    await page.getByLabel('Nome da empresa').selectOption('1');
    await page.getByLabel('E-mail do vendedor').selectOption('2');
    await page.getByLabel('Descrição da venda').selectOption('3');
    await page.getByLabel('Valor decimal').selectOption('4');
    await page.getByLabel('Moeda').selectOption('5');
    await page.getByLabel('Data e hora da venda').selectOption('6');
    await page.getByLabel('Situação (confirmed/cancelled)').selectOption('7');
    await page.getByRole('button', { name: 'Salvar mapeamento' }).click();

    await page.getByRole('button', { name: 'Validar todas as linhas' }).click();
    await expect(
        page.getByRole('heading', { name: 'Validar e confirmar' }),
    ).toBeVisible();
    await expect(page.getByText('1', { exact: true }).first()).toBeVisible();

    await page.getByRole('button', { name: 'Confirmar importação' }).click();
    await expect(
        page.getByRole('heading', { name: 'Resultado da importação' }),
    ).toBeVisible();
    await expect(page.getByText(description)).toBeVisible();

    await page.goto('/sales');
    await expect(page.getByText(description, { exact: true })).toBeVisible();
});

test('owner sees why confirmation stops when its destination team becomes inactive', async ({
    page,
}) => {
    const teamName = 'Equipe Importação E2E';
    const importFilename = 'companies-team.csv';

    await signIn(page, 'owner@example.test');
    await page.goto('/teams');
    await page.getByLabel('Nome', { exact: true }).fill(teamName);
    await page.getByRole('button', { name: 'Criar equipe' }).click();
    await expect(
        page.getByRole('heading', { name: teamName, exact: true }),
    ).toBeVisible();

    await page.goto('/imports');
    await page
        .getByLabel('Equipe de destino')
        .selectOption({ label: teamName });
    await page.getByLabel('Arquivo CSV ou Excel').setInputFiles({
        name: importFilename,
        mimeType: 'text/csv',
        buffer: Buffer.from('Name\nEscritório Equipe E2E\n'),
    });
    await page.getByRole('button', { name: 'Enviar para prévia' }).click();

    const importLink = page.getByRole('link', { name: importFilename });
    const importHref = await importLink.getAttribute('href');
    expect(importHref).toBeTruthy();
    await importLink.click();
    await page.getByLabel('Nome da empresa').selectOption('0');
    await page.getByRole('button', { name: 'Salvar mapeamento' }).click();
    await page.getByRole('button', { name: 'Validar todas as linhas' }).click();

    await page.goto('/teams');
    await page.getByRole('link', { name: teamName, exact: true }).click();
    await page.getByLabel('Situação').selectOption('inactive');
    await page.getByRole('button', { name: 'Salvar equipe' }).click();

    await page.goto(importHref!);
    await page.getByRole('button', { name: 'Confirmar importação' }).click();
    await expect(page.getByRole('alert')).toContainText(
        'A equipe de destino não está mais ativa nesta organização.',
    );
});

test('owner is told to enable an asynchronous queue before confirming a large import', async ({
    page,
}) => {
    const importFilename = 'large-companies.csv';
    const rows = Array.from(
        { length: 251 },
        (_, index) => `Empresa grande ${index + 1}`,
    );

    await signIn(page, 'owner@example.test');
    await page.goto('/imports');
    await page.getByLabel('Arquivo CSV ou Excel').setInputFiles({
        name: importFilename,
        mimeType: 'text/csv',
        buffer: Buffer.from(['Name', ...rows].join('\n')),
    });
    await page.getByRole('button', { name: 'Enviar para prévia' }).click();
    await page.getByRole('link', { name: importFilename }).click();
    await page.getByLabel('Nome da empresa').selectOption('0');
    await page.getByRole('button', { name: 'Salvar mapeamento' }).click();
    await page.getByRole('button', { name: 'Validar todas as linhas' }).click();
    await page.getByRole('button', { name: 'Confirmar importação' }).click();

    await expect(page.getByRole('alert')).toContainText(
        'Configure Redis e um worker',
    );
    await expect(
        page.getByRole('heading', { name: 'Validar e confirmar' }),
    ).toBeVisible();
});

async function signIn(
    page: import('@playwright/test').Page,
    email: string,
): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/dashboard/);
}
