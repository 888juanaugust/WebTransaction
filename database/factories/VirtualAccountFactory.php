<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\VirtualAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VirtualAccount>
 */
class VirtualAccountFactory extends Factory
{
    protected $model = VirtualAccount::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'bank_code' => 'BCA',
            'account_number' => fake()->unique()->numerify('##############'),
            'status' => 'active',
        ];
    }
}
