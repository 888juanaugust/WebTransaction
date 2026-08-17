<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GoodsReceiptLine;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplierBillLine> */
class SupplierBillLineFactory extends Factory
{
    protected $model = SupplierBillLine::class;

    public function definition(): array
    {
        return [
            'supplier_bill_id' => SupplierBill::factory(),
            'qty_base' => 10,
            'unit_cost_rupiah' => 100_000,
            'line_total_rupiah' => 1_000_000,
        ];
    }

    /** A charge with no goods behind it — freight, duty, handling. */
    public function biaya(int $amount, string $deskripsi = 'Ongkos angkut'): static
    {
        return $this->state(fn () => [
            'jenis' => SupplierBillLine::JENIS_BIAYA,
            'goods_receipt_line_id' => null,
            'sku' => null,
            'deskripsi' => $deskripsi,
            'qty_base' => 0,
            'unit_cost_rupiah' => 0,
            'line_total_rupiah' => $amount,
        ]);
    }

    /** A line billing for goods that were actually received. */
    public function forReceiptLine(GoodsReceiptLine $line, ?int $value = null): static
    {
        return $this->state(fn () => [
            'goods_receipt_line_id' => $line->id,
            'sku' => $line->sku,
            'qty_base' => $line->qty_base,
            'unit_cost_rupiah' => $line->unit_cost_rupiah,
            'line_total_rupiah' => $value ?? $line->line_value_rupiah,
        ]);
    }
}
