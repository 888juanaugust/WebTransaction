#!/usr/bin/env node
/**
 * Read-only structural scan of ACCURATE Online.
 *
 *   node scan.mjs --mode=recon   log in, open the database, record what the
 *                                app's markup and requests look like. Clicks
 *                                nothing past the login. Writes only to .state/
 *   node scan.mjs --mode=full    visit every menu, its list, its empty "new"
 *                                form and each tab; write docs/accurate/scan.json
 *
 * Credentials come from ACCURATE_EMAIL / ACCURATE_PASSWORD and are never
 * printed. ACCURATE_DATABASE picks the database when the account has several.
 *
 * Safety, in the order it holds:
 *   1. src/guard.mjs refuses every request that is not plainly a read, for
 *      every page in the context, and WebSockets are not connected at all.
 *   2. The crawler clicks only menu entries, tabs, "new" buttons and
 *      close/cancel buttons — mayClick() refuses Simpan, Hapus, Proses…
 *   3. sanitize.mjs keeps labels and drops anything that looks like a record.
 * A captcha or OTP stops the run; it is never worked around.
 */
import { chromium } from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { classifyRequest, describeRequest, mayClick, NEW_RECORD_BUTTON, CLOSE_BUTTON } from './src/guard.mjs';
import { extractStructure, listClickables } from './src/extract.mjs';
import { cleanText, sanitizePage } from './src/sanitize.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(here, '../..');

const args = Object.fromEntries(
    process.argv.slice(2).map((a) => {
        const [k, v] = a.replace(/^--/, '').split('=');
        return [k, v ?? true];
    }),
);

const mode = args.mode ?? 'recon';
const delay = Number(args.delay ?? 800);
const maxItems = Number(args['max-items'] ?? 500);
const only = args.only ? new RegExp(String(args.only), 'i') : null;
const stateDir = path.resolve(process.env.ACCURATE_SCAN_STATE ?? path.join(here, '.state'));
const outFile = path.resolve(args.out ?? path.join(repoRoot, 'docs/accurate/scan.json'));
const selectors = JSON.parse(await fs.readFile(path.resolve(args.selectors ?? path.join(here, 'selectors.json')), 'utf8'));

const email = process.env.ACCURATE_EMAIL;
const password = process.env.ACCURATE_PASSWORD;
if (!email || !password) {
    console.error('Set ACCURATE_EMAIL and ACCURATE_PASSWORD in the environment (never on the command line).');
    process.exit(2);
}

await fs.mkdir(stateDir, { recursive: true });

const requestLog = new Map(); // described request → {allow, reason, count}
const blocked = [];
const pause = (ms = delay) => new Promise((r) => setTimeout(r, ms));

function log(msg) {
    console.log(`[accurate-scan] ${msg}`);
}

const executablePath = process.env.ACCURATE_SCAN_CHROMIUM ?? (await exists('/opt/pw-browsers/chromium') ? '/opt/pw-browsers/chromium' : undefined);
const browser = await chromium.launch({ headless: !args.headful, executablePath });
const context = await browser.newContext({
    serviceWorkers: 'block', // a service worker's fetches would bypass the route below
    locale: 'id-ID',
    viewport: { width: 1440, height: 900 },
});

// Offline smoke test only (test/smoke.test.mjs): serve *.accurate.id from
// files. Registered before the guard, so the guard — which Playwright runs
// first, as the later route — still decides; this only answers what the
// guard let through, and records it so the test can prove no save arrived.
const fixtureHits = [];
if (process.env.ACCURATE_SCAN_FIXTURES) {
    const dir = path.resolve(process.env.ACCURATE_SCAN_FIXTURES);
    await context.route('**/*', async (route) => {
        const req = route.request();
        const u = new URL(req.url());
        fixtureHits.push(`${req.method()} ${u.hostname}${u.pathname}`);
        const base = path.join(dir, u.hostname, u.pathname === '/' ? 'index.html' : u.pathname);
        for (const file of [base, `${base}.html`, `${base}.json`]) {
            if (await exists(file) && !(await fs.stat(file)).isDirectory()) {
                const type = file.endsWith('.html') ? 'text/html' : file.endsWith('.js') ? 'text/javascript' : 'application/json';
                return route.fulfill({ status: 200, contentType: type, body: await fs.readFile(file) });
            }
        }
        return route.fulfill({ status: 404, contentType: 'application/json', body: '{}' });
    });
}

