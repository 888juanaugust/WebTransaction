<?php

declare(strict_types=1);

namespace App\Client\Access;

use App\Models\Settings\AccessGroup;
use App\Models\User;

/**
 * Central's roles are the base's access groups by name. The team rules
 * (who may hold a customer's sales or marketing seat, who approves) read
 * membership here; the rights each group holds are shaped by the seeders
 * and the Access Groups screen.
 */
final class CentralGroups
{
    public const SALES = 'Sales';

    public const MARKETING = 'Marketing';

    public const INVENTORY = 'Inventory';

    public const WAREHOUSE = 'Warehouse';

    public const FINANCE = 'Finance';

    public static function isMember(?User $user, string $group): bool
    {
        return $user !== null && $user->accessGroups()->where('name', $group)->exists();
    }

    public static function find(string $group): ?AccessGroup
    {
        return AccessGroup::query()->where('name', $group)->first();
    }
}
