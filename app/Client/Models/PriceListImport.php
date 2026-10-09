<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Domain\Audit\HasAuditReference;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An uploaded price list: parsed to rows, diffed against the list in force, published as a version or discarded. */
class PriceListImport extends Model implements HasAuditReference
{
    public const UPLOADED = 'uploaded';

    public const PARSING = 'parsing';

    public const PARSED = 'parsed';

    public const FAILED = 'failed';

    public const PUBLISHED = 'published';

    public const DISCARDED = 'discarded';

    public const CANONICAL = 'canonical';

    public const SUPPLIER = 'supplier';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_full_replacement' => 'boolean', 'effective_from' => 'date', 'diff' => 'array', 'approved_at' => 'datetime',
            'row_count' => 'integer', 'blocker_count' => 'integer', 'note_count' => 'integer'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(PriceListImportRow::class, 'import_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PriceListVersion::class, 'version_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isCanonical(): bool
    {
        return $this->format === self::CANONICAL;
    }

    public function brakeTripped(): bool
    {
        return (bool) ($this->diff['brake_tripped'] ?? false);
    }

    public function auditReference(): string
    {
        return (string) $this->original_filename;
    }
}
