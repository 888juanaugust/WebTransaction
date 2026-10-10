<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * An image the home page shows: a promo (a slide of the carousel, with a
 * title, a text and a link) or a photo (the gallery: the goods, the
 * warehouse, anything the Owner wants seen). On the public disk; shown
 * while active and within its dates.
 */
class SiteImage extends Model implements HasAuditReference
{
    use RecordsActivity;

    public const PROMO = 'promo';

    public const PHOTO = 'photo';

    protected $table = 'site_images';

    protected $fillable = ['kind', 'title', 'text', 'image_path', 'link', 'is_active', 'show_from', 'show_until', 'sort'];

    protected function casts(): array
    {
        return ['title' => 'array', 'text' => 'array', 'is_active' => 'boolean', 'show_from' => 'date', 'show_until' => 'date', 'sort' => 'integer'];
    }

    /** Active and within its dates today, in order. */
    public function scopeLive(Builder $query, ?string $kind = null): Builder
    {
        $today = today()->toDateString();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('show_from')->orWhere('show_from', '<=', $today))
            ->where(fn (Builder $q) => $q->whereNull('show_until')->orWhere('show_until', '>=', $today))
            ->when($kind !== null, fn (Builder $q) => $q->where('kind', $kind))
            ->orderBy('sort')->orderBy('id');
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->image_path);
    }

    /** A path on this site or an http(s) address: nothing a browser would run. */
    public static function isSafeLink(string $link): bool
    {
        $link = trim($link);
        if ($link === '') {
            return true;
        }
        if (str_starts_with($link, '/') && ! str_starts_with($link, '//') && ! str_starts_with($link, '/\\')) {
            return true;
        }

        return preg_match('~^https?://[^\s]+$~i', $link) === 1;
    }

    /** The slide's href, or null when the stored link is not safe to follow. */
    public function safeLink(): ?string
    {
        $link = trim((string) $this->link);
        if ($link === '' || ! self::isSafeLink($link)) {
            return null;
        }

        return str_starts_with($link, '/') ? url($link) : $link;
    }

    public function auditReference(): string
    {
        return (string) ($this->title['id'] ?? $this->title['en'] ?? $this->image_path);
    }
}
