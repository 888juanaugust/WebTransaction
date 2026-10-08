<?php

declare(strict_types=1);

namespace App\Domain\Posting;

use App\Models\GeneralLedger\DocumentRevision;
use Illuminate\Database\Eloquent\Model;

/** Writes the before/after record of every document change. Append-only. */
final class Revisions
{
    public function record(Model $document, string $action, ?array $before, ?array $after): DocumentRevision
    {
        $last = (int) DocumentRevision::query()
            ->where('document_type', $document->getMorphClass())
            ->where('document_id', $document->getKey())
            ->max('revision');

        return DocumentRevision::query()->create([
            'document_type' => $document->getMorphClass(),
            'document_id' => $document->getKey(),
            'revision' => $last + 1,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'user_id' => auth()->id(),
        ]);
    }
}
