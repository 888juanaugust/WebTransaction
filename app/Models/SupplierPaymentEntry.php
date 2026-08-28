<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money out, append-only. Never mutate a row; insert a reversing entry.
 */
#[Fillable([
    'supplier_id', 'supplier_bill_id', 'amount_rupiah', 'kind',
    'referensi', 'actor_id', 'reverses_entry_id', 'paid_at', 'catatan',
])]
class SupplierPaymentEntry extends Model
{
    use HasFactory;
    use HasRegion;

    public const UPDATED_AT = null;

    public const KIND_PAYMENT = 'payment';

    public const KIND_REVERSAL = 'reversal';

    public const KIND_ADJUSTMENT = 'adjustment';

    protected function casts(): array
    {
        return [
            'amount_rupiah' => 'integer',
            'paid_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function supplierBill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
