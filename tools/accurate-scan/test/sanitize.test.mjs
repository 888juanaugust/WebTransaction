import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cleanText, sanitizePage } from '../src/sanitize.mjs';

test('product labels survive', () => {
    for (const label of ['Pesanan Penjualan', 'No. Faktur', 'Syarat Pembayaran', 'Termasuk Pajak', 'Diskon %', 'Kts Dipesan']) {
        assert.equal(cleanText(label), label);
    }
});

test('anything that could be a record is dropped', () => {
    for (const value of [
        'budi@tokomaju.co.id',
        'Rp 1.250.000',
        '1.250.000',
        '03/10/2026',
        '2026-10-03',
        '0812 3456 7890',
        'SO.2026.10.00012',
        '01.234.567.8-901.000',
        'PT Maju Jaya Abadi',
        'Toko Sinar Motor',
        'x'.repeat(81),
    ]) {
        assert.equal(cleanText(value), null, value);
    }
});

test('a dropdown of records keeps its size, not its contents', () => {
    const page = sanitizePage({
        fields: [
            { label: 'Pelanggan', type: 'select', options: ['PT A', 'CV B', 'Toko C'] },
            { label: 'Syarat Pembayaran', type: 'select', options: ['C.O.D', 'Net 30', 'Net 45'] },
        ],
    });
    assert.deepEqual(page.fields[0], { label: 'Pelanggan', type: 'select', optionCount: 3 });
    assert.deepEqual(page.fields[1].options, ['C.O.D', 'Net 30', 'Net 45']);
});

test('values are written only from preference screens, and only short numbers or switches', () => {
    const raw = {
        fields: [
            { label: 'Izinkan stok minus', type: 'checkbox', checked: true },
            { label: 'Jatuh tempo (hari)', type: 'number', value: '30' },
            { label: 'Keterangan', type: 'text', value: 'rahasia pelanggan' },
        ],
    };
    const elsewhere = sanitizePage(raw);
    assert.equal(elsewhere.fields[0].checked, undefined);
    assert.equal(elsewhere.fields[1].value, undefined);

    const prefs = sanitizePage(raw, { preferences: true });
    assert.equal(prefs.fields[0].checked, true);
    assert.equal(prefs.fields[1].value, '30');
    assert.equal(prefs.fields[2].value, undefined);
});

test('grid columns are kept; empty grids vanish', () => {
    const page = sanitizePage({ grids: [['Kode Barang', 'Nama Barang', 'Kuantitas', 'Satuan', '@Harga'], []] });
    assert.deepEqual(page.grids, [['Kode Barang', 'Nama Barang', 'Kuantitas', 'Satuan']]);
});
