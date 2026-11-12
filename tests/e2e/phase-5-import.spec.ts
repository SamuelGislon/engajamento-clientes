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
