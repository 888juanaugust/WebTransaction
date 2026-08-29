<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Access\Role;
use App\Domain\Regions\RegionContext;
use App\Models\PriceTier;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Baseline reference data: one staff account per role, the warehouse, and the
 * price tiers.
 *
 * No products and no prices — those come from a real price list import,
 * because a seeded price is a price nobody approved.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * The console is unbound, and seeded rows need books to file
         * themselves under. Pin to the default region the migration created
         * — the same thing TestCase does for the suite.
         */
        app(RegionContext::class)->pinTo(
            Region::query()->orderBy('id')->firstOrFail(),
        );

        /*
         * Ordinary staff are pinned to the default region the migration
         * created; the Owner's blank is the grant of every region. Left
         * unpinned, a seeded salesperson could not be seated on any team —
         * TeamAssigner rightly refuses an assignee the region scope hides
         * from the customer.
         */
        $wilayahUtama = Region::query()->orderBy('id')->value('id');

        foreach (Role::cases() as $role) {
            User::query()->firstOrCreate(
                ['email' => "{$role->value}@example.test"],
                [
                    'name' => $role->label(),
                    'password' => Hash::make('password'),
                    'role' => $role,
                    'is_active' => true,
                    'email_verified_at' => now(),
                    // Marketing is global like the Owner — no region of
                    // their own, they answer for customers everywhere.
                    'region_id' => in_array($role, [Role::Owner, Role::Marketing], true) ? null : $wilayahUtama,
                ],
            );
        }

        $this->call(ChartOfAccountsSeeder::class);

        Warehouse::query()->firstOrCreate(
            ['kode' => 'GD-PUSAT'],
            ['nama' => 'Gudang Pusat', 'aktif' => true],
        );

        foreach ([
            ['BENGKEL', 'Bengkel', 0],
            ['TOKO', 'Toko sparepart', 250],
            ['DIST', 'Distributor', 750],
        ] as [$kode, $nama, $bps]) {
            PriceTier::query()->firstOrCreate(
                ['kode' => $kode],
                ['nama' => $nama, 'discount_bps' => $bps, 'aktif' => true],
            );
        }
    }
}
