<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Orders\OrderStatus;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Surat jalan — the delivery note that travels with the goods.
 *
 * Rendered as a print-styled HTML page rather than a generated PDF. That is a
 * deliberate choice, not a shortcut: it adds no dependency, prints correctly
 * from any browser including the shared machine in a warehouse, and "Save as
 * PDF" in the print dialogue produces the archival copy when one is wanted. A
 * PDF library can be added later without changing this page — the layout is
 * already the document.
 *
 * **No prices appear on it.** That is the point of the document as much as the
 * quantities: a surat jalan is handed to a driver and then to whoever receives
 * the goods, and neither of them is party to what this customer pays. It is
 * also why warehouse staff can reach this page while being unable to see a
 * price anywhere else in the panel.
 */
class SuratJalanController extends Controller
{
    public function __invoke(Order $order): View
    {
        $user = auth()->user();

        if ($user === null || ! $user->role()->canPickAndShip()) {
            // Sales and finance have no business printing delivery notes;
            // picking and shipping is the warehouse's job and the owner's.
            throw new AccessDeniedHttpException('Hanya gudang yang bisa mencetak surat jalan.');
        }

        /*
         * Only for an order that has actually committed stock.
         *
         * Before `confirmed` nothing is reserved, so a delivery note would
         * describe goods the warehouse has not been told to set aside — which
         * is how stock leaves the building without a sale behind it.
         */
        $printable = $order->status->holdsReservation()
            || in_array($order->status, [OrderStatus::Shipped, OrderStatus::Completed], true);

        if (! $printable) {
            throw new AccessDeniedHttpException(
                "Order {$order->nomor} belum dikonfirmasi, jadi belum ada barang yang disiapkan."
            );
        }

        return view('dokumen.surat-jalan', [
            'order' => $order->load(['company', 'warehouse', 'lines.product']),
        ]);
    }
}
