<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A posted journal entry. Immutable once written.
 *
 * There is no `updated_at` on this table and that is deliberate: nothing about
 * a posted entry changes, except the one column that records it was reversed.
 * Corrections are new entries.
 */
#[Fillable([
    'nomor', 'tanggal', 'keterangan', 'source_type', 'source_id', 'jenis',
    'total_debit_rupiah', 'total_kredit_rupiah', 'posted_by', 'posted_at',
    'reverses_entry_id',
])]
class JournalEntry extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    /** Manual journals an accountant writes; everything else names a document. */
    public const JENIS_MANUAL = 'manual';

    public const JENIS_PENJUALAN = 'penjualan';

    public const JENIS_HPP = 'hpp';

    public const JENIS_PENERIMAAN_BARANG = 'penerimaan_barang';

    public const JENIS_TAGIHAN_PEMASOK = 'tagihan_pemasok';

    public const JENIS_PEMBAYARAN_PELANGGAN = 'pembayaran_pelanggan';

    public const JENIS_PEMBAYARAN_PEMASOK = 'pembayaran_pemasok';

    /** A credit note: the sale unwound, and the goods back on the shelf. */
    public const JENIS_NOTA_KREDIT = 'nota_kredit';

    public const JENIS_HPP_RETUR = 'hpp_retur';

    /**
     * The year-end entry that zeroes income and expense into Laba Ditahan.
     * Its source is the December period that produced it, so a period can only
     * ever have one — the same unique index every other posting relies on.
     */
    public const JENIS_TUTUP_BUKU = 'tutup_buku';

    public const JENIS_PEMBALIKAN = 'pembalikan';

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'total_debit_rupiah' => 'integer',
            'total_kredit_rupiah' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('urutan')->orderBy('id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo('source');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /** The entry that undid this one, if any. */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_entry_id');
    }

    /** The entry this one undoes, if it is itself a reversal. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function isReversed(): bool
    {
        return $this->reversed_by_entry_id !== null;
    }

    public function isReversal(): bool
    {
        return $this->reverses_entry_id !== null;
    }

    /** Both sides are equal by construction; either one is "the amount". */
    public function amount(): int
    {
        return (int) $this->total_debit_rupiah;
    }

    public function isBalanced(): bool
    {
        return (int) $this->total_debit_rupiah === (int) $this->total_kredit_rupiah;
    }

    public function scopeUpTo(Builder $query, \DateTimeInterface $date): Builder
    {
        return $query->whereDate('tanggal', '<=', $date);
    }

    public function scopeBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->whereDate('tanggal', '>=', $from)->whereDate('tanggal', '<=', $to);
    }
}
