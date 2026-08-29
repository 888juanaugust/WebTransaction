<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Quotation;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Dokumen penawaran — the priced offer handed (or emailed) to a customer.
 *
 * Staff only, on the same gate as the faktur: it shows a customer's prices,
 * which is credit-side information. A draft prints too — a salesperson
 * proofreads on paper before sending — and the sheet marks a draft as one,
 * so a proof cannot pass for an offer.
 */
class PenawaranController extends Controller
{
    public function __invoke(Quotation $quotation): View
    {
        // Named guard, matching the route. `auth()` alone means "the default
        // guard", and this application has two.
        $user = auth('web')->user();

        if ($user === null || ! $user->role()->canSeeCreditData()) {
            throw new AccessDeniedHttpException('Peran Anda tidak berhak melihat penawaran.');
        }

        return view('dokumen.penawaran', [
            'quotation' => $quotation->load(['company', 'lines', 'creator']),
        ]);
    }
}
