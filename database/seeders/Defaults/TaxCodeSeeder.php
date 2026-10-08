<?php

namespace Database\Seeders\Defaults;

use App\Domain\Tax\TaxType;
use App\Models\Company\TaxCode;
use App\Models\GeneralLedger\Account;
use Illuminate\Database\Seeder;

/** The 12 % VAT whose base is 11/12 of the price (PMK 131/2024), and an exempt code. */
class TaxCodeSeeder extends Seeder
{
    public function run(): void
    {
        $vatOut = Account::query()->where('no', '2200')->value('id');
        $vatIn = Account::query()->where('no', '1400')->value('id');

        TaxCode::query()->updateOrCreate(['description' => 'VAT 12% (burden 11%, base 11/12)'], [
            'tax_type' => TaxType::Vat,
            'rate_percent' => 12,
            'dpp_numerator' => 11,
            'dpp_denominator' => 12,
            'sales_tax_account_id' => $vatOut,
            'purchase_tax_account_id' => $vatIn,
            'is_default' => true,
            'is_active' => true,
        ]);

        TaxCode::query()->updateOrCreate(['description' => 'VAT exempt (0%)'], [
            'tax_type' => TaxType::Vat,
            'rate_percent' => 0,
            'dpp_numerator' => 1,
            'dpp_denominator' => 1,
            'sales_tax_account_id' => $vatOut,
            'purchase_tax_account_id' => $vatIn,
            'is_default' => false,
            'is_active' => true,
        ]);
    }
}
