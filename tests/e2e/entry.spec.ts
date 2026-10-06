import { expect, test } from '@playwright/test';
import { loginAs, postJson } from './helpers';

/*
| QR entry, through the real pages: the customer's pass shows a rotating
| signed code, staff redeem it once, and replaying it is refused.
*/

test.describe.configure({ mode: 'serial' });

test('a customer scanned in by staff is shown as checked in, and the same code cannot be replayed', async ({
    browser,
}) => {
    const customer = await loginAs(browser, 'customer');
    const staff = await loginAs(browser, 'staff');

    await customer.page.goto('/dashboard');
    await expect(customer.page.getByText('Not checked in')).toBeVisible();
    await expect(customer.page.locator('canvas')).toBeVisible();

    // What the pass's QR encodes right now.
    const token = (
        await (
            await customer.context.request.get('/dashboard/entry-token')
        ).json()
    ).token as string;
    expect(token.split('.')).toHaveLength(4);

    const scan = await postJson(staff.context, '/staff/scan', {
        code: token,
        mode: 'entry',
    });
    expect(scan.status()).toBe(200);
    expect(await scan.json()).toMatchObject({
        allowed: true,
        checked_in: true,
    });

    await customer.page.reload();
    await expect(
        customer.page.getByText('Checked in', { exact: true }),
    ).toBeVisible();

    // A screenshot of that code (or the same code again) gets nobody in.
    const replay = await postJson(staff.context, '/staff/scan', {
        code: token,
        mode: 'entry',
    });
    expect(replay.status()).toBe(409);

    // Staff check the customer out with a fresh code.
    const fresh = (
        await (
            await customer.context.request.get('/dashboard/entry-token')
        ).json()
    ).token as string;
    const exit = await postJson(staff.context, '/staff/scan', {
        code: fresh,
        mode: 'exit',
    });
    expect(exit.status()).toBe(200);

    await customer.context.close();
    await staff.context.close();
});

test('the permanent user id is not a valid QR code, and the page never contains it', async ({
    browser,
}) => {
    const customer = await loginAs(browser, 'customer');
    const staff = await loginAs(browser, 'staff');

    await customer.page.goto('/dashboard');
    const html = await customer.page.content();
    // Tokens are `{qr_code}.{expiry}.{nonce}.{sig}`; the bare id must not be embedded anywhere.
    const token = (
        await (
            await customer.context.request.get('/dashboard/entry-token')
        ).json()
    ).token as string;
    const bareId = token.split('.')[0];
    expect(html).not.toContain(bareId);

    const scan = await postJson(staff.context, '/staff/scan', {
        code: bareId,
        mode: 'entry',
    });
    expect(scan.status()).toBe(404);

    await customer.context.close();
    await staff.context.close();
});
