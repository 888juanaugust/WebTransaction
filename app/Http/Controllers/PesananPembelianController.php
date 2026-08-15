<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Purchasing\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The purchase order, as the supplier receives it.
 *
 * The third print document, and the first one that travels *outward*. The surat
 * jalan goes with our goods and the faktur goes to our customer; this goes to
 * somebody outside the company and asks them to do something. Until it existed,
 * "Kirim ke pemasok" moved a status in our database and put nothing in their
 * inbox — the supplier had no document, and we had no evidence of what we had
 * asked for at the price we asked for it.
 *
 * Print-styled HTML for the same reasons as the other two: no PDF dependency,
 * and "Save as PDF" in the print dialogue produces the copy that gets emailed.
 *
 * Gated on canRecordPurchases(), which is Finance and Owner. The document
 * carries what we are willing to pay, and purchase cost plus selling price is
 * margin — see Role::canSeeCost() for why Sales is not on that list.
 */
class PesananPembelianController extends Controller
{
    public function __invoke(PurchaseOrder $purchaseOrder): View
    {
        // Named guard, matching the route. `auth()` alone means "the default
        // guard", and this application has two.
        $user = auth('web')->user();

        if ($user === null || ! $user->role()->canRecordPurchases()) {
            throw new AccessDeniedHttpException('Anda tidak berhak mencetak pesanan pembelian.');
        }

        /*
         * A draft is not an order.
         *
         * Nothing has been agreed, the lines are still being edited, and the
         * total has not been fixed — sending one to a supplier would create an
         * obligation the system does not believe exists, against numbers that
         * may change ten minutes later. Sending it is what makes it a document.
         */
        if ($purchaseOrder->status === PurchaseOrderStatus::Draft) {
            throw new AccessDeniedHttpException(
                "PO {$purchaseOrder->nomor} masih draf. Kirim dulu ke pemasok sebelum dicetak."
            );
        }

        return view('dokumen.pesanan-pembelian', [
            'po' => $purchaseOrder->load(['supplier', 'warehouse', 'lines.product', 'sender']),
        ]);
    }
}
