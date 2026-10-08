<?php

namespace App\Models\Inventory;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use App\Models\Company\Branch;
use App\Models\Purchasing\Vendor;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Stock Opname Order: who counts what, where, from when. Not posted; it scopes the count. */
class StockOpnameOrder extends Model implements HasAuditReference
{
    use RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'start_date' => 'date'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'stock_opname_order_users');
    }

    public function itemCategories(): BelongsToMany
    {
        return $this->belongsToMany(ItemCategory::class, 'stock_opname_order_item_categories');
    }

    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'stock_opname_order_vendors');
    }

    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(ItemBrand::class, 'stock_opname_order_item_brands');
    }

    public function results(): HasMany
    {
        return $this->hasMany(StockOpnameResult::class);
    }

    /** The items this order asks to count: stocked items narrowed by the chosen categories, vendors and brands. */
    public function itemsToCount(): Builder
    {
        $categories = $this->itemCategories()->pluck('item_categories.id');
        $vendors = $this->vendors()->pluck('vendors.id');
        $brands = $this->brands()->pluck('item_brands.id');

        return Item::query()->active()
            ->where('item_type', 'inventory')
            ->when($categories->isNotEmpty(), fn ($q) => $q->whereIn('category_id', $categories))
            ->when($vendors->isNotEmpty(), fn ($q) => $q->whereIn('preferred_vendor_id', $vendors))
            ->when($brands->isNotEmpty(), fn ($q) => $q->whereIn('brand_id', $brands))
            ->orderBy('number');
    }

    public function auditReference(): string
    {
        return $this->number;
    }
}
