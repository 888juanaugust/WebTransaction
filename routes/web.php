<?php

declare(strict_types=1);

use App\Http\Controllers\FakturController;
use App\Http\Controllers\FakturExportController;
use App\Http\Controllers\GantiWilayahController;
use App\Http\Controllers\NotaKreditController;
use App\Http\Controllers\PenawaranController;
use App\Http\Controllers\PesananPembelianController;
use App\Http\Controllers\RekeningPelangganController;
use App\Http\Controllers\ReturPembelianController;
use App\Http\Controllers\SuratJalanController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Situs publik
|--------------------------------------------------------------------------
|
| Open and indexed. No prices appear on any of these pages — wholesale
| pricing is per customer, and public price display is out of scope for v1.
|
| Content comes from config/perusahaan.php, not from these templates.
|
*/

Route::view('/', 'publik.beranda')->name('publik.beranda');
Route::view('/tentang-kami', 'publik.tentang')->name('publik.tentang');
Route::view('/mitra', 'publik.mitra')->name('publik.mitra');
Route::view('/rencana-pengembangan', 'publik.rencana')->name('publik.rencana');
Route::view('/kontak', 'publik.kontak')->name('publik.kontak');

/*
 * Legal pages. Public and indexable like the rest, but kept out of the main
 * nav — nobody navigates to a privacy policy, they follow a link to it from
 * the footer or from a form. Putting them in the nav costs a slot that a
 * customer looking for the catalogue needs.
 *
 * Kebijakan Privasi is required under UU PDP 27/2022 before the system is used
 * by real users, and is a prerequisite for PSE Lingkup Privat registration.
 */
Route::view('/kebijakan-privasi', 'publik.kebijakan-privasi')->name('publik.privasi');
Route::view('/syarat-penjualan', 'publik.syarat-penjualan')->name('publik.syarat');

/*
 * Login chooser. Staff and buyers authenticate on different guards against
 * different tables, so this page routes to one of two panels rather than
 * trying to work out which kind of account an address belongs to.
 *
 *   /admin   staff   (web guard, users)
 *   /portal  buyers  (customer guard, customer_users)
 */
Route::view('/masuk', 'publik.masuk')->name('masuk');

/*
|--------------------------------------------------------------------------
| Dokumen cetak
|--------------------------------------------------------------------------
|
| Surat jalan. Behind the staff guard, and the controller additionally checks
| the role — picking and shipping is the warehouse's job, and this document
| carries no prices precisely because it is handed to a driver and then to
| whoever signs for the goods.
|
| A route rather than a Filament page: it renders its own bare HTML so it
| prints identically from any machine, with no panel chrome to strip.
|
*/
/*
 * `auth:web`, not a bare `auth`. Bare `auth` resolves whatever the *default*
 * guard happens to be at the time, and this application has two — so a staff
 * document would quietly start accepting buyer sessions the day anything
 * changed the default. Naming the guard costs four characters.
 */
Route::middleware(['web', 'auth:web'])
    ->get('/dokumen/surat-jalan/{order}', SuratJalanController::class)
    ->name('dokumen.surat-jalan');

/*
 * The Owner switching which region they are looking at. POST because it
 * changes state; the controller refuses anyone whose region is pinned on
 * their account.
 */
Route::middleware(['web', 'auth:web'])
    ->post('/admin/wilayah-aktif', GantiWilayahController::class)
    ->name('admin.wilayah-aktif');

/*
 * Faktur — the invoice, and the surat jalan's opposite number: all money, down
 * to DPP and PPN per line.
 *
 * Two routes rather than one, because staff and buyers are different guards
 * against different tables. A single route carrying `auth:web,customer` would
 * authenticate on whichever guard answered first, and the controller would then
 * have to work out which kind of visitor it was talking to before deciding what
 * they may see. One route, one guard, one rule.
 *
 * Both render the same document from the same figures — a customer and the
 * salesperson discussing an invoice on the phone must be looking at the same
 * page.
 */
Route::middleware(['web', 'auth:web'])
    ->get('/dokumen/faktur/{invoice}', [FakturController::class, 'staff'])
    ->name('dokumen.faktur');

Route::middleware(['web', 'auth:customer'])
    ->get('/portal/dokumen/faktur/{invoice}', [FakturController::class, 'pelanggan'])
    ->name('portal.dokumen.faktur');

