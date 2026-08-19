<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Expenses\PaidFrom;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money a customer paid before anything was owed.
 *
 * There is no draft state and no posting step, unlike most documents here. A
 * deposit is recorded because the money already arrived — the event happened in
 * the bank, not on this screen, and a draft would mean cash sitting in the
 * account that the books have not heard about. It posts on the spot, and a
 * mistake is unwound by refunding rather than by editing.
 *
 * `terpakai_rupiah`, `dikembalikan_rupiah` and `status` are not fillable. Only
 * CustomerDepositRegister moves them, in the same transaction as the movement
 * row that justifies the new figure.
 */
#[Fillable([
    'nomor', 'company_id', 'order_id', 'tanggal', 'jumlah_rupiah',
    'diterima_di', 'referensi', 'catatan', 'created_by',
])]
class CustomerDeposit extends Model
{
    use HasFactory;

    public const STATUS_HELD = 'held';

    public const STATUS_CLOSED = 'closed';

    protected $attributes = ['status' => self::STATUS_HELD];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'jumlah_rupiah' => 'integer',
            'terpakai_rupiah' => 'integer',
            'dikembalikan_rupiah' => 'integer',
            // Named from the expense side, where it was first needed. Both
            // documents are asking the same question: which account holds it.
            'diterima_di' => PaidFrom::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CustomerDepositMovement::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** What is still ours to hold. */
    public function sisaRupiah(): int
    {
        return (int) $this->jumlah_rupiah
            - (int) $this->terpakai_rupiah
            - (int) $this->dikembalikan_rupiah;
    }

    public function isHeld(): bool
    {
        return $this->status === self::STATUS_HELD;
    }

    /** Deposits with money still on them. What the liability account equals. */
    public function scopeHeld(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_HELD);
    }
}
