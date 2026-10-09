<?php

declare(strict_types=1);

namespace App\Client\Seeders;

use App\Domain\Numbering\NumberPattern;
use App\Domain\Numbering\ResetRule;
use App\Domain\Numbering\TransactionType;
use App\Models\Settings\DocumentSeries;
use Illuminate\Database\Seeder;

/**
 * The documents that move goods and money per branch carry the branch code
 * in their number and count per branch each month: SO-JKT-2610-0001. Only
 * the base's untouched default series are reshaped; one the owner changed
 * on the Numbering screen is left alone.
 */
class DocumentSeriesSeeder extends Seeder
{
    /** @var list<TransactionType> */
    public const BRANCHED = [
        TransactionType::SalesQuotation,
        TransactionType::SalesOrder,
        TransactionType::DeliveryOrder,
        TransactionType::SalesInvoice,
        TransactionType::SalesReturn,
        TransactionType::GoodsReceipt,
        TransactionType::ItemTransfer,
        TransactionType::InventoryAdjustment,
        TransactionType::CashBankVoucher,
    ];

    public function run(): void
    {
        foreach (self::BRANCHED as $type) {
            $prefix = $type->defaultPrefix();
            $base = NumberPattern::fromFormat("{$prefix}-YYMM-####")->toArray();
            $branched = NumberPattern::fromFormat("{$prefix}-BR-YYMM-####")->toArray();

            DocumentSeries::query()
                ->where('transaction_type', $type->value)
                ->where('name', 'Default')
                ->where('is_default', true)
                ->get()
                ->filter(fn (DocumentSeries $series) => $series->pattern()->toArray() === $base)
                ->each(fn (DocumentSeries $series) => $series->forceFill(['pattern' => $branched, 'reset_rule' => ResetRule::Monthly])->saveQuietly());
        }
    }
}
