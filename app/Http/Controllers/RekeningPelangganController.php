<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reporting\CustomerStatement;
use App\Domain\Reporting\Period;
use App\Models\Company;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Rekening koran pelanggan — the statement, print-styled, ready to send.
 *
 * Print-styled HTML like the other documents: no PDF dependency, and "Save as
 * PDF" from the print dialogue produces the copy that gets emailed.
 *
 * The dates come from the query string rather than the record, because unlike
 * a faktur this document has no fixed period of its own — it is whatever
 * window somebody asked for. Both are parsed defensively: a statement is
 * something a customer reads, and a 500 on a mistyped date is worse than a
 * sensible default.
 *
 * Gated on `canSeeCreditData()`, matching the report screen. There is no
 * portal route: a buyer's own account is already on their portal dashboard,
 * and this is the version staff send with a covering message.
 */
class RekeningPelangganController extends Controller
{
    public function __invoke(Request $request, Company $company): View
    {
        $user = auth('web')->user();

        if ($user === null || ! $user->role()->canSeeCreditData()) {
            throw new AccessDeniedHttpException('Anda tidak berhak mencetak rekening pelanggan.');
        }

        $period = Period::between(
            $this->date($request->query('dari'), Carbon::now()->startOfMonth()->subMonths(2)),
            $this->date($request->query('sampai'), Carbon::now()),
        );

        return view('dokumen.rekening-pelanggan', [
            'company' => $company,
            'table' => app(CustomerStatement::class)->build($company, $period),
            'period' => $period,
        ]);
    }

    private function date(mixed $value, Carbon $fallback): Carbon
    {
        if (! is_string($value) || $value === '') {
            return $fallback;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
