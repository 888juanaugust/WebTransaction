<?php

namespace Database\Seeders\System;

use App\Models\Company\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        Currency::query()->updateOrCreate(['code' => 'IDR'], [
            'symbol' => 'Rp',
            'name' => 'Indonesian Rupiah',
            'country' => 'Indonesia',
            'is_base' => true,
            'is_active' => true,
        ]);
    }
}
