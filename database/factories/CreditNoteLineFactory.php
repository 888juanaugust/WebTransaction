<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CreditNoteLine> */
class CreditNoteLineFactory extends Factory
{
    protected $model = CreditNoteLine::class;

    public function definition(): array
    {
        return [
            'credit_note_id' => CreditNote::factory(),
            'sku' => 'YH-1001',
            'urutan' => 1,
            'ordered_unit' => 'PCS',
            'ordered_qty' => 1,
            'qty_per_ctn_snapshot' => 1,
            'qty_base' => 1,
        ];
    }
}
