<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Uom\Unit;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderLine>
 */
class OrderLineFactory extends Factory
{
    protected $model = OrderLine::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'sku' => Product::factory(),
            'urutan' => 1,
            'ordered_unit' => Unit::Pcs,
            'ordered_qty' => 10,
            'qty_per_ctn_snapshot' => 10,
            'satuan_dasar_snapshot' => 'PCS',
            'qty_base' => 10,
        ];
    }

    /** Order by the carton, resolving to base units the way the domain does. */
    public function cartons(int $cartons, int $qtyPerCtn): static
    {
        return $this->state(fn () => [
            'ordered_unit' => Unit::Ctn,
            'ordered_qty' => $cartons,
            'qty_per_ctn_snapshot' => $qtyPerCtn,
            'qty_base' => $cartons * $qtyPerCtn,
        ]);
    }

    public function qty(int $qtyBase): static
    {
        return $this->state(fn () => [
            'ordered_qty' => $qtyBase,
            'qty_base' => $qtyBase,
        ]);
    }
}
