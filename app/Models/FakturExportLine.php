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

    /**
     * Region scope lifted, matching the filing that created this row.
     *
     * A filing carries the whole company's month, so a line here can point at
     * a faktur booked in another region's books. Scoped, this relation
     * resolved to null for exactly those — and `NsfpRecorder` writes the
     * serial through `$line->invoice?->…`, so the number landed on the export
     * line, not on the faktur, and nothing said so.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScope('region');
    }
}
