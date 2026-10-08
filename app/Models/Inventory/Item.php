<?php

namespace App\Models\Inventory;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use App\Domain\Shared\Enums\ItemType;
use App\Models\Company\Branch;
use App\Models\Company\TaxCode;
use App\Models\GeneralLedger\Account;
use App\Models\Purchasing\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model implements HasAuditReference
{
    use RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'item_type' => ItemType::class,
            'default_discount' => 'decimal:4',
            'sell_price' => 'integer',
            'purchase_price' => 'integer',
            'min_sell_qty' => 'decimal:4',
            'min_purchase_qty' => 'decimal:4',
            'min_stock' => 'decimal:4',
            'use_wholesale_price' => 'boolean',
            'length_cm' => 'decimal:2',
            'width_cm' => 'decimal:2',
            'height_cm' => 'decimal:2',
            'weight_gr' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function unit1(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit1_id');
    }

    public function vendorUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'vendor_unit_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(ItemBrand::class, 'brand_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    public function preferredVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'preferred_vendor_id');
    }

    public function substitute(): BelongsTo
    {
        return $this->belongsTo(self::class, 'substitute_item_id');
    }

    public function tax1(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'tax1_id');
    }

    public function tax3(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'tax3_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** The extra units beyond unit 1, with their ratios. */
    public function units(): HasMany
    {
        return $this->hasMany(ItemUnit::class)->orderBy('sort');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ItemPrice::class)->orderBy('sort');
    }

    public function components(): HasMany
    {
        return $this->hasMany(ItemComponent::class, 'group_item_id')->orderBy('sort');
    }

    public function minimumStocks(): HasMany
    {
        return $this->hasMany(ItemMinimumStock::class);
    }

    public function openingStocks(): HasMany
    {
        return $this->hasMany(ItemOpeningStock::class)->orderBy('sort');
    }

    /** The account for a purpose: the item's own, else its category's, else the preference default (resolved by the caller). */
    public function accountFor(string $purpose): ?Account
    {
        $column = "{$purpose}_account_id";
        $id = $this->{$column} ?? $this->category?->{$column};

        return $id ? Account::query()->find($id) : null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function auditReference(): string
    {
        return "{$this->number} {$this->name}";
    }
}
