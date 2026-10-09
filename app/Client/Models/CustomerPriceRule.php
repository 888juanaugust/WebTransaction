<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use App\Models\Inventory\Item;
use App\Models\Sales\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** A deal with one customer: a price or a discount for one item or for every item, from a quantity, between dates. */
class CustomerPriceRule extends Model implements HasAuditReference
{
    use RecordsActivity;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $rule): void {
            $rule->created_by ??= auth()->id();
        });
    }

    protected function casts(): array
    {
        return ['min_base_quantity' => 'decimal:4', 'price' => 'integer', 'discount_percent' => 'decimal:4', 'effective_from' => 'date', 'effective_until' => 'date', 'is_active' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Active and in force on the date (an open end means no end). */
    public function scopeInForceOn(Builder $query, string $date): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date));
    }

    public function auditReference(): string
    {
        return ($this->customer?->name ?? (string) $this->customer_id).' · '.($this->item?->number ?? __('every item'));
    }

    public function isBlanket(): bool
    {
        return $this->item_id === null;
    }

    public static function inForce(int $customerId, int $itemId, string|Carbon $date): Collection
    {
        return static::query()->where('customer_id', $customerId)
            ->where(fn (Builder $q) => $q->where('item_id', $itemId)->orWhereNull('item_id'))
            ->inForceOn(Carbon::parse($date)->toDateString())
            ->get();
    }
}
