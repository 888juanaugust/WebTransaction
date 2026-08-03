<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Orders\OrderStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'nomor' => 'SO-'.fake()->unique()->numerify('########'),
            'company_id' => Company::factory(),
            'warehouse_id' => Warehouse::factory(),
            'created_by' => User::factory(),
            'status' => OrderStatus::Draft,
        ];
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function totalling(int $rupiah): static
    {
        return $this->state(fn () => [
            'subtotal_rupiah' => $rupiah,
            'total_rupiah' => $rupiah,
        ]);
    }
}
