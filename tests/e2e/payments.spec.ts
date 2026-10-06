import { expect, test } from '@playwright/test';
import { loginAs, postJson } from './helpers';

/*
| The reservation money path, through the real UI. Stripe is replaced by a
| fake payment page (E2E_MODE); everything else — forms, redirects, the
| fulfilment code, the cancel/refund rules — is the production code.
*/

test.describe.configure({ mode: 'serial' });

test('a customer finishes a pending reservation on the payment page and it becomes active', async ({
    browser,
}) => {
    const { context, page } = await loginAs(browser, 'customer');

    await page.goto('/reservations');
    const card = page.locator('article', { hasText: 'pending' });
    // Unpaid reservations have no group chat to open yet.
    await expect(card.getByRole('link', { name: 'Group chat' })).toHaveCount(0);

    await card.getByRole('button', { name: 'Finish payment' }).click();
    await expect(
        page.getByRole('heading', { name: 'Fake Stripe Checkout' }),
    ).toBeVisible();

    await page
        .getByRole('button', { name: 'Pay and return to the site' })
        .click();

    await expect(page).toHaveURL(/\/reservations$/);
    await expect(page.getByText('Reservation confirmed')).toBeVisible();
    const active = page.locator('article', { hasText: 'active' });
    await expect(
        active.getByRole('link', { name: 'Group chat' }),
    ).toBeVisible();

    await context.close();
});

test('cancelling a paid reservation well ahead refunds it and says so', async ({
    browser,
}) => {
    const { context, page } = await loginAs(browser, 'customer');

    await page.goto('/reservations');
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Cancel reservation' }).click();

    await expect(
        page.getByText('Reservation cancelled and refunded.'),
    ).toBeVisible();
    await expect(page.locator('article')).toHaveCount(0);

    const refunds = await (await context.request.get('/__e2e/refunds')).json();
    expect(refunds).toHaveLength(1);

    await context.close();
});

test('a customer who pays and closes the tab before the redirect still gets the reservation', async ({
    browser,
}) => {
    const { context, page } = await loginAs(browser, 'customer');

    // Book through the form endpoint (the same request the UI's form sends).
    const start = new Date(Date.now() + 5 * 24 * 3600 * 1000);
    const startsAt = `${start.toISOString().slice(0, 10)} 15:00`;
    const booking = await postJson(context, '/reservations', {
        starts_at: startsAt,
        duration_minutes: 60,
        group_size: 3,
    });
    expect(booking.status()).toBe(302);
    const payUrl = booking.headers()['location'];
    expect(payUrl).toContain('/__e2e/pay/');

    // Pending until paid.
    await page.goto('/reservations');
    await expect(page.locator('article', { hasText: 'pending' })).toBeVisible();

    // Pays, but the browser never reaches the success URL.
    await page.goto(payUrl);
    await page
        .getByRole('button', { name: 'Pay, then close the tab (no redirect)' })
        .click();
    await expect(page.locator('#paid')).toBeVisible();

    // Later, the reservation is there: the webhook, not the redirect, activated it.
    await page.goto('/reservations');
    await expect(page.locator('article', { hasText: 'active' })).toBeVisible();
    await expect(page.locator('article', { hasText: 'pending' })).toHaveCount(
        0,
    );

    await context.close();
});

test('two customers cannot hold the same slot', async ({ browser }) => {
    const first = await loginAs(browser, 'customer');
    const second = await loginAs(browser, 'friend');

    const start = new Date(Date.now() + 6 * 24 * 3600 * 1000);
    const body = {
        starts_at: `${start.toISOString().slice(0, 10)} 18:00`,
        duration_minutes: 60,
        group_size: 3,
    };

    const a = await postJson(first.context, '/reservations', body);
    const b = await postJson(second.context, '/reservations', body);

    // The first customer is sent to pay; the second is bounced back with a
    // validation error and never gets a checkout.
    expect(a.headers()['location']).toContain('/__e2e/pay/');
    expect(b.headers()['location'] ?? '').not.toContain('/__e2e/pay/');

    const page = await second.context.newPage();
    await page.goto('/reservations');
    await expect(page.locator('article')).toHaveCount(0);

    await first.context.close();
    await second.context.close();
});
