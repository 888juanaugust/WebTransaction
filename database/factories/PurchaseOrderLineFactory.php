<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Uom\Unit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseOrderLine> */
class PurchaseOrderLineFactory extends Factory
{
    protected $model = PurchaseOrderLine::class;

    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'sku' => 'YH-'.fake()->unique()->numerify('####'),
            'ordered_unit' => Unit::Pcs,
            'ordered_qty' => 10,
            'qty_base' => 10,
            'unit_cost_rupiah' => 100_000,
            'line_value_rupiah' => 1_000_000,
        ];
    }

    public function pieces(int $qty, int $unitCost): static
    {
        return $this->state(fn () => [
            'ordered_unit' => Unit::Pcs,
            'ordered_qty' => $qty,
            'qty_base' => $qty,
            'unit_cost_rupiah' => $unitCost,
            'line_value_rupiah' => $qty * $unitCost,
        ]);
    }
}
