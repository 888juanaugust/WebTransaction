<?php

use App\Client\Modules\ClaimsModule;
use App\Client\Modules\CollectionsModule;
use App\Client\Modules\OrdersModule;
use App\Client\Modules\PortalModule;
use App\Client\Modules\PriceListModule;
use App\Client\Modules\SiteModule;
use App\Client\Modules\WarehouseModule;
use App\Client\Screens\CentralScreen;

/*
|--------------------------------------------------------------------------
| Central's own layer
|--------------------------------------------------------------------------
|
| Central runs on the August ERP base. Everything that is Central's own —
| its modules, screens, models, migrations, strings — lives under app/Client
| and is registered here, so the base stays readable as the base
| (see CLAUDE.md, "Architecture").
|
*/

return [

    // Central's modules, each a class implementing App\Modules\Module.
    // They register after the standard modules of config/modules.php.
    'modules' => [
        OrdersModule::class,
        PriceListModule::class,
        ClaimsModule::class,
        CollectionsModule::class,
        WarehouseModule::class,
        PortalModule::class,
        SiteModule::class,
    ],

    // Central's own screen keys: string-backed enums implementing
    // App\Domain\Access\ScreenKey, values starting "client__". Rights, menus and
    // the standard's pages pick them up with the base's MenuKey.
    'screens' => [
        CentralScreen::class,
    ],

    // Optional modules Central starts with, by module key, when erp:install
    // is not told --enable or --disable. Sales extras (check-ins, commissions,
    // targets) are part of the credit-sales operation; payroll, departments
    // and projects are not.
    'features' => [
        'sales-extras' => true,
        'payroll' => false,
        'departments' => false,
        'projects' => false,
    ],

    // The panel's colours; any key left out keeps the base's (DESIGN.md).
    'theme' => [
        'colors' => [
            // 'primary' => '#0f766e',
        ],
    ],

];
