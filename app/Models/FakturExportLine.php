<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One invoice inside a filing, and the serial that came back for it. */
#[Fillable(['faktur_export_id', 'invoice_id', 'referensi', 'nsfp', 'nsfp_recorded_at'])]
class FakturExportLine extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'nsfp_recorded_at' => 'datetime',
        ];
    }

    public function fakturExport(): BelongsTo
    {
        return $this->belongsTo(FakturExport::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
