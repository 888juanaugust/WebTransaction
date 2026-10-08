<?php

namespace App\Models\Purchasing;

use App\Domain\Audit\HasAuditReference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** Vendor Price: a vendor's prices per item from a date, the default on purchase orders. */
class VendorPrice extends Model implements HasAuditReference
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'end_date' => 'date'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VendorPriceLine::class)->orderBy('sort');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** The price a vendor charges for an item on a date, from the latest list in force; null when none. */
    public static function lookup(int $vendorId, int $itemId, \DateTimeInterface|string $date, ?int $unitId = null): ?string
    {
        $date = Carbon::parse($date)->toDateString();
        $line = VendorPriceLine::query()
            ->whereHas('vendorPrice', fn (Builder $q) => $q->where('vendor_id', $vendorId)->where('trans_date', '<=', $date)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date)))
            ->where('item_id', $itemId)
            ->when($unitId, fn ($q) => $q->where(fn ($q) => $q->where('unit_id', $unitId)->orWhereNull('unit_id')))
            ->join('vendor_prices', 'vendor_prices.id', '=', 'vendor_price_lines.vendor_price_id')
            ->orderByDesc('vendor_prices.trans_date')
            ->orderByRaw('vendor_price_lines.unit_id IS NULL')
            ->select('vendor_price_lines.*')
            ->first();

        return $line ? (string) $line->price : null;
    }

    public function auditReference(): string
    {
        return $this->number;
    }
}
