<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to a deposit: it was applied, or it was given back.
 *
 * Append-only. The deposit's remaining balance is its amount less the sum of
 * these, so a row here is the only thing that may change that balance.
 */
#[Fillable([
    'customer_deposit_id', 'jenis', 'jumlah_rupiah', 'invoice_id',
    'payment_entry_id', 'tanggal', 'catatan', 'actor_id',
])]
class CustomerDepositMovement extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const JENIS_PAKAI = 'pakai';

    public const JENIS_KEMBALI = 'kembali';

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'jumlah_rupiah' => 'integer',
        ];
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(CustomerDeposit::class, 'customer_deposit_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function paymentEntry(): BelongsTo
    {
        return $this->belongsTo(PaymentEntry::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
