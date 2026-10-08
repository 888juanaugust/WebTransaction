<?php

namespace App\Models\Sales;

use App\Domain\Approval\RequiresApproval;
use App\Domain\Audit\HasAuditReference;
use App\Domain\Documents\PricedDocument;
use App\Models\Company\Branch;
use App\Models\Company\PaymentTerm;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Sales Order: what the customer ordered; approved before it ships; deliveries and invoices pull from it. Not posted. */
class SalesOrder extends Model implements HasAuditReference
{
    use PricedDocument;
    use RequiresApproval;

    public const AWAITING = 'awaiting';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'ship_date' => 'date', 'approved_at' => 'datetime', 'taxable' => 'boolean', 'inclusive_tax' => 'boolean', 'is_printed' => 'boolean',
            'subtotal' => 'integer', 'discount_amount' => 'integer', 'charges_total' => 'integer', 'dpp_total' => 'integer', 'tax_total' => 'integer', 'total' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class)->orderBy('sort')->chaperone(); // each line knows its document without a query
    }

    public function charges(): HasMany
    {
        return $this->hasMany(SalesOrderCharge::class)->orderBy('sort');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->approval_status === self::APPROVED;
    }

    /** The value of what is still to be delivered, at the order's prices. */
    public function remainingValue(): int
    {
        $value = 0;
        foreach ($this->lines as $line) {
            if (BigDecimal::of((string) $line->base_quantity)->isZero()) {
                continue;
            }
            $value += BigDecimal::of((int) $line->amount + (int) $line->tax_amount)
                ->multipliedBy($line->remainingQuantity())
                ->dividedBy((string) $line->base_quantity, 0, RoundingMode::HalfUp)
                ->toInt();
        }

        return $value;
    }

    public function auditReference(): string
    {
        return $this->number;
    }
}
