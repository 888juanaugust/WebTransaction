<?php

declare(strict_types=1);

namespace App\Domain\Posting;

use App\Models\GeneralLedger\DocumentRevision;
use App\Models\GeneralLedger\Posting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * The common part of every Postable model: identity from the morph class and
 * key, date from trans_date, lines from the lines() relation. Models still
 * declare buildPostings().
 */
trait PostsToLedger
{
    public function postingKey(): string
    {
        return $this->getMorphClass().':'.$this->getKey();
    }

    public function postingDate(): CarbonInterface
    {
        return CarbonImmutable::parse($this->getAttribute('trans_date'));
    }

    public function postingBranchId(): ?int
    {
        return $this->getAttribute('branch_id');
    }

    public function postingNumber(): string
    {
        return (string) $this->getAttribute('number');
    }

    public function postingDescription(): ?string
    {
        return $this->getAttribute('description');
    }

    public function snapshot(): array
    {
        $header = collect($this->getAttributes())->except(['updated_at', 'created_at'])->all();
        $lines = method_exists($this, 'lines') ? $this->lines()->get()->map(fn ($l) => $l->getAttributes())->all() : [];

        return ['header' => $header, 'lines' => $lines];
    }

    public function posting(): MorphOne
    {
        return $this->morphOne(Posting::class, 'document')->whereNull('superseded_at');
    }

    public function postings(): MorphMany
    {
        return $this->morphMany(Posting::class, 'document');
    }

    public function revisions(): MorphMany
    {
        return $this->morphMany(DocumentRevision::class, 'document')->orderBy('revision');
    }
}
