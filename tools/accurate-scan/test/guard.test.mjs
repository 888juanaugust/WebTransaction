import { test } from 'node:test';
import assert from 'node:assert/strict';
import { classifyRequest, describeRequest, mayClick, NEW_RECORD_BUTTON } from '../src/guard.mjs';

const api = (path) => `https://zeus.accurate.id/accurate/api/${path}`;

test('every save, delete and approve call is refused, whatever the verb', () => {
    for (const path of [
        'sales-invoice/save.do',
        'sales-invoice/delete.do',
        'sales-order/approve.do',
        'item/bulk-save.do',
        'customer/import.do',
        'purchase-order/close.do',
        'journal-voucher/void.do',
        'sales-invoice/print.do',
        'period/lock.do',
        'database-backup/run.do',
        'item/set-default.do',
    ]) {
        for (const method of ['POST', 'GET', 'PUT', 'DELETE']) {
            assert.equal(classifyRequest({ method, url: api(path) }).allow, false, `${method} ${path}`);
        }
    }
});

test('a module whose name sounds like a write still lists', () => {
    // Pemindahan barang is item-transfer; its list is a read.
    assert.equal(classifyRequest({ method: 'POST', url: api('item-transfer/list.do') }).allow, true);
    assert.equal(classifyRequest({ method: 'GET', url: api('bank-transfer/detail.do?id=4') }).allow, true);
    // ...but its save is not.
    assert.equal(classifyRequest({ method: 'POST', url: api('item-transfer/save.do') }).allow, false);
});

test('reads by POST are allowed only when the action says read', () => {
    assert.equal(classifyRequest({ method: 'POST', url: api('sales-order/list.do') }).allow, true);
    assert.equal(classifyRequest({ method: 'POST', url: api('customer/detail.do') }).allow, true);
    assert.equal(classifyRequest({ method: 'POST', url: api('preference/load.do') }).allow, true);
    // An action that says nothing is a write until a person decides otherwise.
    assert.equal(classifyRequest({ method: 'POST', url: api('sales-order/x.do') }).allow, false);
});

test('a write word in the query string is enough to refuse', () => {
    assert.equal(classifyRequest({ method: 'GET', url: api('dispatch.do?action=save') }).allow, false);
});

test('the login exchange gets through, and its words unlock nothing else', () => {
    assert.equal(classifyRequest({ method: 'POST', url: 'https://account.accurate.id/api/login.do' }).allow, true);
    assert.equal(classifyRequest({ method: 'POST', url: 'https://account.accurate.id/oauth/token' }).allow, true);
    assert.equal(classifyRequest({ method: 'POST', url: 'https://account.accurate.id/api/open-db.do' }).allow, true);
    assert.equal(classifyRequest({ method: 'POST', url: api('session/save.do') }).allow, false);
});

test('an allow-listed path is exact, not a prefix', () => {
    const opts = { allowPost: ['/j_security_check'] };
    assert.equal(classifyRequest({ method: 'POST', url: 'https://account.accurate.id/j_security_check' }, opts).allow, true);
    assert.equal(classifyRequest({ method: 'POST', url: 'https://account.accurate.id/j_security_check/x' }, opts).allow, false);
});

test('third parties may serve files but receive nothing', () => {
    assert.equal(classifyRequest({ method: 'GET', url: 'https://cdn.example.com/app.js', resourceType: 'script' }).allow, true);
    assert.equal(classifyRequest({ method: 'POST', url: 'https://www.google-analytics.com/g/collect' }).allow, false);
});

test('a lookalike host is not ACCURATE', () => {
    assert.equal(classifyRequest({ method: 'POST', url: 'https://accurate.id.evil.example/api/list.do' }).allow, false);
    assert.equal(classifyRequest({ method: 'POST', url: 'https://notaccurate.id/api/list.do' }).allow, false);
});

test('logged requests carry no ids and no query string', () => {
    assert.equal(
        describeRequest({ method: 'post', url: api('sales-invoice/detail.do?id=123&q=PT%20Maju') }),
        'POST zeus.accurate.id/accurate/api/sales-invoice/detail.do',
    );
    assert.equal(describeRequest({ method: 'GET', url: api('item/4567/photo') }), 'GET zeus.accurate.id/accurate/api/item/:n/photo');
});

test('the crawler never presses a button that commits anything', () => {
    for (const label of ['Simpan', 'Simpan & Baru', 'Hapus', 'Proses', 'Setujui', 'Tutup Buku', 'Impor', 'Cetak', 'Batalkan Transaksi']) {
        assert.equal(mayClick(label), false, label);
    }
    for (const label of ['Batal', 'Tutup', 'Kembali', '+ Tambah', 'Pesanan Penjualan']) {
        assert.equal(mayClick(label), true, label);
    }
});

test('the new-record button is recognised however it is written', () => {
    for (const label of ['+ Tambah', '+', 'Tambah', 'Baru', 'Buat Faktur', '+ Data Baru']) {
        assert.match(label, NEW_RECORD_BUTTON, label);
    }
    for (const label of ['Tambahan Biaya Lain', 'Pembaruan', 'Simpan']) {
        assert.doesNotMatch(label, NEW_RECORD_BUTTON, label);
    }
});
