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

    public function auditReference(): string
    {
        return (string) ($this->title['id'] ?? $this->title['en'] ?? $this->image_path);
    }
}
