import { rmSync } from 'node:fs';
import { E2E_DB } from '../../playwright.config';

export default function globalTeardown() {
    for (const suffix of ['', '-wal', '-shm']) {
        rmSync(E2E_DB + suffix, { force: true });
    }
}
