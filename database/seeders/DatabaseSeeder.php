<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Access\Role;
use App\Models\PriceTier;
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
        foreach (Role::cases() as $role) {
            User::query()->firstOrCreate(
                ['email' => "{$role->value}@example.test"],
                [
                    'name' => $role->label(),
                    'password' => Hash::make('password'),
                    'role' => $role,
                    'is_active' => true,
                    'email_verified_at' => now(),
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