await context.route('**/*', async (route) => {
    const req = route.request();
    const verdict = classifyRequest(
        { method: req.method(), url: req.url(), resourceType: req.resourceType() },
        { allowPost: selectors.allowPost ?? [] },
    );
    const key = describeRequest({ method: req.method(), url: req.url() });
    const seen = requestLog.get(key) ?? { allow: verdict.allow, reason: verdict.reason, count: 0 };
    seen.count++;
    requestLog.set(key, seen);
    if (verdict.allow) return route.fallback();
    if (seen.count === 1) {
        blocked.push(`${key} (${verdict.reason})`);
        log(`blocked ${key} — ${verdict.reason}`);
    }
    return route.abort('blockedbyclient');
});

// A socket can carry a save as easily as a POST can, and route() cannot see
// inside it. Not connecting it is the only read-only answer; recon will show
// whether ACCURATE needs one to display anything.
await context.routeWebSocket(/.*/, (ws) => {
    blocked.push(`WS ${describeRequest({ method: 'GET', url: ws.url() })}`);
    ws.close();
});

context.on('page', (p) => p.on('dialog', (d) => d.dismiss().catch(() => {})));

let page = await context.newPage();
page.on('dialog', (d) => d.dismiss().catch(() => {}));

try {
    await login();
    page = await openDatabase();
    await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
    await pause(2000);

    if (mode === 'recon') {
        await recon();
    } else {
        await fullScan();
    }
} catch (e) {
    log(`stopped: ${e.message}`);
    process.exitCode = process.exitCode || 1;
} finally {
    if (process.env.ACCURATE_SCAN_FIXTURES) {
        await fs.writeFile(path.join(stateDir, 'fixture-hits.json'), JSON.stringify(fixtureHits, null, 2)).catch(() => {});
    }
    await writeRequestLog();
    await browser.close();
}

// ---------------------------------------------------------------------------

async function login() {
    log(`opening ${selectors.loginUrl}`);
    await page.goto(selectors.loginUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});

    const emailBox = await first(page, selectors.login.email);
    const passBox = await first(page, selectors.login.password);
    if (!emailBox || !passBox) throw new Error('login form not found — run recon with --headful and tune selectors.login');

    await emailBox.fill(email);
    await passBox.fill(password);

    if (await challengeShowing()) {
        // A captcha on the form itself is answered together with it — by the
        // person at the window, who then presses Masuk.
        if (!args.headful) {
            process.exitCode = 3;
            throw new Error('login page asks for a captcha. Run on your own machine with --headful and answer it by hand.');
        }
        log('login page shows a captcha — answer it and press Masuk in the browser window (3 minutes)');
        await page.locator('input[type=password]').filter({ visible: true }).first().waitFor({ state: 'hidden', timeout: 180000 });
    } else {
        const submit = await first(page, selectors.login.submit);
        if (submit) await submit.click();
        else await passBox.press('Enter');
    }

    await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
    await pause(1500);
    await assertNoChallenge('after login');

    if ((await page.locator('input[type=password]').filter({ visible: true }).count()) > 0) {
        throw new Error('still on the login form — wrong credentials, or the login POST was blocked (see blocked list; add its exact path to allowPost)');
    }
    log('logged in');
}

async function openDatabase() {
    const wanted = process.env.ACCURATE_DATABASE;
    let target = null;
    if (wanted) {
        target = page.getByText(wanted, { exact: false }).filter({ visible: true }).first();
        if ((await target.count()) === 0) throw new Error('ACCURATE_DATABASE not found on the database list');
    } else {
        const candidates = [];
        for (const s of selectors.database.open) {
            const loc = page.locator(s).filter({ visible: true });
            const n = await loc.count();
            if (n > 0) candidates.push({ loc, n });
        }
        if (candidates.length === 0) {
            log('no database list seen; assuming the app is already open');
            return page;
        }
        if (candidates[0].n > 1) {
            throw new Error(`${candidates[0].n} databases on this account: set ACCURATE_DATABASE to part of the one to scan`);
        }
        target = candidates[0].loc.first();
    }

    const popup = context.waitForEvent('page', { timeout: 15000 }).catch(() => null);
    await target.click();
    const opened = await popup;
    const appPage = opened ?? page;
    await appPage.waitForLoadState('domcontentloaded').catch(() => {});
    log('database opened');
    return appPage;
}

