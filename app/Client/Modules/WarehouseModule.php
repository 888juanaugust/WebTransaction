<?php

declare(strict_types=1);

namespace App\Client\Modules;

use App\Client\Access\CentralGroups;
use App\Client\Domain\Warehouse\WarehouseBinder;
use App\Client\Domain\Warehouse\WarehouseScope;
use App\Client\Screens\CentralScreen;
use App\Models\User;
use App\Modules\BaseModule;
use App\Modules\ModuleContext;

/**
 * Gudang: one active account per warehouse, bound by an administrator, and
 * the warehouse's fulfilment queue — what it holds for approved orders, to
 * pick and deliver. Always on.
 */
final class WarehouseModule extends BaseModule
{
    public static function key(): string
    {
        return 'central-warehouse';
    }

    public static function menuKeys(): array
    {
        return [CentralScreen::Fulfilment, CentralScreen::WarehouseAccounts];
    }

    public static function boot(ModuleContext $context): void
    {
        // Reactivating a bound account while another active account holds its warehouse is refused: one warehouse, one account.
        User::updating(function (User $user) use ($context): void {
            if (! ($user->isDirty('is_active') && $user->is_active && ! (bool) $user->getOriginal('is_active'))) {
                return;
            }
            if (! CentralGroups::isMember($user, CentralGroups::WAREHOUSE)) {
                return;
            }
            $warehouse = WarehouseScope::of($user->fresh() ?? $user);
            if ($warehouse !== null) {
                $context->app->make(WarehouseBinder::class)->assertFree($warehouse, $user);
            }
        });
    }
}
