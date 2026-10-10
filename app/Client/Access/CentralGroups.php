<?php

declare(strict_types=1);

namespace App\Client\Access;

use App\Models\Settings\AccessGroup;
use App\Models\User;

/**
 * Central's roles are the base's access groups, each claimed by a stable
 * role key (access_groups.role_key). The team rules, the claims and the
 * warehouse binding read membership here; the rights each group holds are
 * shaped by CentralGroupSeeder and the Access Groups screen. The owner may
 * rename a group (Keuangan, Pembelian, Gudang, Penjualan); the key stays.
 */
final class CentralGroups
{
    public const ADMINISTRATOR = 'administrator';

    public const SALES = 'sales';

    public const MARKETING = 'marketing';

    /** Stock, the catalogue, the price list and the purchasing chain. */
    public const PURCHASING = 'purchasing';

    public const WAREHOUSE = 'warehouse';

    public const FINANCE = 'finance';

    /** The portal's own staff identity, never a person's. */
    public const PORTAL = 'portal';

    /** The name each role is seeded under: the base's group of that name is the one claimed. */
    public const NAMES = [
        self::ADMINISTRATOR => 'Administrator',
        self::SALES => 'Sales',
        self::MARKETING => 'Marketing',
        self::PURCHASING => 'Purchasing',
        self::WAREHOUSE => 'Warehouse',
        self::FINANCE => 'Finance',
        self::PORTAL => 'Portal',
    ];

    /** The name Central gave the stock keepers before they took over purchasing; merged into Purchasing. */
    public const LEGACY_INVENTORY = 'Inventory';

    public static function isMember(?User $user, string $role): bool
    {
        return $user !== null && $user->accessGroups()->where('role_key', $role)->exists();
    }

    public static function find(string $role): ?AccessGroup
    {
        return AccessGroup::query()->where('role_key', $role)->first();
    }

    /** The group of a role: by its key, else the base's group of the seeded name, else a new one; the key is stamped. */
    public static function claim(string $role): AccessGroup
    {
        $group = self::find($role)
            ?? AccessGroup::query()->whereNull('role_key')->where('name', self::NAMES[$role])->first()
            ?? new AccessGroup(['name' => self::NAMES[$role], 'restriction_type' => 'preferences']);
        if ($group->role_key !== $role) {
            $group->role_key = $role;
            $group->save();
        }

        return $group;
    }

    /** The role a group plays, for labels: the group's own name. */
    public static function nameOf(string $role): string
    {
        return self::find($role)?->name ?? self::NAMES[$role];
    }
}
