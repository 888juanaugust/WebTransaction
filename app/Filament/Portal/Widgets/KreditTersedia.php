<?php

declare(strict_types=1);

namespace App\Filament\Portal\Widgets;

use App\Domain\Credit\CreditChecker;
use App\Domain\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Available credit, shown persistently.
 *
 * Second item on the buyer portal priority list, and the number a buyer checks
 * before deciding whether they can order at all — so it sits at the top of
 * every visit rather than behind a menu.
 */
class KreditTersedia extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $company = auth('customer')->user()?->company;

        if ($company === null) {
            return [];
        }

        $status = app(CreditChecker::class)->status($company);

        return [
            Stat::make('Sisa limit kredit', Money::format($status->available()))
                ->description('dari limit '.Money::format($status->limit))
                ->color($status->available() > 0 ? 'primary' : 'danger'),

            Stat::make('Terpakai', Money::format($status->outstanding + $status->committed))
                ->description('faktur terbuka dan order berjalan')
                ->color('gray'),

            Stat::make('Termin pembayaran', $company->payment_terms_days.' hari')
                ->description($company->nama)
                ->color('gray'),
        ];
    }
}
