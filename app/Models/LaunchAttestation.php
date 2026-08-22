<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody's word that a thing outside this system was done.
 */
#[Fillable(['kunci', 'attested_by', 'attested_at', 'catatan'])]
class LaunchAttestation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['attested_at' => 'datetime'];
    }

    public function attestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attested_by');
    }
}
