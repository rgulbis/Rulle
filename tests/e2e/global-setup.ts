import { chromium, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, rmSync } from 'node:fs';
import { E2E_DB, e2eEnv } from '../../playwright.config';
import { AUTH_DIR, PASSWORD } from './helpers';

export default async function globalSetup() {
    for (const suffix of ['', '-wal', '-shm']) {
        rmSync(E2E_DB + suffix, { force: true });
    }

    const run = (...args: string[]) =>
        execFileSync('php', ['artisan', ...args], {
            env: { ...process.env, ...e2eEnv },
            stdio: 'inherit',
        });

    run('migrate:fresh', '--force');
    run('e2e:seed');

    // Log each seeded user in once, through the real form, and keep the
    // session: the login endpoint is rate-limited (5/min), and the specs
    // shouldn't each pay for a login anyway.
    rmSync(AUTH_DIR, { recursive: true, force: true });
    mkdirSync(AUTH_DIR, { recursive: true });

    const browser = await chromium.launch();

    for (const who of ['customer', 'staff', 'friend']) {
        const context = await browser.newContext({ baseURL: e2eEnv.APP_URL });
        // The UI in English, so assertions don't depend on the Latvian default.
        await context.addCookies([
            { name: 'locale', value: 'en', url: e2eEnv.APP_URL },
        ]);
        const page = await context.newPage();

        await page.goto('/login');
        await page.waitForLoadState('networkidle');
        await page.getByPlaceholder(/@/).fill(`e2e-${who}@test.test`);
        await page.locator('input[type="password"]').fill(PASSWORD);
        await page.locator('button[type="submit"]').click();
        await expect(page).not.toHaveURL(/\/login/);

        await context.storageState({ path: `${AUTH_DIR}/${who}.json` });
        await context.close();
    }

    await browser.close();
}
