<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockTransferLine> */
class StockTransferLineFactory extends Factory
{
    protected $model = StockTransferLine::class;

    public function definition(): array
    {
        return [
            'stock_transfer_id' => StockTransfer::factory(),
            'sku' => 'YH-1001',
            'urutan' => 1,
            'ordered_unit' => 'PCS',
            'ordered_qty' => 1,
            'qty_per_ctn_snapshot' => 1,
            'qty_base' => 1,
        ];
    }
}
