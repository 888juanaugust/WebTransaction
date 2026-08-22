<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Launch\LaunchCheck;
use App\Domain\Launch\LaunchReadiness;
use Filament\Widgets\Widget;

/**
 * What is still in the way of going live, on the dashboard.
 *
 * **It disappears the day the list is clear**, and never comes back unless
 * something breaks. That is the whole reason it can sit above the work queues:
 * a permanent banner congratulating a business that launched two years ago is
 * decoration, and decoration on a dashboard is what teaches people to stop
 * reading the top of it.
 *
 * The full list lives on its own screen. This shows the count and the first few
 * items, because a dashboard panel with fifteen rows on it is a page, and
 * somebody has to scroll past it every morning to reach the orders.
 *
 * It sits above `BackupStatus`, which will also be complaining before launch —
 * the backup is one of the items on this list. The duplication is deliberate
 * and short-lived: this one goes away at launch, and the backup widget goes on
 * doing its job for the years afterwards.
 */
class LaunchReadinessSummary extends Widget
{
    protected static ?int $sort = -20;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.launch-readiness-summary';

    /** How many rows before the panel becomes a page. */
    private const SHOWN = 4;

    public static function canView(): bool
    {
        /*
         * Owner only, matching the screen. A warning shown to somebody who
         * cannot act on it is noise that trains them to ignore warnings — and
         * nobody but the owner can decide any of this.
         */
        if (! (auth()->user()?->role()->canViewAuditLog() ?? false)) {
            return false;
        }

        return ! app(LaunchReadiness::class)->isReady();
    }

    public function outstanding(): int
    {
        return app(LaunchReadiness::class)->outstanding();
    }

    /** @return list<LaunchCheck> */
    public function topOutstanding(): array
    {
        return array_slice(app(LaunchReadiness::class)->outstandingChecks(), 0, self::SHOWN);
    }

    public function moreCount(): int
    {
        return max(0, $this->outstanding() - self::SHOWN);
    }
}
