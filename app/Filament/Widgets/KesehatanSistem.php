<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Ops\OpsCheck;
use App\Domain\Ops\OpsHealth;
use App\Domain\Ops\OpsStatus;
use Filament\Widgets\Widget;

/**
 * The box's own vitals, shown only when one of them is wrong.
 *
 * Same rule as the backup banner above it: **silent when healthy**. A green
 * tick that is always present stops being read within a week, and then it
 * is decoration on the one morning it matters. `php artisan ops:check` is
 * the deliberate version of this widget.
 */
class KesehatanSistem extends Widget
{
    protected static ?int $sort = -9;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.kesehatan-sistem';

    public static function canView(): bool
    {
        // The owner: infrastructure findings shown to somebody who cannot
        // act on them are noise that trains people to ignore warnings.
        if (! (auth()->user()?->role()->canViewAuditLog() ?? false)) {
            return false;
        }

        return app(OpsHealth::class)->worst() !== OpsStatus::Sehat;
    }

    /** @return list<OpsCheck> */
    public function getFailing(): array
    {
        return app(OpsHealth::class)->failing();
    }
}