async function recon() {
    const stages = [];
    stages.push(await snapshot('app'));

    // The sidebar as it stands, and what one module click reveals — the
    // two facts the menu selectors depend on. The click is on a sidebar
    // entry that passes mayClick, nothing else.
    const sidebar = (await page.evaluate(listClickables)).filter((c) => c.x < selectors.menu.sidebarMaxX);
    const firstModule = sidebar.find((c) => mayClick(c.text) && cleanText(c.text));
    if (firstModule) {
        const before = new Set((await page.evaluate(listClickables)).map((c) => c.text));
        await clickText(firstModule.text);
        const after = await page.evaluate(listClickables);
        stages.push({
            stage: `after clicking "${cleanText(firstModule.text)}"`,
            revealed: after.filter((c) => !before.has(c.text)).map(cleanClickable).filter(Boolean),
        });
    }

    const file = path.join(stateDir, 'recon.json');
    await fs.writeFile(file, JSON.stringify({ when: new Date().toISOString(), stages, blocked }, null, 2));
    log(`recon written to ${file}`);
}

async function snapshot(stage) {
    if (args.screenshots) {
        await page.screenshot({ path: path.join(stateDir, `${stage.replace(/\W+/g, '-')}.png`), fullPage: true }).catch(() => {});
    }
    const clickables = (await page.evaluate(listClickables)).map(cleanClickable).filter(Boolean);
    return { stage, host: new URL(page.url()).hostname, title: cleanText(await page.title()), clickables };
}

function cleanClickable(c) {
    const text = cleanText(c.text);
    return text === null ? null : { ...c, text };
}

async function fullScan() {
    const result = await loadExisting();
    const modules = selectors.menu.modules.length > 0
        ? selectors.menu.modules
        : (await page.evaluate(listClickables))
            .filter((c) => c.x < selectors.menu.sidebarMaxX)
            .sort((a, b) => a.y - b.y)
            .map((c) => cleanText(c.text))
            .filter((t, i, all) => t && mayClick(t) && all.indexOf(t) === i);

    log(`${modules.length} modules: ${modules.join(', ')}`);
    let visited = 0;

    for (const moduleLabel of modules) {
        if (only && !only.test(moduleLabel)) continue;
        const items = await submenuOf(moduleLabel);
        log(`${moduleLabel}: ${items.length} entries`);
        const mod = (result.modules[moduleLabel] ??= { items: {} });

        for (const item of items) {
            if (visited++ >= maxItems) return save(result);
            if (mod.items[item]) continue; // resumable: a rerun skips what is done

            try {
                await clickText(moduleLabel);
                await clickText(item);
                await settle();
                const kind = /laporan|report/i.test(moduleLabel) ? 'report' : /preferensi|preference/i.test(item) ? 'preferences' : 'screen';
                const entry = { kind, view: await read(kind === 'preferences') };
                entry.view.tabsRead = await readTabs(kind === 'preferences');

                if (kind === 'screen' && (await openNewRecord())) {
                    entry.form = await read(false);
                    entry.form.tabsRead = await readTabs(false);
                    await closeForm();
                }
                mod.items[item] = entry;
                log(`  ✓ ${item}`);
            } catch (e) {
                mod.items[item] = { error: cleanText(e.message) ?? 'error' };
                log(`  ✗ ${item}: ${e.message}`);
                await page.keyboard.press('Escape').catch(() => {});
            }
            await save(result);
        }
    }
    await save(result);
}

async function submenuOf(moduleLabel) {
    const before = new Set((await page.evaluate(listClickables)).map((c) => c.text));
    await clickText(moduleLabel);
    await pause();
    const after = await page.evaluate(listClickables);
    const items = after
        .filter((c) => !before.has(c.text))
        .map((c) => cleanText(c.text))
        .filter((t, i, all) => t && mayClick(t) && all.indexOf(t) === i);
    await page.keyboard.press('Escape').catch(() => {});
    return items;
}

async function read(preferences) {
    return sanitizePage(await page.evaluate(extractStructure), { preferences });
}

