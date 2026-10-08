<?php

namespace App\Models\Purchasing;

use App\Domain\Approval\RequiresApproval;
use App\Domain\Audit\HasAuditReference;
use App\Domain\Documents\PricedDocument;
use App\Models\Company\Branch;
use App\Models\Company\PaymentTerm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Purchase Order: what was ordered from a vendor; receipts and invoices pull from it. Not posted. */
class PurchaseOrder extends Model implements HasAuditReference
{
    use PricedDocument;
    use RequiresApproval;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'ship_date' => 'date', 'taxable' => 'boolean', 'inclusive_tax' => 'boolean', 'is_printed' => 'boolean',
            'subtotal' => 'integer', 'discount_amount' => 'integer', 'charges_total' => 'integer', 'dpp_total' => 'integer', 'tax_total' => 'integer', 'total' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('sort')->chaperone(); // each line knows its document without a query
    }

    public function charges(): HasMany
    {
        return $this->hasMany(PurchaseOrderCharge::class)->orderBy('sort');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function auditReference(): string
    {
        return $this->number;
    }
}
