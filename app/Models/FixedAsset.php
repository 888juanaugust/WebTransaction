<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Assets\DepreciationGroup;
use App\Domain\Expenses\PaidFrom;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing the business owns and uses rather than sells.
 *
 * `status` and everything about disposal are not fillable: only
 * FixedAssetRegister moves them, because moving them is what writes the
 * journal.
 */
#[Fillable([
    'nomor', 'nama', 'keterangan', 'kelompok', 'masa_manfaat_bulan',
    'tanggal_perolehan', 'harga_perolehan_rupiah', 'nilai_residu_rupiah',
    'kategori', 'dibayar_dari', 'created_by',
])]
class FixedAsset extends Model
{
    use HasFactory;

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_DILEPAS = 'dilepas';

    protected $attributes = ['status' => self::STATUS_AKTIF];

    protected function casts(): array
    {
        return [
            'kelompok' => DepreciationGroup::class,
            'dibayar_dari' => PaidFrom::class,
            'tanggal_perolehan' => 'date',
            'tanggal_pelepasan' => 'date',
            'harga_perolehan_rupiah' => 'integer',
            'nilai_residu_rupiah' => 'integer',
            'harga_jual_rupiah' => 'integer',
            'masa_manfaat_bulan' => 'integer',
        ];
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function disposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disposed_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_AKTIF;
    }

    /** What can still be written off: cost less whatever is left at the end. */
    public function depreciableBase(): int
    {
        return (int) $this->harga_perolehan_rupiah - (int) $this->nilai_residu_rupiah;
    }

    public function accumulated(): int
    {
        return (int) $this->depreciations()->sum('amount_rupiah');
    }

    /** Cost less what has been taken off it so far. */
    public function bookValue(): int
    {
        return (int) $this->harga_perolehan_rupiah - $this->accumulated();
    }

    public function isFullyDepreciated(): bool
    {
        return $this->accumulated() >= $this->depreciableBase();
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AKTIF);
    }
}
