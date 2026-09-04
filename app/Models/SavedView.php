<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A named arrangement of filters, sort and columns over one dataset. */
#[Fillable(['user_id', 'nama', 'dataset', 'filters', 'urutan', 'pencarian', 'dibagikan'])]
class SavedView extends Model
{
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'dibagikan' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Mine, plus anything a colleague chose to share. */
    public function scopeReadableBy(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('user_id', $userId)
            ->orWhere('dibagikan', true));
    }

    public function milik(int $userId): bool
    {
        return (int) $this->user_id === $userId;
    }
}