async function readTabs(preferences) {
    const tabs = (await page.evaluate(extractStructure)).tabs.map(cleanText).filter(Boolean);
    const out = {};
    for (const tab of tabs.slice(0, 15)) {
        if (!mayClick(tab)) continue;
        try {
            await page.getByRole('tab', { name: tab, exact: true }).first().click({ timeout: 3000 });
            await settle();
            out[tab] = await read(preferences);
        } catch {
            // A tab that will not click is recorded by name only.
            out[tab] = null;
        }
    }
    return out;
}

async function openNewRecord() {
    const buttons = page.locator('button, [role=button], a.btn, a').filter({ visible: true });
    const n = Math.min(await buttons.count(), 80);
    for (let i = 0; i < n; i++) {
        const b = buttons.nth(i);
        const text = ((await b.innerText().catch(() => '')) || (await b.getAttribute('title')) || (await b.getAttribute('aria-label')) || '').trim();
        if (NEW_RECORD_BUTTON.test(text) && mayClick(text)) {
            await b.click({ timeout: 3000 });
            await settle();
            return true;
        }
    }
    return false;
}

async function closeForm() {
    await page.keyboard.press('Escape').catch(() => {});
    await pause(400);
    const buttons = page.locator('button, [role=button], a.btn').filter({ visible: true });
    const n = Math.min(await buttons.count(), 80);
    for (let i = 0; i < n; i++) {
        const b = buttons.nth(i);
        const text = (await b.innerText().catch(() => '')).trim();
        if (CLOSE_BUTTON.test(text) && mayClick(text)) {
            await b.click({ timeout: 2000 }).catch(() => {});
            await pause(400);
            return;
        }
    }
}

async function clickText(text) {
    if (!mayClick(text)) throw new Error(`refusing to click "${text}"`);
    const loc = page.getByText(text, { exact: true }).filter({ visible: true }).first();
    await loc.click({ timeout: 5000 });
    await pause();
}

async function settle() {
    await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
    await pause();
}

async function challengeShowing() {
    const re = new RegExp(selectors.challenge, 'i');
    const frames = await page.locator('iframe[src*="captcha"], iframe[src*="recaptcha"], iframe[src*="hcaptcha"]').count();
    const body = await page.locator('body').innerText().catch(() => '');
    return frames > 0 || re.test(body);
}

async function assertNoChallenge(where) {
    if (!(await challengeShowing())) return;

    // A person at the keyboard may answer it; the scan never does.
    if (args.headful) {
        log(`${where}: a captcha or verification code is showing — complete it in the browser window (3 minutes)`);
        const until = Date.now() + 180000;
        while (Date.now() < until) {
            await pause(2000);
            if (!(await challengeShowing())) {
                await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
                return;
            }
        }
    }
    process.exitCode = 3;
    throw new Error(`${where}: a captcha or verification code is being asked for. The scan does not get past these — run it on your own machine with --headful and complete the step by hand.`);
}

async function first(p, list) {
    for (const s of list ?? []) {
        const loc = p.locator(s).filter({ visible: true }).first();
        if ((await loc.count()) > 0) return loc;
    }
    return null;
}

async function loadExisting() {
    try {
        const existing = JSON.parse(await fs.readFile(outFile, 'utf8'));
        if (existing && existing.modules) return existing;
    } catch {
        // first run
    }
    return { scannedAt: null, modules: {} };
}

async function save(result) {
    result.scannedAt = new Date().toISOString();
    result.blockedRequests = [...new Set(blocked)];
    await fs.mkdir(path.dirname(outFile), { recursive: true });
    await fs.writeFile(outFile, JSON.stringify(result, null, 2) + '\n');
}

async function writeRequestLog() {
    const rows = [...requestLog.entries()].map(([k, v]) => ({ request: k, ...v })).sort((a, b) => a.request.localeCompare(b.request));
    await fs.writeFile(path.join(stateDir, 'requests.json'), JSON.stringify(rows, null, 2)).catch(() => {});
    const refused = rows.filter((r) => !r.allow).length;
    log(`${rows.length} distinct requests, ${refused} refused — ${path.join(stateDir, 'requests.json')}`);
}

async function exists(p) {
    try {
        await fs.access(p);
        return true;
    } catch {
        return false;
    }
}
