<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Raw gateway callbacks. The (gateway, event_id) UNIQUE constraint is the
 * idempotency mechanism — a redelivery collides and is never processed twice.
 */
#[Fillable([
    'gateway', 'event_id', 'event_type', 'payload',
    'signature_verified', 'received_at',
])]
class WebhookEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_verified' => 'boolean',
            'received_at' => 'datetime',
            'claimed_at' => 'datetime',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    /** Picked up by a worker that has not committed anything yet. */
    public function isClaimed(): bool
    {
        return $this->claimed_at !== null && $this->processed_at === null;
    }
}
