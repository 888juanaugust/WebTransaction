<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Inventory\Costing\CostEngine;
use App\Domain\Inventory\Costing\Recoster;
use App\Domain\Inventory\Exceptions\NegativeStockException;
use App\Domain\Pengaturan\BusinessRule;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Shared\Format;
use App\Models\GeneralLedger\Posting;
use App\Models\Inventory\Item;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\Warehouse;
use Brick\Math\BigDecimal;

/**
 * Writes the stock movements a posting declared, costs the issues that did
 * not bring their own cost, refuses negative stock unless the rule allows it,
 * refreshes the item_costs cache and asks the Recoster whether later
 * documents need re-posting. Registered on the PostingService.
 */
final class StockLedger
{
    public function __construct(private readonly CostEngine $engine, private readonly Recoster $recoster) {}

    public function write(Posting $posting, PostingBuilder $builder): void
    {
        $movements = $builder->stockMovements();
        if ($movements === []) {
            return;
        }

        $touched = [];
        foreach ($movements as $i => $m) {
            $direction = $m['direction'];
            $quantity = (string) BigDecimal::of((string) $m['base_quantity'])->toScale(4);

            if ($direction === StockMovement::OUT && ! array_key_exists('total_cost', $m)) {
                $cost = $this->engine->issueCost((int) $m['item_id'], (int) $m['warehouse_id'], $posting->trans_date, $quantity);
                $m['unit_cost'] = $cost['unit_cost'];
                $m['total_cost'] = $cost['total_cost'];
            }

            if ($direction === StockMovement::OUT) {
                $this->assertStockStaysPositive((int) $m['item_id'], (int) $m['warehouse_id'], $posting->trans_date->toDateString(), $quantity);
            }

            StockMovement::query()->create([
                'posting_id' => $posting->id,
                'sort' => $i,
                'item_id' => $m['item_id'],
                'warehouse_id' => $m['warehouse_id'],
                'trans_date' => $posting->trans_date->toDateString(),
                'direction' => $direction,
                'base_quantity' => $quantity,
                'unit_cost' => $m['unit_cost'] ?? 0,
                'total_cost' => (int) ($m['total_cost'] ?? 0),
                'source_line_type' => $m['source_line_type'] ?? null,
                'source_line_id' => $m['source_line_id'] ?? null,
                'branch_id' => $m['branch_id'] ?? $posting->branch_id,
            ]);
            $touched["{$m['item_id']}:{$m['warehouse_id']}"] = ['item' => (int) $m['item_id'], 'warehouse' => (int) $m['warehouse_id'], 'in' => ($touched["{$m['item_id']}:{$m['warehouse_id']}"]['in'] ?? false) || $direction === StockMovement::IN];
        }

        foreach ($touched as $pair) {
            $this->engine->refreshCache($pair['item'], $pair['warehouse']);
            $this->recoster->schedule($pair['item'], $pair['warehouse'], $posting->trans_date, $posting->id, $pair['in']);
        }
    }

    /** When a posting is superseded, the pairs it moved need their caches and later costs redone. */
    public function unwrite(Posting $posting): void
    {
        $pairs = StockMovement::query()->where('posting_id', $posting->id)
            ->get(['item_id', 'warehouse_id', 'direction'])
            ->groupBy(fn ($m) => "{$m->item_id}:{$m->warehouse_id}");

        foreach ($pairs as $movements) {
            $first = $movements->first();
            $this->engine->refreshCache((int) $first->item_id, (int) $first->warehouse_id);
            $this->recoster->schedule((int) $first->item_id, (int) $first->warehouse_id, $posting->trans_date, $posting->id, true);
        }
    }

    /**
     * With the new issue in place, the running quantity of the pair must never
     * drop below zero on its day or any later day, unless the rule allows it.
     */
    private function assertStockStaysPositive(int $itemId, int $warehouseId, string $date, string $quantity): void
    {
        if (BusinessRule::AllowNegativeStock->isOn()) {
            return;
        }

        $movements = StockMovement::query()->active()
            ->where('item_id', $itemId)->where('warehouse_id', $warehouseId)
            ->orderBy('trans_date')->orderByRaw("CASE WHEN direction = 'in' THEN 0 ELSE 1 END")->orderBy('id')
            ->get(['trans_date', 'direction', 'base_quantity'])
            ->map(fn ($m) => ['date' => $m->trans_date->toDateString(), 'in' => $m->direction === StockMovement::IN, 'qty' => (string) $m->base_quantity])
            ->push(['date' => $date, 'in' => false, 'qty' => $quantity])
            ->sortBy(fn ($m) => [$m['date'], $m['in'] ? 0 : 1]);

        $running = BigDecimal::zero();
        foreach ($movements as $m) {
            $running = $m['in'] ? $running->plus($m['qty']) : $running->minus($m['qty']);
            if ($running->isNegative()) {
                $item = Item::query()->find($itemId);
                $warehouse = Warehouse::query()->find($warehouseId);
                throw new NegativeStockException(sprintf(
                    'Not enough stock of %s in %s on %s: the quantity would fall to %s.',
                    $item?->name ?? $itemId,
                    $warehouse?->name ?? $warehouseId,
                    Format::date($m['date']),
                    Format::quantity((string) $running),
                ));
            }
        }
    }
}
