import type { Browser, BrowserContext, Page } from '@playwright/test';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const PASSWORD = 'E2e-Passw0rd!x';

export const AUTH_DIR = join(dirname(fileURLToPath(import.meta.url)), '.auth');

/**
 * A fresh browser context already logged in as a seeded user (sessions are
 * created once, through the real login form, in global-setup).
 */
export async function loginAs(
    browser: Browser,
    who: 'customer' | 'staff' | 'friend',
): Promise<{ context: BrowserContext; page: Page }> {
    const context = await browser.newContext({
        baseURL: 'http://127.0.0.1:8002',
        storageState: join(AUTH_DIR, `${who}.json`),
    });

    return { context, page: await context.newPage() };
}

/** Laravel's CSRF token as the frontend sends it (XSRF-TOKEN cookie). */
export async function xsrf(context: BrowserContext): Promise<string> {
    const cookies = await context.cookies();
    const token = cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';

    return decodeURIComponent(token);
}

export async function postJson(
    context: BrowserContext,
    path: string,
    data: Record<string, unknown>,
) {
    return context.request.post(path, {
        data,
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': await xsrf(context),
        },
        maxRedirects: 0,
    });
}
