<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Orders\OrderStatus;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
 * the goods, and neither of them is party to what this customer pays.
 *
 * Two entry points, one per guard, the same shape as the faktur — and for the
 * same reason: staff and buyers authenticate on different guards against
 * different tables, are refused for different reasons, and are told different
 * things when refused. One method holding both rule sets is a method where the
 * wrong branch runs one day.
 *
 * What the two are *for* differs, and the gate differs with it. For the
 * warehouse this is the picking paperwork, printable from `confirmed` because
 * that is when the goods are set aside. For the buyer it is proof of what was
 * delivered, so it does not exist until the goods have actually left.
 */
class SuratJalanController extends Controller
{
    /** Warehouse copy, on the `web` guard: the paperwork that goes with the goods. */
    public function staff(Order $order): View
    {
        // Named guard, matching the route. `auth()` alone means "the default
        // guard", and this application has two — a buyer resolved here would
        // reach role() and fatal, or worse, not.
        $user = auth('web')->user();

        if ($user === null || ! $user->role()->canPickAndShip()) {
            // Sales and finance have no business printing delivery notes;
            // picking and shipping is the warehouse's job and the owner's.
            throw new AccessDeniedHttpException('Hanya gudang yang bisa mencetak surat jalan.');
        }

        // A warehouse-bound (Gudang) account prints only its own gudang's
        // paperwork — the URL is guessable, the boundary must not be.
        if ($user->role()->isWarehouseBound()
            && (int) $order->warehouse_id !== (int) $user->warehouse_id) {
            throw new AccessDeniedHttpException('Order ini milik gudang lain.');
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

        return $this->render($order);
    }

    /**
     * Customer copy, on the `customer` guard.
     *
     * A buyer prints their own company's delivery notes and no others. The
     * refusal is a 404 rather than a 403, matching the faktur: a 403 confirms
     * that SO-SBY-202609-0007 exists and belongs to somebody, which is a slow
     * way of telling a customer how much business the shop down the road is
     * doing. Out of scope simply has no matching row, which is what
     * ScopedToBuyer already does inside the portal.
     *
     * Region scope does not enter into it. After a multi-warehouse split a
     * buyer's own pieces are booked in other regions' books by design, and
     * their company is the boundary — as it is everywhere else the buyer
     * reads.
     */
    public function pelanggan(Order $order): View
    {
        $buyer = auth('customer')->user();

        if ($buyer === null || $buyer->company_id === null || $order->company_id !== $buyer->company_id) {
            throw new NotFoundHttpException;
        }

        /*
         * Only once the goods have gone.
         *
         * The warehouse prints this from `confirmed` because for them it is
         * the picking list. For the customer it is the record of a delivery,
         * and a delivery note available before anything was delivered is a
         * document that says we shipped when we have not — which is exactly
         * the piece of paper a dispute later turns on.
         */
        if (! in_array($order->status, [OrderStatus::Shipped, OrderStatus::Completed], true)) {
            throw new AccessDeniedHttpException(
                "Pesanan {$order->nomor} belum dikirim, jadi surat jalannya belum ada."
            );
        }

        return $this->render($order);
    }

    private function render(Order $order): View
    {
        return view('dokumen.surat-jalan', [
            'order' => $order->load(['company', 'warehouse', 'lines.product']),
        ]);
    }
}
