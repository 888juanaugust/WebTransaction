<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/** The dashboard: the first, pinned tab of the workspace, at /admin/dashboard. */
class Dashboard extends BaseDashboard
{
    protected static string $routePath = 'dashboard';

    protected static bool $shouldRegisterNavigation = false;
}
