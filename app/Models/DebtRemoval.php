<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Credit\DebtRemovalStatus;
use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One claim that a debt was settled outside the system.
 *
 * `status`, the decision fields and the payment link are not fillable: only
 * DebtRemover moves them, because moving them is what posts money. The row
 * itself never touches the books — the payment entry it points at does.
 */
#[Fillable([
    'invoice_id', 'company_id', 'amount_rupiah', 'alasan', 'initiated_by',
])]
class DebtRemoval extends Model
{
    use HasFactory;
    use HasRegion;

    protected $attributes = ['status' => DebtRemovalStatus::Diajukan->value];

    protected function casts(): array
    {
        return [
            'status' => DebtRemovalStatus::class,
            'amount_rupiah' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function paymentEntry(): BelongsTo
    {
        return $this->belongsTo(PaymentEntry::class);
    }
}
