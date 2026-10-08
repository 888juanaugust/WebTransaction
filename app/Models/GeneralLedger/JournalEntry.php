<?php

namespace App\Models\GeneralLedger;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A journal entry: the lines one posting wrote. Append-only. */
class JournalEntry extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'created_at' => 'datetime'];
    }

    /** Entries whose posting is still active. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereHas('posting', fn (Builder $q) => $q->whereNull('superseded_at'));
    }

    public function posting(): BelongsTo
    {
        return $this->belongsTo(Posting::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('sort');
    }

    public function total(): int
    {
        return (int) $this->lines()->sum('debit');
    }
}
