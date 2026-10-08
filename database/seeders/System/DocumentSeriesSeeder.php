<?php

namespace Database\Seeders\System;

use App\Domain\Numbering\NumberPattern;
use App\Domain\Numbering\ResetRule;
use App\Domain\Numbering\TransactionType;
use App\Models\Settings\DocumentSeries;
use Illuminate\Database\Seeder;

/**
 * One default series per transaction type in DESIGN.md's shape, PREFIX-YYMM-####
 * resetting monthly; masters count without a period (C-00001). Changed on the
 * Numbering screen.
 */
class DocumentSeriesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (TransactionType::cases() as $type) {
            $prefix = $type->defaultPrefix();
            $pattern = $type->isMaster()
                ? NumberPattern::fromFormat("{$prefix}-#####")
                : NumberPattern::fromFormat("{$prefix}-YYMM-####");

            DocumentSeries::query()->firstOrCreate(
                ['transaction_type' => $type->value, 'name' => 'Default'],
                [
                    'reset_rule' => $type->isMaster() ? ResetRule::None : ResetRule::Monthly,
                    'counter_digits' => $type->isMaster() ? 5 : 4,
                    'pattern' => $pattern->toArray(),
                    'used_all_user' => true,
                    'is_default' => true,
                    'is_active' => true,
                ],
            );
        }
    }
}
