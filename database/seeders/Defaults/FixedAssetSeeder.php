<?php

namespace Database\Seeders\Defaults;

use App\Domain\FixedAssets\DepreciationMethod;
use App\Models\Company\Branch;
use App\Models\FixedAssets\AssetCategory;
use App\Models\FixedAssets\AssetLocation;
use App\Models\FixedAssets\FiscalAssetCategory;
use App\Models\GeneralLedger\Account;
use Illuminate\Database\Seeder;

/** What the Fixed Assets module starts with: one category on the seeded accounts, the tax office's asset groups, one location. */
class FixedAssetSeeder extends Seeder
{
    public function run(): void
    {
        $id = fn (string $no): ?int => Account::query()->where('no', $no)->value('id');

        foreach ([
            ['Equipment', DepreciationMethod::StraightLine, 48],
            ['Vehicles', DepreciationMethod::StraightLine, 96],
            ['Buildings', DepreciationMethod::StraightLine, 240],
        ] as [$name, $method, $months]) {
            AssetCategory::query()->firstOrCreate(['name' => $name], [
                'asset_account_id' => $id('1500'),
                'accumulated_depreciation_account_id' => $id('1510'),
                'depreciation_expense_account_id' => $id('6400'),
                'depreciation_method' => $method,
                'useful_life_months' => $months,
                'is_active' => true,
            ]);
        }

        // Golongan harta berwujud per PMK 96/2009 and the buildings classes.
        foreach ([
            ['Group I (4 years)', 4, 25, DepreciationMethod::StraightLine],
            ['Group II (8 years)', 8, 12.5, DepreciationMethod::StraightLine],
            ['Group III (16 years)', 16, 6.25, DepreciationMethod::StraightLine],
            ['Group IV (20 years)', 20, 5, DepreciationMethod::StraightLine],
            ['Permanent building (20 years)', 20, 5, DepreciationMethod::StraightLine],
            ['Non-permanent building (10 years)', 10, 10, DepreciationMethod::StraightLine],
        ] as [$name, $years, $rate, $method]) {
            FiscalAssetCategory::query()->firstOrCreate(['name' => $name], [
                'depreciation_method' => $method,
                'useful_life_years' => $years,
                'rate_percent' => $rate,
            ]);
        }

        $branch = Branch::default();
        AssetLocation::query()->firstOrCreate(['name' => 'Head office'], [
            'address' => $branch?->address,
            'branch_id' => $branch?->id,
            'is_active' => true,
        ]);
    }
}
