import { expect, test } from '@playwright/test';

test('owner resumes the guided setup, skips optional steps, and opens the dashboard', async ({
    page,
}) => {
    await page.goto('/login');
    await page
        .getByLabel('Email address')
        .fill('onboarding-owner@example.test');
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();

    await expect(page).toHaveURL(/onboarding/);
    await expect(
        page.getByRole('heading', { name: 'Configure seu espaço comercial' }),
    ).toBeVisible();

    await page
        .getByLabel('Nome da organização')
        .fill('Workspace Configurado E2E');
    await page.getByLabel('Fuso horário').selectOption('UTC');
    await page.getByRole('button', { name: 'Salvar e continuar' }).click();

    await expect(
        page.getByRole('heading', {
            name: 'Configuração inicial do relacionamento',
        }),
    ).toBeVisible();
    await page.getByLabel('Dias sem compra para alerta').fill('120');
    await page.getByLabel('Dias sem contato para alerta').fill('45');
    await page.getByRole('button', { name: 'Salvar e continuar' }).click();

    await expect(
        page.getByRole('heading', {
            name: 'Escolha a jornada de prospecção padrão',
        }),
    ).toBeVisible();
    await page
        .getByLabel('Inscrição automática de prospectos')
        .selectOption('off');
    await page.getByRole('button', { name: 'Salvar e continuar' }).click();

    await expect(
        page.getByRole('heading', { name: 'Convide colaboradores' }),
    ).toBeVisible();
    const teamsPagePromise = page.context().waitForEvent('page');
    await page.getByRole('link', { name: 'Configurar equipes' }).click();
    const teamsPage = await teamsPagePromise;
    await expect(teamsPage).toHaveURL(/teams/);
    await expect(
        teamsPage.getByRole('heading', { name: 'Equipes', exact: true }),
    ).toBeVisible();
    await teamsPage.close();

    await page.getByRole('button', { name: 'Continuar sem convidar' }).click();

    await expect(
        page.getByRole('heading', { name: 'Adicione sua primeira carteira' }),
    ).toBeVisible();
    await page
        .getByRole('button', { name: 'Concluir e abrir o dashboard' })
        .click();

    await expect(page).toHaveURL(/dashboard/);
    await expect(page.getByText('Workspace Configurado E2E')).toBeVisible();
});
