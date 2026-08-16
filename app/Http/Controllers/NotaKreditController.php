<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CreditNote;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Nota kredit — the document the customer receives when money goes back.
 *
 * Built like the faktur, and for the same reasons: print-styled HTML, no PDF
 * dependency, "Save as PDF" from the print dialogue produces the archival copy.
 *
 * Two entry points, one per guard, rather than one method that inspects
 * whoever happens to be logged in. Staff and buyers authenticate on different
 * guards against different tables, and a single method holding both rules is a
 * method where the wrong branch runs one day.
 *
 * A draft cannot be printed at all. Every figure on a credit note is decided at
 * posting; printing a draft would hand somebody a document whose totals are
 * all nil and whose numbers are not yet anybody's decision.
 */
class NotaKreditController extends Controller
{
    /**
     * Staff copy, on the `web` guard.
     *
     * Gated on canSeeCreditData() — the same permission as the faktur, and for
     * the same reason. It excludes Warehouse: they receive the physical
     * cartons back, and they still never see what they were worth.
     *
     * Deliberately *not* gated on canIssueCreditNote(). Finance may not create
     * one, because they confirm payments — but they very much need to read
     * one, since it changes what a customer owes and they are the people
     * chasing it.
     */
    public function staff(CreditNote $creditNote): View
    {
        $user = auth('web')->user();

        if ($user === null || ! $user->role()->canSeeCreditData()) {
            throw new AccessDeniedHttpException('Gudang tidak berhak melihat nota kredit.');
        }

        return $this->render($creditNote);
    }

    /**
     * Buyer copy, on the `customer` guard.
     *
     * A note belonging to another company is a 404, not a 403. A 403 confirms
     * the document exists, and the number is guessable.
     */
    public function pelanggan(CreditNote $creditNote): View
    {
        $user = auth('customer')->user();

        if ($user === null || $creditNote->company_id !== $user->company_id) {
            throw new NotFoundHttpException;
        }

        return $this->render($creditNote);
    }

    private function render(CreditNote $creditNote): View
    {
        if ($creditNote->isDraft()) {
            throw new NotFoundHttpException('Nota kredit ini belum diposting.');
        }

        $creditNote->load(['lines.product', 'company', 'invoice', 'warehouse', 'postedBy']);

        return view('dokumen.nota-kredit', ['nota' => $creditNote]);
    }
}
