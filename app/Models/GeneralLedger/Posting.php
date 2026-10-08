<?php

namespace App\Models\GeneralLedger;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One posting of a document; the active one is the one not superseded. */
class Posting extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'posted_at' => 'datetime', 'superseded_at' => 'datetime', 'revision' => 'integer'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('postings.superseded_at');
    }

    public function document(): MorphTo
    {
        return $this->morphTo();
    }

    public function journalEntry(): HasOne
    {
        return $this->hasOne(JournalEntry::class);
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'superseded_by');
    }

    public function isActive(): bool
    {
        return $this->superseded_at === null;
    }
}
