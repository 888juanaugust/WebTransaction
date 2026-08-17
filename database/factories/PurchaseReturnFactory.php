<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Documents\DocumentNumberGenerator;
use App\Models\GoodsReceipt;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseReturn> */
class PurchaseReturnFactory extends Factory
{
    protected $model = PurchaseReturn::class;

    public function definition(): array
    {
        return [
            'nomor' => app(DocumentNumberGenerator::class)->nextPurchaseReturnNumber(),
            'supplier_id' => Supplier::factory(),
            'goods_receipt_id' => GoodsReceipt::factory(),
            'warehouse_id' => Warehouse::factory(),
            'tanggal' => now()->toDateString(),
            'alasan' => 'Barang tidak sesuai pesanan',
            'created_by' => User::factory(),
            'status' => PurchaseReturn::STATUS_DRAFT,
        ];
    }
}
