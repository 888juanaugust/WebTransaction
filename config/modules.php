<?php

use App\Modules\Budgeting\BudgetsModule;
use App\Modules\CashBank\CashBankModule;
use App\Modules\Company\CompanyModule;
use App\Modules\Company\DepartmentsModule;
use App\Modules\Company\ProjectsModule;
use App\Modules\FixedAssets\FixedAssetsModule;
use App\Modules\GeneralLedger\GeneralLedgerModule;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Purchasing\PurchasingModule;
use App\Modules\Reports\ReportsModule;
use App\Modules\Sales\SalesExtrasModule;
use App\Modules\Sales\SalesModule;
use App\Modules\Settings\ApprovalModule;
use App\Modules\Settings\SettingsModule;
use App\Modules\Tax\TaxModule;

/*
 * The standard modules of the ERP, in sidebar order. Each class says which
 * screens it owns and which Features preference switches it on; the core
 * modules have no switch. A client adds its own modules in config/client.php.
 */
return [
    'modules' => [
        SettingsModule::class,
        ApprovalModule::class,
        CompanyModule::class,
        DepartmentsModule::class,
        ProjectsModule::class,
        GeneralLedgerModule::class,
        BudgetsModule::class,
        PayrollModule::class,
        CashBankModule::class,
        SalesModule::class,
        SalesExtrasModule::class,
        PurchasingModule::class,
        InventoryModule::class,
        FixedAssetsModule::class,
        TaxModule::class,
        ReportsModule::class,
    ],
];
