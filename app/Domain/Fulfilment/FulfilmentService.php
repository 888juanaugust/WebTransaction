<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Keeps processed_quantity on upstream lines equal to the sum of the
 * downstream lines that pull from them (a receipt from an order, an invoice
 * from a receipt), and derives the upstream document's status from it. The
 * registry says which line classes can pull from which.
 */
final class FulfilmentService
{
    /** @var array<class-string, list<class-string>> upstream line class → downstream line classes */
    private array $downstream = [];

    public function register(string $upstreamLineClass, string $downstreamLineClass): void
    {
        $this->downstream[$upstreamLineClass][] = $downstreamLineClass;
    }

    /** Whether a line of the second class may pull from a line of the first (an invoice line from a delivery line). */
    public function pulls(string $upstreamLineClass, string $downstreamLineClass): bool
    {
        return in_array($downstreamLineClass, $this->downstream[$upstreamLineClass] ?? [], true);
    }

    /** After a downstream document was saved or deleted: refresh every upstream line and document it touched. */
    public function refreshUpstream(Model $document, array $previousSources = []): void
    {
        $sources = $previousSources;
        if ($document->exists && method_exists($document, 'lines')) {
            foreach ($document->lines()->get() as $line) {
                if ($line->source_line_type && $line->source_line_id) {
                    $sources["{$line->source_line_type}:{$line->source_line_id}"] = [$line->source_line_type, (int) $line->source_line_id];
                }
            }
        }

        $documents = [];
        foreach ($sources as [$type, $id]) {
            $class = Relation::getMorphedModel((string) $type); // a mapped alias only, never a class name from a form
            $line = $class !== null ? $class::query()->find($id) : null;
            if ($line === null) {
                continue;
            }
            $line->forceFill(['processed_quantity' => $this->processedQuantity($line)])->saveQuietly();
            $parent = $line->document();
            $documents[$parent::class.':'.$parent->getKey()] = $parent;
        }

        foreach ($documents as $parent) {
            if (method_exists($parent, 'refreshStatus')) {
                $parent->refreshStatus();
            }
        }
    }

    /** The sources a document's lines point at, to refresh after they are gone. */
    public function sourcesOf(Model $document): array
    {
        $sources = [];
        if (method_exists($document, 'lines')) {
            foreach ($document->lines()->get() as $line) {
                if ($line->source_line_type && $line->source_line_id) {
                    $sources["{$line->source_line_type}:{$line->source_line_id}"] = [$line->source_line_type, (int) $line->source_line_id];
                }
            }
        }

        return $sources;
    }

    public function processedQuantity(Model $upstreamLine): string
    {
        $total = BigDecimal::zero();
        foreach ($this->downstream[$upstreamLine::class] ?? [] as $class) {
            $sum = $class::query()
                ->where('source_line_type', $upstreamLine->getMorphClass())
                ->where('source_line_id', $upstreamLine->getKey())
                ->sum('base_quantity');
            $total = $total->plus((string) ($sum ?: 0));
        }

        return (string) $total->toScale(4);
    }
}
