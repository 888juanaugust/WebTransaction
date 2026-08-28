<?php

declare(strict_types=1);

namespace App\Filament\Portal\Widgets;

use App\Domain\Credit\DebtAging;
use App\Models\Invoice;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * The customer's own aging warning, above everything else on their landing
 * screen.
 *
 * Computed live on every visit rather than sent once: a banner cannot be
 * missed the way a notification can, cannot go stale the way a stored flag
 * can, and disappears by itself the moment the debt is paid. Renders nothing
 * at all while the account is healthy — a permanent "you are fine" box
 * teaches people to stop reading boxes.
 */
class PeringatanTunggakan extends Widget
{
    /** Above the reorder widget: nothing else matters if the account is frozen. */
    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.portal.widgets.peringatan-tunggakan';

    public static function canView(): bool
    {
        return auth('customer')->user()?->company_id !== null;
    }

    /** @return array{beku: bool, faktur: Collection<int, Invoice>} */
    protected function getViewData(): array
    {
        $company = auth('customer')->user()->company;
        $aging = app(DebtAging::class);

        $beku = $aging->fallDueInvoices($company);

        if ($beku->isNotEmpty()) {
            return ['beku' => true, 'faktur' => $beku];
        }

        /*
         * Not yet frozen but past the three-month notice line: the warning
         * the sweep sent the team, shown to the customer too, with the date
         * their account locks — a deadline with a date on it gets paid.
         */
        $menua = Invoice::query()
            ->where('company_id', $company->id)
            ->where('status', Invoice::STATUS_OPEN)
            ->whereDate('issued_on', '<=', $aging->noticeCutoff()->toDateString())
            ->orderBy('issued_on')
            ->get();

        return ['beku' => false, 'faktur' => $menua];
    }
}
