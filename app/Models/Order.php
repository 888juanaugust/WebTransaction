<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Orders\OrderStatus;
use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'nomor', 'company_id', 'warehouse_id', 'created_by', 'sales_user_id',
    'po_pelanggan', 'catatan', 'placed_by_customer_user_id',
])]
class Order extends Model
{
    use HasFactory;
    use HasRegion;

    /**
     * `status` is deliberately absent from the fillable list — only
     * OrderStateMachine may write it, so no form or controller can move an
     * order by mass assignment.
     *
     * A new order is a draft. Declaring it here rather than relying on the
     * column default means a freshly created instance reports its own status
     * honestly before it is reloaded, without opening it to mass assignment.
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal_rupiah' => 'integer',
            'discount_rupiah' => 'integer',
            'dpp_rupiah' => 'integer',
            'ppn_rupiah' => 'integer',
            'total_rupiah' => 'integer',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'completed_at' => 'datetime',
            'reservation_expires_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        /*
         * Region-free: since orders split across warehouses, a document in
         * one region's books can belong to a customer homed in another.
         * Reading the customer through a document you can already see is
         * not a leak — the document's own scope is the gate.
         */
        return $this->belongsTo(Company::class)->withoutGlobalScope('region');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('urutan');
    }

    /** The original this order was split off, when approval scattered it. */
    public function splitParent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'split_parent_id');
    }

    /** The siblings this order split into, one per extra warehouse. */
    public function splitChildren(): HasMany
    {
        return $this->hasMany(self::class, 'split_parent_id')->withoutGlobalScope('region');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function priceListVersion(): BelongsTo
    {
        return $this->belongsTo(PriceListVersion::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Set when the buyer placed this themselves in the portal. */
    public function placedByCustomerUser(): BelongsTo
    {
        return $this->belongsTo(CustomerUser::class, 'placed_by_customer_user_id');
    }

    public function placedInPortal(): bool
    {
        return $this->placed_by_customer_user_id !== null;
    }

    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Submitted);
    }

    public function scopeReadyToPick(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Paid);
    }
}
