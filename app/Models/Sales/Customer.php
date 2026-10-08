<?php

namespace App\Models\Sales;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use App\Domain\Shared\Enums\TaxDocumentCode;
use App\Domain\Shared\Enums\WpType;
use App\Domain\Shared\RecordInUse;
use App\Models\Company\Branch;
use App\Models\Company\Employee;
use App\Models\Company\OpeningBalance;
use App\Models\Company\PaymentTerm;
use App\Models\Inventory\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Customer extends Model implements HasAuditReference
{
    use RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ship_same_as_bill' => 'boolean',
            'tax_same_as_bill' => 'boolean',
            'default_inc_tax' => 'boolean',
            'default_sales_disc' => 'decimal:4',
            'wp_type' => WpType::class,
            'document_code' => TaxDocumentCode::class,
            'credit_limit_age_enabled' => 'boolean',
            'credit_limit_amount_enabled' => 'boolean',
            'credit_limit_amount' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CustomerCategory::class, 'category_id');
    }

    public function priceCategory(): BelongsTo
    {
        return $this->belongsTo(PriceCategory::class);
    }

    public function discountCategory(): BelongsTo
    {
        return $this->belongsTo(DiscountCategory::class);
    }

    /** The price category whose discount adjustments apply to the customer; none means their price category's. */
    public function discountPriceCategory(): BelongsTo
    {
        return $this->belongsTo(PriceCategory::class, 'discount_price_category_id');
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'salesman_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    public function parentCustomer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_customer_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class)->orderBy('sort');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class)->orderBy('sort');
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

    /** The billing address on one line, as lists print it. */
    public function billAddress(): string
    {
        return collect([$this->bill_street, $this->bill_city, $this->bill_province, $this->bill_zip_code])->filter()->join(', ');
    }

    public function taxAddress(): string
    {
        if ($this->tax_same_as_bill) {
            return $this->billAddress();
        }

        return collect([$this->tax_street, $this->tax_city, $this->tax_province, $this->tax_zip_code])->filter()->join(', ');
    }
}
