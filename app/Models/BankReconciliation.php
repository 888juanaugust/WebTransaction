<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One month's proof that the Bank account is real.
 *
 * The summary figures are not fillable and neither is `status`. Both are
 * written by BankReconciler at finalisation, from the ticks — a reconciliation
 * whose totals could be typed would prove nothing at all.
 */
#[Fillable([
    'nomor', 'tanggal_rekening', 'saldo_rekening_rupiah', 'catatan', 'created_by',
])]
class BankReconciliation extends Model
{
    use HasFactory;
    use HasRegion;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SELESAI = 'selesai';

    protected $attributes = ['status' => self::STATUS_DRAFT];

    protected function casts(): array
    {
        return [
            'tanggal_rekening' => 'date',
            'saldo_rekening_rupiah' => 'integer',
            'saldo_buku_rupiah' => 'integer',
            'setoran_beredar_rupiah' => 'integer',
            'penarikan_beredar_rupiah' => 'integer',
            'selisih_rupiah' => 'integer',
            'finalised_at' => 'datetime',
        ];
    }

    public function ticks(): HasMany
    {
        return $this->hasMany(BankReconciliationLine::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BankReconciliationItem::class)->orderBy('tanggal')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function finalisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalised_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isFinalised(): bool
    {
        return $this->status === self::STATUS_SELESAI;
    }

    public function scopeFinalised(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SELESAI);
    }
}
