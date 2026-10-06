import { defineConfig } from '@playwright/test';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// A throwaway SQLite file and cache/session tables inside it: the browser
// tests never touch the development database or Stripe (E2E_MODE swaps
// Stripe for a fake payment page — see AppServiceProvider::registerE2eMode()).
export const E2E_DB = join(tmpdir(), 'skatepark-e2e.sqlite');
const PORT = 8002;

export const e2eEnv = {
    APP_ENV: 'e2e',
    APP_DEBUG: 'false',
    APP_URL: `http://127.0.0.1:${PORT}`,
    E2E_MODE: '1',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: E2E_DB,
    SESSION_DRIVER: 'database',
    CACHE_STORE: 'database',
    BROADCAST_CONNECTION: 'null',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array',
    STRIPE_KEY: 'pk_test_e2e',
    STRIPE_SECRET: 'sk_test_e2e',
    STRIPE_WEBHOOK_SECRET: '',
    BCRYPT_ROUNDS: '4',
    PHP_CLI_SERVER_WORKERS: '1',
};

export default defineConfig({
    testDir: './tests/e2e',
    // One server, one database: tests share state in order, so they run serially.
    workers: 1,
    fullyParallel: false,
    retries: 0,
    timeout: 30_000,
    reporter: [['list']],
    globalSetup: './tests/e2e/global-setup.ts',
    globalTeardown: './tests/e2e/global-teardown.ts',
    use: {
        baseURL: `http://127.0.0.1:${PORT}`,
        // English UI, so assertions don't depend on the default Latvian.
        storageState: undefined,
        trace: 'retain-on-failure',
    },
    webServer: {
        command: `php artisan serve --host=127.0.0.1 --port=${PORT} --no-reload`,
        url: `http://127.0.0.1:${PORT}/up`,
        reuseExistingServer: false,
        timeout: 60_000,
        env: e2eEnv,
    },
});
