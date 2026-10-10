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

    /** Central's own prefixes where they differ from the base's: the delivery is the surat jalan. */
    public const PREFIXES = ['delivery_order' => 'SJ'];

    public function run(): void
    {
        foreach (self::BRANCHED as $type) {
            $prefix = $type->defaultPrefix();
            $own = self::PREFIXES[$type->value] ?? $prefix;
            $base = NumberPattern::fromFormat("{$prefix}-YYMM-####")->toArray();
            $earlier = NumberPattern::fromFormat("{$prefix}-BR-YYMM-####")->toArray(); // a series this seeder shaped before it had its own prefix
            $branched = NumberPattern::fromFormat("{$own}-BR-YYMM-####")->toArray();

            DocumentSeries::query()
                ->where('transaction_type', $type->value)
                ->where('name', 'Default')
                ->where('is_default', true)
                ->get()
                ->filter(fn (DocumentSeries $series) => in_array($series->pattern()->toArray(), [$base, $earlier], true) && $series->pattern()->toArray() !== $branched)
                ->each(fn (DocumentSeries $series) => $series->forceFill(['pattern' => $branched, 'reset_rule' => ResetRule::Monthly])->saveQuietly());
        }
    }
}
