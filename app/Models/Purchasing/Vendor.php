<?php

namespace App\Models\Purchasing;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use App\Domain\Shared\Enums\TaxDocumentCode;
use App\Domain\Shared\Enums\WpType;
use App\Domain\Shared\RecordInUse;
use App\Models\Company\Branch;
use App\Models\Company\OpeningBalance;
use App\Models\Company\PaymentTerm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Vendor extends Model implements HasAuditReference
{
    use RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'service_seller' => 'boolean',
            'tax_same_as_bill' => 'boolean',
            'default_inc_tax' => 'boolean',
            'use_bill_number' => 'boolean',
            'default_purchase_disc' => 'decimal:4',
            'wp_type' => WpType::class,
            'document_code' => TaxDocumentCode::class,
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(VendorCategory::class, 'category_id');
    }

    public function vendorType(): BelongsTo
    {
        return $this->belongsTo(VendorType::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(VendorContact::class)->orderBy('sort');
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(VendorBankAccount::class)->orderBy('sort');
    }

    /** Opening balances post to the books, so a party holding any stays; deactivate it instead. */
    protected static function booted(): void
    {
        static::deleting(function (self $party): void {
            if ($party->openingBalances()->exists()) {
                throw new RecordInUse($party, [__('Opening balances')]);
            }
        });
    }

    public function openingBalances(): MorphMany
    {
        return $this->morphMany(OpeningBalance::class, 'party')->orderBy('sort');
    }

    public function auditReference(): string
    {
        return "{$this->number} {$this->name}";
    }
}
