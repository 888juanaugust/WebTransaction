<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Uom\Unit;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GoodsReceiptLine> */
class GoodsReceiptLineFactory extends Factory
{
    protected $model = GoodsReceiptLine::class;

    public function definition(): array
    {
        return [
            'goods_receipt_id' => GoodsReceipt::factory(),
            'sku' => 'YH-'.fake()->unique()->numerify('####'),
            'ordered_unit' => Unit::Pcs,
            'ordered_qty' => 10,
            'qty_base' => 10,
            'unit_cost_rupiah' => 100_000,
            'line_value_rupiah' => 1_000_000,
        ];
    }

    /** A line of pieces at a stated cost each. */
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

    /** Cartons, priced per carton — the shape a supplier invoice actually has. */
    public function cartons(int $cartons, int $perCarton, int $costPerCarton): static
    {
        return $this->state(fn () => [
            'ordered_unit' => Unit::Ctn,
            'ordered_qty' => $cartons,
            'qty_per_ctn_snapshot' => $perCarton,
            'qty_base' => $cartons * $perCarton,
            'unit_cost_rupiah' => $costPerCarton,
            'line_value_rupiah' => $cartons * $costPerCarton,
        ]);
    }
}
