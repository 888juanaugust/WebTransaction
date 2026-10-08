<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment;

use App\Domain\Access\BranchLimit;
use App\Models\Inventory\Item;
use App\Models\Purchasing\GoodsReceipt;
use App\Models\Sales\Delivery;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * A line pulled from an upstream document points back at it through two hidden fields the browser sends. The
 * server checks them: the line it names is of a kind this document pulls from (the fulfilment chain), exists,
 * carries the same item in the same unit, and belongs to a document of the same customer or vendor in a branch the
 * user may use; on a receipt or delivery (which show no price), the price is the upstream line's. Runs after the
 * save, inside its transaction, so a refusal saves nothing.
 */
final class SourceLineGuard
{
    /** Documents whose price is not shown, so it can only be the upstream line's. */
    private const PRICE_FROM_SOURCE = [GoodsReceipt::class, Delivery::class];

    public function __construct(private readonly FulfilmentService $fulfilment) {}

    public function check(Model $document): void
    {
        if (! method_exists($document, 'lines') || ! self::hasSources($document->lines()->getRelated()->getTable())) {
            return;
        }
        $party = $document->getAttribute('customer_id') ?? $document->getAttribute('vendor_id');
        foreach ($document->lines()->whereNotNull('source_line_id')->get() as $line) {
            $class = Relation::getMorphedModel((string) $line->getAttribute('source_line_type'));
            $source = $class !== null && $this->fulfilment->pulls($class, $line::class) ? $class::query()->find($line->getAttribute('source_line_id')) : null;
            $upstream = $source !== null && method_exists($source, 'document') ? $source->document() : null;
            if ($upstream === null) {
                $this->refuse(__('A line points at a document line that does not exist.'));
            }
            // The upstream line's item, in its unit: a pulled line keeps its price, so it keeps what the price is for.
            $unit = fn (Model $l) => $l->getAttribute('unit_id') ?? Item::query()->whereKey($l->getAttribute('item_id'))->value('unit1_id');
            if ((int) $source->getAttribute('item_id') !== (int) $line->getAttribute('item_id') || (int) $unit($source) !== (int) $unit($line)) {
                $this->refuse(__('A line pulled from :number keeps its item and unit.', ['number' => $upstream->getAttribute('number')]));
            }
            $upstreamParty = $upstream->getAttribute('customer_id') ?? $upstream->getAttribute('vendor_id');
            if ($party !== null && $upstreamParty !== null && (int) $upstreamParty !== (int) $party) {
                $this->refuse(__(':number belongs to another customer or vendor.', ['number' => $upstream->getAttribute('number')]));
            }
            $branch = $upstream->getAttribute('branch_id');
            if (! BranchLimit::allows(auth()->user(), $branch !== null ? (int) $branch : null)) {
                $this->refuse(__('You are not assigned to that branch.'));
            }
            if (in_array($document::class, self::PRICE_FROM_SOURCE, true) && $source->getAttribute('unit_price') !== null
                && BigDecimal::of((string) $line->unit_price)->minus((string) $source->getAttribute('unit_price'))->abs()->isGreaterThan('0.5')) {
                $this->refuse(__('A line pulled from :number keeps its price.', ['number' => $upstream->getAttribute('number')]));
            }
        }
    }

    /** @var array<string, bool> line table → whether its lines point at upstream lines */
    private static array $tables = [];

    private static function hasSources(string $table): bool
    {
        return self::$tables[$table] ??= Schema::hasColumn($table, 'source_line_id');
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['data.lines' => $message]);
    }
}