/*
 * Nota kredit — the faktur run backwards, and the same two-route shape for the
 * same reason. A customer arguing about a return and the salesperson who agreed
 * to it must be reading one document.
 */
Route::middleware(['web', 'auth:web'])
    ->get('/dokumen/nota-kredit/{creditNote}', [NotaKreditController::class, 'staff'])
    ->name('dokumen.nota-kredit');

Route::middleware(['web', 'auth:customer'])
    ->get('/portal/dokumen/nota-kredit/{creditNote}', [NotaKreditController::class, 'pelanggan'])
    ->name('portal.dokumen.nota-kredit');

/*
 * Rekening koran pelanggan — the statement a customer gets before they pay.
 *
 * Staff only, and no portal twin. A buyer's own account is already on their
 * portal dashboard; this is the version somebody sends with a covering
 * message when the two sides disagree about a figure. Its window comes from
 * the query string, because unlike a faktur this document has no period of
 * its own.
 */
Route::middleware(['web', 'auth:web'])
    ->get('/dokumen/rekening-pelanggan/{company}', RekeningPelangganController::class)
    ->name('dokumen.rekening-pelanggan');

/*
 * Pesanan pembelian — the first of the three print documents that travels
 * outward. The surat jalan goes with our goods and the faktur goes to our
 * customer; this one is emailed to a supplier and asks them to ship something.
 *
 * Behind canRecordPurchases(), like every other screen that shows what we pay.
 */
Route::middleware(['web', 'auth:web'])
    ->get('/dokumen/pesanan-pembelian/{purchaseOrder}', PesananPembelianController::class)
    ->name('dokumen.pesanan-pembelian');

/*
 * Nota retur — goes back to the supplier with the goods.
 *
 * The second outward-travelling document, and the one that turns our decision
 * into their obligation: under the PPN rules the buyer issues the nota retur,
 * so this carries our number and their credit note comes back against it.
 *
 * No portal twin. Nothing about a purchase return is a buyer's business.
 */
Route::middleware(['web', 'auth:web'])
    ->get('/dokumen/retur-pembelian/{purchaseReturn}', ReturPembelianController::class)
    ->name('dokumen.retur-pembelian');

/*
 * The faktur pajak export file, served from disk rather than regenerated.
 *
 * Not a printable document like the three above — this one is uploaded to the
 * tax office — but it belongs here for the same reason: it is a file we hand
 * to somebody outside, and what was actually handed over has to stay
 * retrievable.
 */
Route::middleware(['web', 'auth:web'])
    ->get('/dokumen/faktur-pajak/{fakturExport}', FakturExportController::class)
    ->name('faktur-pajak.unduh');

/*
|--------------------------------------------------------------------------
| Untuk mesin pencari
|--------------------------------------------------------------------------
|
| Both served from routes rather than static files because both need the
| application's own URL: the sitemap protocol requires absolute locations,
| and robots.txt points crawlers at the sitemap. A static file would carry
| whatever hostname somebody hard-coded on the day it was written.
|
| The panels are disallowed not as a security measure — they sit behind
| logins — but because a crawler knocking on /admin is pure log noise.
|
*/
Route::get('/robots.txt', function () {
    return response(implode("\n", [
        'User-agent: *',
        'Disallow: /admin',
        'Disallow: /portal',
        'Disallow: /dokumen',
        'Allow: /',
        '',
        'Sitemap: '.route('sitemap'),
        '',
    ]))->header('Content-Type', 'text/plain');
});

Route::get('/sitemap.xml', function () {
    $halaman = [
        'publik.beranda', 'publik.tentang', 'publik.mitra', 'publik.rencana',
        'publik.kontak', 'masuk', 'publik.privasi', 'publik.syarat',
    ];

    $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
        .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

    foreach ($halaman as $rute) {
        $xml .= '  <url><loc>'.e(route($rute)).'</loc></url>'."\n";
    }

    $xml .= '</urlset>'."\n";

    return response($xml)->header('Content-Type', 'application/xml');
})->name('sitemap');

/*
 * Dokumen penawaran — staff print the priced offer for a customer. Same
 * posture as the faktur: staff guard, credit-data gate in the controller.
 */
Route::middleware(['web', 'auth:web'])
    ->get('/dokumen/penawaran/{quotation}', PenawaranController::class)
    ->name('dokumen.penawaran');
