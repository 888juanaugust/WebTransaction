<?php

declare(strict_types=1);

namespace App\Modules\FixedAssets;

use App\Console\Commands\DepreciateCommand;
use App\Domain\Access\MenuKey;
use App\Domain\FixedAssets\AssetFromBill;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\FixedAssets\AssetCategory;
use App\Models\FixedAssets\AssetChange;
use App\Models\FixedAssets\AssetChangeExpenditure;
use App\Models\FixedAssets\AssetDepreciation;
use App\Models\FixedAssets\AssetDisposal;
use App\Models\FixedAssets\AssetLocation;
use App\Models\FixedAssets\AssetTransfer;
use App\Models\FixedAssets\AssetTransferLine;
use App\Models\FixedAssets\FiscalAssetCategory;
use App\Models\FixedAssets\FixedAsset;
use App\Models\FixedAssets\FixedAssetExpenditure;
use App\Modules\BaseModule;
use App\Modules\ModuleContext;
use Database\Seeders\Defaults\FixedAssetSeeder;
use Illuminate\Console\Scheduling\Schedule;

/** Fixed assets and their monthly depreciation. Switched by the Fixed assets feature. */
final class FixedAssetsModule extends BaseModule
{
    public static function key(): string
    {
        return 'fixed-assets';
    }

    public static function feature(): ?PreferensiKey
    {
        return PreferensiKey::FixedAssets;
    }

    public static function menuKeys(): array
    {
        return [MenuKey::FixedAssets, MenuKey::AssetCategories, MenuKey::FiscalAssetCategories, MenuKey::AssetChanges, MenuKey::AssetDisposals, MenuKey::AssetTransfers, MenuKey::AssetsByLocation];
    }

    public static function morphMap(): array
    {
        return [
            'asset_category' => AssetCategory::class,
            'fiscal_asset_category' => FiscalAssetCategory::class,
            'asset_location' => AssetLocation::class,
            'fixed_asset' => FixedAsset::class,
            'fixed_asset_expenditure' => FixedAssetExpenditure::class,
            'asset_depreciation' => AssetDepreciation::class,
            'asset_change' => AssetChange::class,
            'asset_change_expenditure' => AssetChangeExpenditure::class,
            'asset_disposal' => AssetDisposal::class,
            'asset_transfer' => AssetTransfer::class,
            'asset_transfer_line' => AssetTransferLine::class,
        ];
    }

    public static function boot(ModuleContext $context): void
    {
        // A purchase invoice an asset was recorded from stays as it was while the asset exists.
        $context->guard->addBlocker(new AssetFromBill);
    }

    public static function defaultSeeders(): array
    {
        return [FixedAssetSeeder::class];
    }

    public static function commands(): array
    {
        return [DepreciateCommand::class];
    }

    public static function schedule(Schedule $schedule): void
    {
        $schedule->command('erp:depreciate')->lastDayOfMonth('23:30')->withoutOverlapping()->onOneServer();
    }
}
