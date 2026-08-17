<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Uom\Unit;
use App\Models\GoodsReceiptLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseReturnLine> */
class PurchaseReturnLineFactory extends Factory
{
    protected $model = PurchaseReturnLine::class;

    public function definition(): array
    {
        return [
            'purchase_return_id' => PurchaseReturn::factory(),
            'goods_receipt_line_id' => GoodsReceiptLine::factory(),
            'sku' => 'YH-'.fake()->unique()->numerify('####'),
            'ordered_unit' => Unit::Pcs,
            'ordered_qty' => 1,
            'qty_base' => 1,
        ];
    }

    /** Send back part of a receipt line, in base units. */
    public function returning(GoodsReceiptLine $line, int $qtyBase): static
    {
        return $this->state(fn () => [
            'goods_receipt_line_id' => $line->id,
            'sku' => $line->sku,
            'ordered_unit' => Unit::Pcs,
            'ordered_qty' => $qtyBase,
            'qty_per_ctn_snapshot' => $line->qty_per_ctn_snapshot ?: 1,
            'qty_base' => $qtyBase,
        ]);
    }
}
