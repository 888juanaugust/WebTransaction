<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Orders\OrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'from_status', 'to_status', 'actor_id', 'customer_actor_id', 'alasan', 'meta'])]
class OrderEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Set when a buyer moved their own order from the portal. */
    public function customerActor(): BelongsTo
    {
        return $this->belongsTo(CustomerUser::class, 'customer_actor_id');
    }

    /**
     * Who did this, in words. Three cases, and "the system" is a real answer
     * rather than a gap: the sweep and invoice settlement have no person
     * behind them.
     */
    public function actorLabel(): string
    {
        return $this->actor?->name
            ?? $this->customerActor?->name
            ?? 'Sistem';
    }
}
