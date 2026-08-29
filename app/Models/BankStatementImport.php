<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded rekening koran file, parsed into lines.
 *
 * Belongs to the draft reconciliation it was uploaded into, and is deleted
 * with it — the raw file on disk stays either way. No region column of its
 * own: the reconciliation above it carries the region.
 */
#[Fillable([
    'bank_reconciliation_id', 'source_file_path', 'original_name',
    'status', 'jumlah_baris', 'jumlah_error', 'catatan', 'created_by',
])]
class BankStatementImport extends Model
{
    use HasFactory;

    public const STATUS_SELESAI = 'selesai';

    public const STATUS_GAGAL = 'gagal';

    protected function casts(): array
    {
        return [
            'jumlah_baris' => 'integer',
            'jumlah_error' => 'integer',
        ];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class)->orderBy('urutan');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
