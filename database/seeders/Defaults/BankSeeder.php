<?php

namespace Database\Seeders\Defaults;

use App\Models\Company\Bank;
use Illuminate\Database\Seeder;

class BankSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['Bank Central Asia (BCA)', 'CENAIDJA'],
            ['Bank Mandiri', 'BMRIIDJA'],
            ['Bank Negara Indonesia (BNI)', 'BNINIDJA'],
            ['Bank Rakyat Indonesia (BRI)', 'BRINIDJA'],
            ['CIMB Niaga', 'BNIAIDJA'],
            ['Bank Permata', 'BBBAIDJA'],
            ['Bank Danamon', 'BDINIDJA'],
            ['Bank Syariah Indonesia (BSI)', 'BSMDIDJA'],
        ] as [$name, $swift]) {
            Bank::query()->firstOrCreate(['name' => $name], ['swift_code' => $swift]);
        }
    }
}
