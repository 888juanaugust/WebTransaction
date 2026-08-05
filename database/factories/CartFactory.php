<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\CustomerUser;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class CartFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'customer_user_id' => CustomerUser::factory(),
            'warehouse_id' => Warehouse::factory(),
        ];
    }
}
