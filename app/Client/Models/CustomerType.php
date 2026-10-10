<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use App\Models\Company\PaymentTerm;
use App\Models\Sales\Customer;
use App\Models\Sales\PriceCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of customer (bengkel, toko sparepart, distributor) and the terms
 * it trades on: a price tier whose rules are its promo, a payment term for
 * its due days, and a credit age limit. The notice and freeze days stay
 * the company's.
 */
class CustomerType extends Model implements HasAuditReference
{
    use RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['credit_limit_age_days' => 'integer', 'is_active' => 'boolean'];
    }

    public function priceCategory(): BelongsTo
    {
        return $this->belongsTo(PriceCategory::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function auditReference(): string
    {
        return $this->code.' · '.$this->name;
    }

    /** The customer columns this type sets. @return array<string, mixed> */
    public function terms(): array
    {
        return [
            'price_category_id' => $this->price_category_id,
            'payment_term_id' => $this->payment_term_id,
            'credit_limit_age_enabled' => $this->credit_limit_age_days !== null && $this->credit_limit_age_days > 0,
            'credit_limit_age_days' => (int) ($this->credit_limit_age_days ?? 0),
        ];
    }
}
