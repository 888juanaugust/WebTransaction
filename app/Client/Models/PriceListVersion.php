<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** One published price list. Never edited after publishing; the next list is the next version. */
class PriceListVersion extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const SUPERSEDED = 'superseded';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'published_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class, 'version_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** Published or superseded: a version that has been in force at some time. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereIn('status', [self::PUBLISHED, self::SUPERSEDED])->whereNotNull('published_at');
    }

    /** The version in force on a date: the latest effective on or before it, the highest id on a tie. */
    public static function effectiveOn(DateTimeInterface|string|null $date = null): ?self
    {
        $date = Carbon::parse($date ?? today())->toDateString();

        return static::query()->published()->whereDate('effective_from', '<=', $date)->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    /** The version in force today. */
    public static function current(): ?self
    {
        return static::effectiveOn();
    }
}
