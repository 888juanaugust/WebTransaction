<?php

declare(strict_types=1);

namespace App\Modules\Reports;

use App\Domain\Access\MenuKey;
use App\Modules\BaseModule;

/** Reports: the catalogue and every report computed from the ledgers. Core. */
final class ReportsModule extends BaseModule
{
    public static function key(): string
    {
        return 'reports';
    }

    public static function menuKeys(): array
    {
        return [MenuKey::ReportCatalogue, MenuKey::AIAnalysis];
    }
}
