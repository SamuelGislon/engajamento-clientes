import { expect, test } from '@playwright/test';

test('owner can sign in and open team management', async ({ page }) => {
    await signIn(page, 'owner@example.test');

    await expect(page).toHaveURL(/dashboard/);

    await page.goto('/teams');
    await expect(
        page.getByRole('heading', { name: 'Equipes', exact: true }),
    ).toBeVisible();
});

test('owner can add a manager to a new team', async ({ page }) => {
    await signIn(page, 'owner@example.test');
    await page.goto('/teams');

    await page.getByLabel('Nome', { exact: true }).fill('Equipe E2E');
    await page.getByRole('button', { name: 'Criar equipe' }).click();

    await expect(
        page.getByRole('heading', { name: 'Equipe E2E', exact: true }),
    ).toBeVisible();
    await page.getByRole('link', { name: 'Equipe E2E' }).click();
    await page.getByRole('checkbox', { name: /Pedro Gestor/ }).check();
    await page.getByRole('button', { name: 'Salvar integrantes' }).click();

    await expect(
        page.getByRole('heading', { name: 'Equipe E2E', exact: true }),
    ).toBeVisible();
    await expect(page.getByText('Pedro Gestor')).toBeVisible();

    await page.goto('/organization/users');
    await page.getByLabel('E-mail').fill('pending-team-invite@example.test');
    await page.getByLabel('Papel', { exact: true }).selectOption('salesperson');
    await page.getByLabel('Equipe', { exact: true }).selectOption({
        label: 'Equipe E2E',
    });
    await page.getByRole('button', { name: 'Enviar convite' }).click();
    await expect(
        page.getByText('pending-team-invite@example.test'),
    ).toBeVisible();

    await page.goto('/teams');
    await page.getByRole('link', { name: 'Equipe E2E' }).click();
    await page.getByLabel('Situação').selectOption('inactive');
    const deactivationResponsePromise = page.waitForResponse(
        (response) =>
            new URL(response.url()).pathname.startsWith('/teams/') &&
            response.request().method() === 'PATCH',
    );
    const teamPageResponsePromise = page.waitForResponse(
        (response) =>
            new URL(response.url()).pathname.startsWith('/teams/') &&
            response.request().method() === 'GET',
    );
    await page.getByRole('button', { name: 'Salvar equipe' }).click();
    const deactivationResponse = await deactivationResponsePromise;
    expect(deactivationResponse.status()).toBe(303);
    await teamPageResponsePromise;
    await expect(page.locator('[role="alert"]')).toContainText(
        'Revogue os convites pendentes ou expirados antes de desativar a equipe.',
    );
    await page.goto('/teams');
    await page.getByRole('link', { name: 'Equipe E2E' }).click();
    const teamHeader = page.locator('header').filter({
        has: page.getByRole('heading', {
            name: 'Equipe E2E',
            exact: true,
        }),
    });
    await expect(teamHeader.getByText('Ativa', { exact: true })).toBeVisible();
});

test('owner creates a team, accepts an invitation, and assigns a company', async ({
    page,
    browser,
}) => {
    const teamName = 'Equipe Convite E2E';
    const inviteeEmail = 'phase4-invitee@example.test';

    await signIn(page, 'owner@example.test');
    await page.goto('/teams');
    await page.getByLabel('Nome', { exact: true }).fill(teamName);
    await page.getByRole('button', { name: 'Criar equipe' }).click();

    await page.goto('/organization/users');
    await page.getByLabel('E-mail').fill(inviteeEmail);
    await page.getByLabel('Papel', { exact: true }).selectOption('salesperson');
    await page
        .getByLabel('Equipe', { exact: true })
        .selectOption({ label: teamName });
    await page.getByRole('button', { name: 'Enviar convite' }).click();
    await expect(page.getByText(inviteeEmail)).toBeVisible();

    const messageListResponse = await page.request.get(
        'http://127.0.0.1:8025/api/v1/messages?limit=100',
    );
    expect(messageListResponse.ok()).toBeTruthy();
    const messageList = (await messageListResponse.json()) as {
        messages: { ID?: string; id?: string; To?: unknown }[];
    };
    const invitationMessage = messageList.messages.find((message) =>
        JSON.stringify(message.To).toLowerCase().includes(inviteeEmail),
    );
    const messageId = invitationMessage?.ID ?? invitationMessage?.id;
    expect(messageId).toBeTruthy();

    const messageResponse = await page.request.get(
        `http://127.0.0.1:8025/api/v1/message/${messageId}`,
    );
    expect(messageResponse.ok()).toBeTruthy();
    const messageBody = JSON.stringify(await messageResponse.json());
    const invitationToken = messageBody.match(
        /invitations\/([a-f0-9]{64})/,
    )?.[1];
    expect(invitationToken).toBeTruthy();

    const inviteeContext = await browser.newContext();
    const inviteePage = await inviteeContext.newPage();
    await inviteePage.goto(`/invitations/${invitationToken}`);
    await inviteePage
        .getByRole('link', { name: 'Entrar para aceitar' })
        .click();
    await signIn(inviteePage, inviteeEmail, /invitations/);
    await inviteePage.getByRole('button', { name: 'Aceitar convite' }).click();
    await expect(inviteePage).toHaveURL(/dashboard/);
    await expect(
        page.getByRole('button', { name: /Camila Proprietária/ }),
    ).toBeVisible();

    await page.goto('/companies/portfolio');
    await page.getByRole('checkbox', { name: /Ateliê Aurora/ }).check();
    await page
        .getByLabel('Equipe de destino')
        .selectOption({ label: teamName });
    await page
        .getByLabel('Vendedor de destino')
        .selectOption({ label: 'Vendedor Convidado E2E' });
    await page
        .getByLabel('Justificativa (opcional)')
        .fill('Atribuição inicial de carteira no teste E2E.');
    const transferResponsePromise = page.waitForResponse(
        (response) =>
            new URL(response.url()).pathname ===
                '/companies/portfolio/transfer' &&
            response.request().method() === 'POST',
    );
    page.once('dialog', async (dialog) => {
        await dialog.accept();
    });
    await page.getByRole('button', { name: /Transferir 1/ }).click();
    const transferResponse = await transferResponsePromise;
    expect(transferResponse.status()).toBe(302);

    const transferredCompany = page
        .locator('label')
        .filter({ has: page.getByRole('checkbox', { name: /Ateliê Aurora/ }) });
    await expect(transferredCompany).toContainText('Vendedor Convidado E2E');
    await expect(transferredCompany).toContainText(teamName);
    await inviteeContext.close();
});

