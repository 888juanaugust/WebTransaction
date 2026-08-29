<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the rekening koran, as the bank printed it.
 *
 * `arah` is from the account holder's seat: masuk is money arriving, keluar
 * is money leaving. A matched line points at the Bank journal leg it turned
 * out to be; the match is metadata about a tick, never a posting of its own.
 */
#[Fillable([
    'bank_statement_import_id', 'urutan', 'tanggal', 'uraian', 'arah',
    'amount_rupiah', 'saldo_rupiah', 'status', 'keterangan',
])]
class BankStatementLine extends Model
{
    use HasFactory;

    public const STATUS_BELUM = 'belum';

    public const STATUS_TERCOCOK = 'tercocok';

    public const STATUS_DIABAIKAN = 'diabaikan';

    public const STATUS_ERROR = 'error';

    public const ARAH_MASUK = 'masuk';

    public const ARAH_KELUAR = 'keluar';

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'amount_rupiah' => 'integer',
            'saldo_rupiah' => 'integer',
            'matched_at' => 'datetime',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'bank_statement_import_id');
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class);
    }

    public function paymentEntry(): BelongsTo
    {
        return $this->belongsTo(PaymentEntry::class);
    }

    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}
