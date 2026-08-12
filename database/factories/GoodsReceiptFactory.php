<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Documents\DocumentNumberGenerator;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GoodsReceipt> */
class GoodsReceiptFactory extends Factory
{
    protected $model = GoodsReceipt::class;

    public function definition(): array
    {
        return [
            'nomor' => app(DocumentNumberGenerator::class)->nextGoodsReceiptNumber(),
            'supplier_id' => Supplier::factory(),
            'warehouse_id' => Warehouse::factory(),
            'tanggal_terima' => now()->toDateString(),
            'status' => GoodsReceipt::STATUS_DRAFT,
        ];
    }
}