test('seller records an interaction and completes a task', async ({ page }) => {
    const interactionDescription = 'Registro de interação criado no E2E.';
    const taskTitle = 'Acompanhar oportunidade pelo E2E';

    await signIn(page, 'ana@example.test');
    await page.goto('/companies');
    await page.getByRole('link', { name: 'Escritório Horizonte' }).click();
    await page.locator('#description').fill(interactionDescription);
    await page.getByRole('button', { name: 'Salvar interação' }).click();
    await expect(page.getByText(interactionDescription)).toBeVisible();

    await page.goto('/tasks/create');
    await page.getByLabel('Título').fill(taskTitle);
    await page
        .getByLabel('Empresa')
        .selectOption({ label: 'Escritório Horizonte' });
    await page.getByRole('button', { name: 'Salvar atividade' }).click();
    await expect(page.getByRole('heading', { name: taskTitle })).toBeVisible();

    const taskRow = page
        .getByRole('listitem')
        .filter({ has: page.getByRole('heading', { name: taskTitle }) });
    const completionResponsePromise = page.waitForResponse(
        (response) =>
            /\/tasks\/\d+\/complete$/.test(new URL(response.url()).pathname) &&
            response.request().method() === 'POST',
    );
    await taskRow.getByRole('button', { name: 'Concluir' }).click();
    const completionResponse = await completionResponsePromise;
    expect(completionResponse.status()).toBe(302);
    await page.goto('/tasks?status=completed');
    await expect(
        page.getByRole('heading', { name: taskTitle, exact: true }),
    ).toBeVisible();
});

test('manager and salesperson cannot open companies outside their team and portfolio', async ({
    page,
    browser,
}) => {
    await signIn(page, 'owner@example.test');
    await page.goto('/companies');
    const restrictedCompanyHref = await page
        .getByRole('link', { name: /Ateliê Aurora/ })
        .first()
        .getAttribute('href');
    expect(restrictedCompanyHref).toMatch(/^\/companies\/\d+$/);

    const managerPage = await browser.newPage();
    await signIn(managerPage, 'manager@example.test');
    await expect(
        managerPage.getByRole('button', { name: /Pedro Gestor/ }),
    ).toBeVisible();
    await managerPage.goto('/companies');
    await expect(
        managerPage.getByRole('link', { name: /Escritório Horizonte/ }),
    ).toBeVisible();
    await expect(
        managerPage.getByRole('link', { name: /Ateliê Aurora/ }),
    ).toHaveCount(0);
    const managerResponse = await managerPage.goto(restrictedCompanyHref!);
    expect(managerResponse?.status()).toBe(403);
    await managerPage.close();

    const sellerPage = await browser.newPage();
    await signIn(sellerPage, 'ana@example.test');
    await expect(
        sellerPage.getByRole('button', { name: /Ana Costa/ }),
    ).toBeVisible();
    await sellerPage.goto('/companies');
    await expect(
        sellerPage.getByRole('link', { name: /Escritório Horizonte/ }),
    ).toBeVisible();
    await expect(
        sellerPage.getByRole('link', { name: /Ateliê Aurora/ }),
    ).toHaveCount(0);
    const sellerResponse = await sellerPage.goto(restrictedCompanyHref!);
    expect(sellerResponse?.status()).toBe(403);
    await sellerPage.close();
});

test('owner filters commercial reports by period and team and opens seller details', async ({
    page,
}) => {
    const today = new Date().toLocaleDateString('en-CA', {
        timeZone: 'America/Sao_Paulo',
    });
    const fromDate = new Date(`${today}T12:00:00.000Z`);
    fromDate.setUTCDate(fromDate.getUTCDate() - 30);

    await signIn(page, 'owner@example.test');
    await page.goto('/reports');
    await page
        .getByLabel('Data inicial')
        .fill(fromDate.toISOString().slice(0, 10));
    await page.getByLabel('Data final').fill(today);
    await page.getByLabel('Equipe', { exact: true }).selectOption({
        label: 'Equipe Norte',
    });
    await page.getByRole('button', { name: 'Filtrar' }).click();
    await expect(page).toHaveURL(/team_id=\d+/);

    const resultsByTeam = page.locator('section').filter({
        has: page.getByRole('heading', { name: 'Resultados por equipe' }),
    });
    const northTeamRow = resultsByTeam
        .getByRole('row')
        .filter({ hasText: 'Equipe Norte' });
    await expect(northTeamRow.getByRole('cell').nth(2)).toHaveText('2');

    await page.getByRole('link', { name: 'Ana Costa', exact: true }).click();
    await expect(page).toHaveURL(/seller_id=\d+/);
    await expect(
        page.getByRole('row').filter({ hasText: 'Ana Costa' }).first(),
    ).toBeVisible();
});

async function signIn(
    page: import('@playwright/test').Page,
    email: string,
    expectedUrl: RegExp = /dashboard/,
): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(expectedUrl);
}
