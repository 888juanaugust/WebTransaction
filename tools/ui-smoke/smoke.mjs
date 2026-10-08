#!/usr/bin/env node
/**
 * Opens the running app in Chromium, logs in as the administrator and
 * screenshots the pages given (default: the dashboard), so a change to the
 * theme or a screen can be seen, not just tested.
 *
 *   php artisan serve --port 8000 &
 *   npm run smoke -- [/admin /admin/customer/sales-invoice ...]
 *
 * Writes storage/app/smoke/<slug>.png (gitignored). Playwright is a dev
 * dependency of the root package.json; a pre-installed Chromium at
 * /opt/pw-browsers/chromium is used when present, else Playwright's own.
 */
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');

const base = process.env.APP_URL_SMOKE ?? 'http://127.0.0.1:8000';
const email = process.env.ADMIN_EMAIL ?? 'admin@example.test';
const password = process.env.ADMIN_PASSWORD || 'password';
const pages = process.argv.slice(2).length ? process.argv.slice(2) : ['/admin'];
const out = path.join(root, 'storage/app/smoke');
await fs.mkdir(out, { recursive: true });

const executablePath = await fs.access('/opt/pw-browsers/chromium').then(() => '/opt/pw-browsers/chromium', () => undefined);
const browser = await chromium.launch({ headless: true, executablePath });
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
try {
    await page.goto(`${base}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[type=email]', email);
    await page.fill('input[type=password]', password);
    await page.click('button[type=submit]');
    await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 20000 });
    for (const p of pages) {
        await page.goto(`${base}${p}`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(500);
        const file = path.join(out, `${p.replace(/^\/+/, '').replace(/[^\w-]+/g, '-') || 'admin'}.png`);
        await page.screenshot({ path: file, fullPage: true });
        console.log(`${page.url()} → ${file}`);
    }
} catch (e) {
    await page.screenshot({ path: path.join(out, 'failure.png'), fullPage: true }).catch(() => {});
    console.error(`smoke failed on ${page.url()}: ${e.message.split('\n')[0]} (see storage/app/smoke/failure.png)`);
    process.exitCode = 1;
} finally {
    await browser.close();
}
