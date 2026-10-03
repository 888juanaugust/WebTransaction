/**
 * The whole scanner, in a real browser, against a fake ACCURATE served from
 * test/fixtures. The fake does what a careless scan would trip over: it
 * autosaves a draft when a form opens, holds a WebSocket, has Simpan and Hapus
 * buttons, and shows customer names and amounts in its lists.
 *
 * Skipped when playwright is not installed (npm install in this directory).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const tool = path.resolve(here, '..');
const hasPlaywright = await fs.access(path.join(tool, 'node_modules/playwright')).then(() => true, () => false);

test('a full scan reads the structure, refuses every write, and writes no records', { skip: !hasPlaywright, timeout: 120000 }, async () => {
    const tmp = await fs.mkdtemp(path.join(os.tmpdir(), 'accurate-scan-'));
    const out = path.join(tmp, 'scan.json');

    await promisify(execFile)(process.execPath, ['scan.mjs', '--mode=full', '--delay=100', `--out=${out}`], {
        cwd: tool,
        env: {
            ...process.env,
            ACCURATE_EMAIL: 'scan@example.test',
            ACCURATE_PASSWORD: 'not-a-real-password',
            ACCURATE_SCAN_STATE: tmp,
            ACCURATE_SCAN_FIXTURES: path.join(here, 'fixtures'),
        },
        timeout: 110000,
    });

    const raw = await fs.readFile(out, 'utf8');
    const scan = JSON.parse(raw);
    const hits = JSON.parse(await fs.readFile(path.join(tmp, 'fixture-hits.json'), 'utf8'));

    // Nothing that saves, deletes or phones home reached "ACCURATE".
    for (const hit of hits) {
        assert.doesNotMatch(hit, /save|delete|google-analytics/i, hit);
    }
    assert.ok(hits.some((h) => h.includes('/accurate/api/item-transfer/list.do')), 'module reads still load');
    assert.ok(scan.blockedRequests.some((b) => b.includes('save-draft.do')), 'the autosave was refused');
    assert.ok(scan.blockedRequests.some((b) => b.startsWith('WS ')), 'the socket was refused');

    // The structure is there.
    const faktur = scan.modules.Penjualan.items['Faktur Penjualan'];
    assert.deepEqual(faktur.view.columns, ['No. Faktur', 'Tanggal', 'Pelanggan', 'Total']);
    const fields = Object.fromEntries(faktur.form.fields.map((f) => [f.label, f]));
    assert.equal(fields['Pelanggan *'].optionCount, 2);
    assert.deepEqual(fields['Syarat Pembayaran'].options, ['C.O.D', 'Net 30']);
    assert.equal(fields['Termasuk Pajak'].type, 'checkbox');
    assert.deepEqual(faktur.form.grids[0], ['Kode Barang', 'Nama Barang', 'Kuantitas', 'Harga Satuan']);
    assert.ok(faktur.form.tabsRead['Info Lainnya'].fields.some((f) => f.label === 'Keterangan'));
    assert.ok(scan.modules.Persediaan.items['Pemindahan Barang']);

    const prefs = scan.modules.Pengaturan.items.Preferensi;
    assert.equal(prefs.kind, 'preferences');
    const stok = prefs.view.tabsRead.Persediaan.fields.find((f) => f.label === 'Izinkan stok minus');
    assert.equal(stok.checked, true);
    assert.equal(prefs.view.tabsRead.Penjualan.fields.find((f) => f.label.startsWith('Jatuh tempo')).value, '30');

    // And no record made it to disk.
    for (const record of ['PT Maju Jaya', 'Rp 1.250.000', 'SI.2026.10.00012', 'SI.2026.10.00013', '03/10/2026', 'PT A', 'CV B', 'scan@example.test', 'PT Contoh Usaha']) {
        assert.ok(!raw.includes(record), `"${record}" was written`);
    }

    // The renderer turns it into pages.
    const docs = path.join(tmp, 'docs');
    await promisify(execFile)(process.execPath, ['render.mjs', out, docs], { cwd: tool });
    const penjualan = await fs.readFile(path.join(docs, 'modul/penjualan.md'), 'utf8');
    assert.match(penjualan, /## Faktur Penjualan/);
    assert.match(penjualan, /Kode Barang · Nama Barang · Kuantitas · Harga Satuan/);
    assert.match(await fs.readFile(path.join(docs, 'preferensi.md'), 'utf8'), /Izinkan stok minus \| checkbox \| ✔ aktif/);
});
