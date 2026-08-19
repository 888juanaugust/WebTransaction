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

    /** A bilyet giro changing hands: the balance moves into a giro account. */
    public const JENIS_GIRO = 'giro';

    /**
     * The same giro leaving the register — cleared, bounced or handed back.
     *
     * A separate type rather than a second entry of the same one, because
     * `Ledger::post()` is idempotent on (source, jenis) and would otherwise
     * treat the release as a duplicate of the issue and silently drop it. The
     * giro would stay parked in Piutang Giro forever and the control account
     * would drift the first time anything cleared.
     */
    public const JENIS_GIRO_SELESAI = 'giro_selesai';

    /**
     * Something found on a bank statement that the books had never heard of.
     *
     * A fee, interest, a standing order, a transfer nobody matched. Its source
     * is the reconciliation *item* rather than the reconciliation, so a month
     * with four of them posts four entries — `Ledger::post()` is idempotent on
     * (source, jenis), and one source would collapse them into one.
     */
    public const JENIS_REKONSILIASI_BANK = 'rekonsiliasi_bank';

    /**
     * Money out on something other than goods: rent, wages, fuel, the courier.
     *
     * Distinct from JENIS_MANUAL, which has no document behind it. Every one of
     * these is sourced on an `expenses` row, which is what makes double-clicking
     * the button harmless — the ledger is idempotent on (source, jenis).
     */
    public const JENIS_BEBAN = 'beban';

    /** Buying something the business uses rather than sells. */
    public const JENIS_AKTIVA_TETAP = 'aktiva_tetap';

    /**
     * One month's wear on one asset.
     *
     * Sourced on the depreciation row, not the asset — an asset depreciates
     * forty-eight times, and one source would collapse them into one entry.
     */
    public const JENIS_PENYUSUTAN = 'penyusutan';

    /** Selling or scrapping one, and the gain or loss that falls out. */
    public const JENIS_PELEPASAN_ASET = 'pelepasan_aset';

    /** Goods going back to a supplier, and the payable coming down with them. */
    public const JENIS_RETUR_PEMBELIAN = 'retur_pembelian';

    /** What a stock count found missing, or found extra. */
    public const JENIS_SELISIH_OPNAME = 'selisih_opname';

    /** Freight and duty coming off the clearing account onto the goods. */
    public const JENIS_BIAYA_PEROLEHAN = 'biaya_perolehan';

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
