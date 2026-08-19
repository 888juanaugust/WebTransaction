<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Expenses\PaidFrom;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One expense, already paid.
 *
 * `reverses_expense_id` is not fillable: only ExpenseRecorder sets it, because
 * setting it is what turns a row into the correction of another one.
 */
#[Fillable([
    'nomor', 'tanggal', 'account_id', 'dibayar_dari', 'amount_rupiah',
    'keterangan', 'supplier_id', 'referensi', 'catatan', 'created_by',
])]
class Expense extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'dibayar_dari' => PaidFrom::class,
            'amount_rupiah' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** What this one corrects, if it is a correction. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_expense_id');
    }

    /** The correction against this one, if somebody has made it. */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_expense_id');
    }

    public function isReversal(): bool
    {
        return $this->reverses_expense_id !== null;
    }

    public function isReversed(): bool
    {
        return $this->reversal()->exists();
    }
}
