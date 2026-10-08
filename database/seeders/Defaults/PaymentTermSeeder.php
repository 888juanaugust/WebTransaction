<?php

namespace Database\Seeders\Defaults;

use App\Models\Company\PaymentTerm;
use Illuminate\Database\Seeder;

class PaymentTermSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['Net 30', 0, 0, 30, true, 'Due 30 days after the invoice date.'],
            ['Net 14', 0, 0, 14, false, null],
            ['Cash on delivery', 0, 0, 0, false, null],
            ['2/10 net 30', 2, 10, 30, false, '2 % off when paid within 10 days, otherwise due in 30.'],
        ] as [$name, $disc, $discDays, $due, $default, $memo]) {
            PaymentTerm::query()->firstOrCreate(['name' => $name], [
                'discount_percent' => $disc,
                'discount_days' => $discDays,
                'due_days' => $due,
                'is_default' => $default,
                'memo' => $memo,
                'is_active' => true,
            ]);
        }
    }
}
