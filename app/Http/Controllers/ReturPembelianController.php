<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PurchaseReturn;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Nota retur — the document that goes back with the goods.
 *
 * Print-styled HTML like the other four, for the same reasons: no PDF
 * dependency, and "Save as PDF" from the print dialogue produces the copy that
 * gets emailed to the supplier.
 *
 * One guard only, unlike the faktur and nota kredit. Those exist in two
 * versions because a customer can read their own; nothing about a purchase
 * return is any of a buyer's business, so there is no portal route and nothing
 * to keep the two branches straight.
 *
 * Gated on canReturnToSupplier() rather than something broader. The document
 * carries what we paid on every line, and purchase cost beside selling price
 * is margin — the same reason the pesanan pembelian is gated where it is.
 */
class ReturPembelianController extends Controller
{
    public function __invoke(PurchaseReturn $purchaseReturn): View
    {
        // Named guard, matching the route. `auth()` alone means "the default
        // guard", and this application has two.
        $user = auth('web')->user();

        if ($user === null || ! $user->role()->canReturnToSupplier()) {
            throw new AccessDeniedHttpException('Anda tidak berhak mencetak nota retur.');
        }

        /*
         * A draft cannot be printed.
         *
         * Every figure on it is decided at posting, and the goods have not
         * left the warehouse. Handing a supplier a nota retur whose totals are
         * all nil, for cartons still on our shelf, creates an argument rather
         * than a record.
         */
        if (! $purchaseReturn->isPosted()) {
            throw new NotFoundHttpException("Retur {$purchaseReturn->nomor} belum diposting.");
        }

        return view('dokumen.retur-pembelian', [
            'retur' => $purchaseReturn->load([
                'supplier', 'warehouse', 'goodsReceipt', 'lines.product', 'postedBy',
            ]),
        ]);
    }
}
