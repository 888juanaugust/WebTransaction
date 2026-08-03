<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'original_filename', 'stored_path', 'checksum', 'uploaded_by', 'status',
    'is_full_replacement', 'effective_from', 'note',
])]
class PriceListImport extends Model
{
    use HasFactory;

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_PARSING = 'parsing';

    public const STATUS_PARSED = 'parsed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_DISCARDED = 'discarded';

    /**
     * Mirrors the column defaults, so a freshly created import reports
     * is_full_replacement as false rather than null before it is reloaded —
     * this flag decides whether missing SKUs get deactivated, and null is not
     * an answer to that question.
     */
    protected $attributes = [
        'status' => self::STATUS_UPLOADED,
        'is_full_replacement' => false,
        'row_count' => 0,
        'blocker_count' => 0,
        'note_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_full_replacement' => 'boolean',
            'effective_from' => 'date',
            'diff' => 'array',
            'approved_at' => 'datetime',
            'row_count' => 'integer',
            'blocker_count' => 'integer',
            'note_count' => 'integer',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(PriceListImportRow::class, 'import_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PriceListVersion::class, 'price_list_version_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
