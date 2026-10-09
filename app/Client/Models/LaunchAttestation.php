<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Domain\Audit\HasAuditReference;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person's word that a readiness item is done: who, when, and the evidence they named. */
class LaunchAttestation extends Model implements HasAuditReference
{
    protected $table = 'launch_attestations';

    protected $fillable = ['key', 'attested_by', 'attested_at', 'note'];

    protected function casts(): array
    {
        return ['attested_at' => 'datetime'];
    }

    public function attestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attested_by');
    }

    public function auditReference(): string
    {
        return (string) $this->key;
    }
}
