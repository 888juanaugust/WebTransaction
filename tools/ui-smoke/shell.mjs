#!/usr/bin/env node
/**
 * Drives the workspace shell in Chromium and fails on the first broken
 * behaviour: tiles open tabs, a tab keeps what was typed while another tab is
 * in front, the tab list survives a reload, a deep link opens as a tab, a link
 * to another screen inside a tab opens a new tab, closing a tab with unsaved
 * changes asks first. Screenshots land in storage/app/smoke/shell-*.png.
 *
 *   php artisan serve --port 8000 &
 *   npm run smoke:shell
 */
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const base = process.env.APP_URL_SMOKE ?? 'http://127.0.0.1:8000';
const email = process.env.ADMIN_EMAIL ?? 'admin@example.test';
const password = process.env.ADMIN_PASSWORD || 'password';
const out = path.join(root, 'storage/app/smoke');
await fs.mkdir(out, { recursive: true });

const executablePath = await fs.access('/opt/pw-browsers/chromium').then(() => '/opt/pw-browsers/chromium', () => undefined);
const browser = await chromium.launch({ headless: true, executablePath });
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));

let failures = 0;
function check(condition, message) {
    console.log(`${condition ? 'ok  ' : 'FAIL'} ${message}`);
    if (!condition) failures++;
}
const tabs = async () => (await page.locator('.ae-tab .ae-tab-title').allInnerTexts()).map((t) => t.trim());
const frameFor = (fragment) => page.frames().find((frame) => frame.url().includes(fragment));
async function openTile(group, key) {
    await page.click(`[data-group-btn="${group}"]`);
    await page.click(`.ae-flyout[data-group="${group}"] .ae-tile[data-key="${key}"]`);
    await page.waitForTimeout(2000);
}

try {
    await page.goto(`${base}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[type=email]', email);
    await page.fill('input[type=password]', password);
    await page.click('button[type=submit]');
    await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 20000 });
    await page.evaluate(() => Object.keys(localStorage).filter((key) => key.startsWith('ae.tabs')).forEach((key) => localStorage.removeItem(key)));
    await page.reload({ waitUntil: 'networkidle' });
    check((await tabs()).join() === 'Dashboard', 'the workspace opens on the pinned dashboard tab');

    await page.click('[data-group-btn="company"]');
    await page.waitForTimeout(300);
    await page.screenshot({ path: path.join(out, 'shell-flyout.png') });
    await page.keyboard.press('Escape');

    await openTile('sales', 'customer__sales-invoice');
    const invoices = frameFor('/customer/sales-invoice');
    await invoices.click('text=New sales invoice');
    await invoices.waitForURL(/create/, { timeout: 15000 });
    await invoices.waitForLoadState('networkidle');
    const notes = invoices.locator('textarea').first();
    await invoices.click('.fi-sc-tabs.fi-vertical .fi-tabs-item:nth-child(2)');
    await notes.fill('Typed before switching tabs');
    check((await tabs()).includes('Create Sales Invoice'), 'a tab follows its frame to the create page and takes its title');
    await page.screenshot({ path: path.join(out, 'shell-form.png') });

    await openTile('inventory', 'inventory__item');
    check((await tabs()).includes('Items & Services'), 'a second tile opens a second tab');
    await page.locator('.ae-tab', { hasText: 'Create Sales Invoice' }).click();
    await page.waitForTimeout(300);
    check((await frameFor('/customer/sales-invoice/create').locator('textarea').first().inputValue()) === 'Typed before switching tabs', 'the form keeps what was typed while another tab was in front');

    const items = frameFor('/inventory/item');
    await page.locator('.ae-tab', { hasText: 'Items & Services' }).click();
    await items.evaluate(() => { const link = document.createElement('a'); link.href = '/admin/inventory/warehouse'; document.body.appendChild(link); link.click(); });
    await page.waitForTimeout(2000);
    check((await tabs()).includes('Warehouses'), 'a link to another screen inside a tab opens a new tab');
    check(frameFor('/inventory/item') !== undefined, 'the tab the link was in stays on its screen');
    await page.screenshot({ path: path.join(out, 'shell-tabs.png') });

    let asked = false;
    page.once('dialog', async (dialog) => { asked = true; await dialog.dismiss(); });
    await page.locator('.ae-tab', { hasText: 'Create Sales Invoice' }).locator('.ae-tab-close').click();
    await page.waitForTimeout(300);
    check(asked && (await tabs()).includes('Create Sales Invoice'), 'closing a tab with unsaved changes asks first, and cancelling keeps it');

    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    const reopened = await tabs();
    check(['Items & Services', 'Warehouses'].every((title) => reopened.includes(title)), 'the open tabs come back after a reload');

    await page.goto(`${base}/admin/vendor/vendor`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    check(new URL(page.url()).pathname === '/admin' && (await tabs()).some((title) => title.startsWith('Vendor')), 'a deep link opens as a tab of the workspace');

    await page.setViewportSize({ width: 390, height: 844 });
    await page.click('.ae-menu-btn');
    await page.waitForTimeout(300);
    await page.screenshot({ path: path.join(out, 'shell-phone.png') });
} finally {
    await browser.close();
}

check(errors.length === 0, `no script errors${errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''}`);
process.exit(failures ? 1 : 0);
