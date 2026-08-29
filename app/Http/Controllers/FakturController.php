<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Faktur — the invoice the customer is actually given.
 *
 * The mirror image of the surat jalan. That document deliberately carries no
 * money; this one is nothing but money, down to DPP and PPN on every line,
 * because that is what the buyer's bookkeeper posts from and what our own
 * accountant reconciles against.
 *
 * Print-styled HTML for the same reasons as the surat jalan: no PDF dependency,
 * prints correctly from any browser, and "Save as PDF" in the print dialogue
 * produces the archival copy. The layout already is the document.
 *
 * Two entry points, one for each guard, rather than one method that sniffs at
 * whoever happens to be logged in. Staff and buyers authenticate on different
 * guards against different tables and are told different things when they are
 * refused, and a single method holding both rules is a method where the wrong
 * branch runs one day. The rendering they share is private and identical:
 * a customer and the salesperson looking at the same invoice must see the same
 * figures.
 *
 * **Not a Faktur Pajak.** This is the commercial invoice. The tax document is
 * issued by Coretax and comes back with an NSFP, which is stored on the invoice
 * record and printed here once it exists — see the view, which says so on the
 * page rather than letting the customer assume.
 */
class FakturController extends Controller
{
    /**
     * Staff copy, on the `web` guard.
     *
     * Gated on canSeeCreditData(), which is the same permission that governs
     * every other view of what a customer owes. It excludes Warehouse, and
     * that exclusion is the point: warehouse staff pick and ship, print the
     * surat jalan, and never see a price. This is the one document where the
     * two roles' access is exactly inverted.
     */
    public function staff(Invoice $invoice): View
    {
        // Named guard, matching the route. `auth()` alone means "the default
        // guard", and this application has two.
        $user = auth('web')->user();

        if ($user === null || ! $user->role()->canSeeCreditData()) {
            throw new AccessDeniedHttpException('Gudang tidak berhak melihat faktur.');
        }

        return $this->render($invoice);
    }

    /**
     * Customer copy, on the `customer` guard.
     *
     * A buyer may print their own company's invoices and no others. The refusal
     * is a 404 rather than a 403 on purpose: a 403 would confirm that invoice
     * INV-202608-0007 exists and belongs to somebody, which is a slow way of
     * telling a customer how much business a competitor is doing. This matches
     * what ScopedToBuyer already does inside the portal, where an out-of-scope
     * id simply has no matching row.
     */
    public function pelanggan(Invoice $invoice): View
    {
        $buyer = auth('customer')->user();

        if ($buyer === null || $buyer->company_id === null || $invoice->company_id !== $buyer->company_id) {
            throw new NotFoundHttpException;
        }

        return $this->render($invoice);
    }

    private function render(Invoice $invoice): View
    {
        $invoice->load([
            'company',
            'order.warehouse',
            'order.lines.product',
        ]);

        return view('dokumen.faktur', [
            'invoice' => $invoice,
            'lines' => $invoice->order?->lines ?? collect(),

            // Summed from the append-only payment ledger, not from a flag.
            'outstanding' => $invoice->amountOutstanding(),
        ]);
    }
}
