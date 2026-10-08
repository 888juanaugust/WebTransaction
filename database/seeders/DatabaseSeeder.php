<?php

namespace Database\Seeders;

use App\Modules\ModuleRegistry;
use Illuminate\Database\Seeder;

/**
 * What every installation gets: the System seeders the code relies on, the
 * Defaults a company usually wants and can edit, and the defaults of each
 * module that is switched on. The demo company is seeded only on request
 * (erp:install --demo, or --class=Database\Seeders\Demo\DemoCompanySeeder).
 */
class DatabaseSeeder extends Seeder
{
    /** @var list<class-string<Seeder>> the tables the code itself relies on */
    public const SYSTEM = [
        System\AdminUserSeeder::class,
        System\BranchSeeder::class,
        System\CurrencySeeder::class,
        System\ChartOfAccountsSeeder::class,
        System\DocumentSeriesSeeder::class,
        System\CoreMastersSeeder::class,
    ];

    /** @var list<class-string<Seeder>> sensible starting data a company edits on its screens */
    public const DEFAULTS = [
        Defaults\BankSeeder::class,
        Defaults\TaxCodeSeeder::class,
        Defaults\PaymentTermSeeder::class,
        Defaults\FobSeeder::class,
        Defaults\UnitSeeder::class,
        Defaults\PrintLayoutSeeder::class,
        Defaults\AccessGroupSeeder::class,
        Defaults\ApprovalRuleSeeder::class,
    ];

    public function run(): void
    {
        $this->call(self::SYSTEM);
        $this->call(self::DEFAULTS);

        foreach (app(ModuleRegistry::class)->enabled() as $module) {
            $this->call($module::defaultSeeders());
        }
    }
}
