<?php

declare(strict_types=1);

namespace App\Client\Domain\Warehouse;

use App\Client\Access\CentralGroups;
use App\Models\Inventory\Warehouse;
use App\Models\User;

/** The one warehouse a Warehouse account is bound to; null for everybody else, who pick a warehouse they may use. */
final class WarehouseScope
{
    public static function of(?User $user): ?Warehouse
    {
        if ($user === null || $user->isAdministrator() || ! CentralGroups::isMember($user, CentralGroups::WAREHOUSE)) {
            return null;
        }

        return Warehouse::query()->whereHas('users', fn ($q) => $q->whereKey($user->id))->orderBy('id')->first();
    }

    public static function isBound(?User $user): bool
    {
        return self::of($user) !== null;
    }
}
