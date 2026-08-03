<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyPriceOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyPriceOverride>
 */
class CompanyPriceOverrideFactory extends Factory
{
    protected $model = CompanyPriceOverride::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'kode' => null,
            'min_qty_base' => 1,
            'harga' => null,
            'discount_bps' => null,
        ];
    }
}
