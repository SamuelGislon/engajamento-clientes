import { expect, test } from '@playwright/test';

test('owner sees tier field errors and custom tier labels on the dashboard', async ({
    page,
}) => {
    await signIn(page, 'owner@example.test');
    await page.goto('/commercial-settings');

    const firstTierName = page.getByLabel('Nome').first();
    await firstTierName.fill('');
    await page.getByRole('button', { name: 'Salvar configurações' }).click();
    await expect(firstTierName.locator('xpath=..').locator('p')).toBeVisible();

    await firstTierName.fill('Conta Inicial E2E');
    await page.getByRole('button', { name: 'Salvar configurações' }).click();
    await expect(
        page.getByRole('heading', { name: 'Configurações comerciais' }),
    ).toBeVisible();

    await page.goto('/dashboard');
    await expect(
        page.getByText('Conta Inicial E2E', { exact: true }),
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
