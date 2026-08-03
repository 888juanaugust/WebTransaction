<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['effective_from', 'source_file_path', 'note', 'status'])]
class PriceListVersion extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_SUPERSEDED = 'superseded';

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class, 'version_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PUBLISHED, self::STATUS_SUPERSEDED])
            ->whereNotNull('published_at');
    }

    /**
     * The version in force on a given date: the most recent published version
     * whose effective_from has arrived.
     */
    public static function effectiveOn(\DateTimeInterface $date): ?self
    {
        return static::query()
            ->published()
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }
}
