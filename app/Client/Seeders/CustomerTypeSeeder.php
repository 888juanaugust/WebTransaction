<?php

declare(strict_types=1);

namespace App\Client\Seeders;

use App\Client\Models\CustomerType;
use Illuminate\Database\Seeder;

/** The kinds of customer Central sells to; their terms are the owner's to set. */
class CustomerTypeSeeder extends Seeder
{
    public const TYPES = ['BKL' => 'Bengkel', 'TOKO' => 'Toko Sparepart', 'DIST' => 'Distributor'];

    public function run(): void
    {
        foreach (self::TYPES as $code => $name) {
            CustomerType::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
        }
    }
}
