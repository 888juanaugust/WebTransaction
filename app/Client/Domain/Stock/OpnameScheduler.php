<?php

declare(strict_types=1);

namespace App\Client\Domain\Stock;

use App\Client\Domain\SystemActor;
use App\Client\Domain\Warehouse\WarehouseBinder;
use App\Domain\Inventory\OpnameApprover;
use App\Domain\Numbering\NumberGenerator;
use App\Domain\Numbering\TransactionType;
use App\Models\Company\Branch;
use App\Models\Inventory\ItemCost;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockOpnameOrder;
use App\Models\Inventory\StockOpnameResult;
use App\Models\Inventory\Warehouse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Count sheets on a cadence. A daily sheet asks the gudang to count the
 * SKUs that went out of its warehouse that day; a semester sheet asks for
 * every SKU the warehouse has had. Each sheet is a Stock Opname Order and
 * its Stock Opname Result (the base's documents), written in the System
 * user's name with the system quantity snapshotted as the base's Pull
 * does; the gudang fills the counts, Purchasing approves and the variance
 * posts as the base posts it. One sheet per warehouse, kind and day.
 */
final class OpnameScheduler
{
    public const DAILY = 'daily';

    public const SEMESTER = 'semester';

    public function __construct(private readonly NumberGenerator $numbers, private readonly WarehouseBinder $binder, private readonly OpnameApprover $approver) {}

    /** The warehouses that get sheets: active, not the system's. @return \Illuminate\Database\Eloquent\Collection<int, Warehouse> */
    public function warehouses(): Collection
    {
        return Warehouse::query()->with('branch')->where('is_system', false)->where('is_active', true)->orderBy('id')->get();
    }

    /** The day's sheet for a warehouse: the SKUs that went OUT that day; null when nothing moved. */
    public function daily(Warehouse $warehouse, \DateTimeInterface|string $day): ?StockOpnameResult
    {
        $day = self::day($day);
        if ($existing = $this->existing($warehouse, self::DAILY, $day)) {
            return $existing;
        }
        $items = StockMovement::query()->active()->where('warehouse_id', $warehouse->id)->where('direction', StockMovement::OUT)
            ->whereDate('trans_date', $day->toDateString())->where('base_quantity', '!=', 0)
            ->distinct()->orderBy('item_id')->limit((int) config('stock.daily_sheet_max', 200))->pluck('item_id')->all();
        if ($items === []) {
            return null;
        }

        return $this->sheet($warehouse, self::DAILY, $day, $items, __('Daily count: what went out on :date', ['date' => $day->toDateString()]));
    }

    /** The semester's full sheet: every SKU the warehouse has ever held. */
    public function semester(Warehouse $warehouse, \DateTimeInterface|string $day): ?StockOpnameResult
    {
        $day = self::day($day);
        if ($existing = $this->existing($warehouse, self::SEMESTER, $day)) {
            return $existing;
        }
        $items = ItemCost::query()->where('warehouse_id', $warehouse->id)->orderBy('item_id')->pluck('item_id')->all();
        if ($items === []) {
            return null;
        }

        return $this->sheet($warehouse, self::SEMESTER, $day, $items, __('Semester count: every item, :date', ['date' => $day->toDateString()]));
    }

    /** Sheets still to count (draft results of scheduled orders), oldest first. */
    public function open(): Builder
    {
        return StockOpnameResult::query()->with(['order.warehouse'])
            ->where('status', StockOpnameResult::DRAFT)
            ->whereHas('order', fn (Builder $q) => $q->whereIn('kind', [self::DAILY, self::SEMESTER]));
    }

    /** The gudang's count: counted quantities per line, in base units; marks the sheet counted. */
    public function count(StockOpnameResult $result, array $counts, User $actor): void
    {
        if ($result->isApproved()) {
            throw new RuntimeException(__(':number is already approved.', ['number' => $result->number]));
        }
        DB::transaction(function () use ($result, $counts, $actor): void {
            foreach ($result->lines as $line) {
                if (! array_key_exists($line->id, $counts)) {
                    continue;
                }
                $qty = (string) $counts[$line->id];
                $line->forceFill(['counted_qty' => $qty, 'base_quantity' => $qty])->saveQuietly();
            }
            $result->forceFill(['counted_at' => now(), 'counted_by' => $actor->id])->saveQuietly();
        });
    }

    private function existing(Warehouse $warehouse, string $kind, CarbonImmutable $day): ?StockOpnameResult
    {
        $order = StockOpnameOrder::query()->where('warehouse_id', $warehouse->id)->where('kind', $kind)->whereDate('start_date', $day->toDateString())->first();

        return $order?->results()->orderBy('id')->first();
    }

    /** @param  list<int>  $items */
    private function sheet(Warehouse $warehouse, string $kind, CarbonImmutable $day, array $items, string $description): StockOpnameResult
    {
        $system = SystemActor::user();
        $holder = $this->binder->holder($warehouse);

        return DB::transaction(function () use ($warehouse, $kind, $day, $items, $description, $system, $holder): StockOpnameResult {
            $orderSeries = $this->numbers->defaultSeries(TransactionType::StockOpnameOrder, $system) ?? throw new RuntimeException(__('No numbering series for stock opname orders.'));
            $resultSeries = $this->numbers->defaultSeries(TransactionType::StockOpnameResult, $system) ?? throw new RuntimeException(__('No numbering series for stock opname results.'));
            $branch = $warehouse->branch_id ? Branch::query()->find($warehouse->branch_id)?->code : null;
            $order = StockOpnameOrder::query()->create([
                'number' => $this->numbers->next($orderSeries, $day, $branch), 'series_id' => $orderSeries->id,
                'trans_date' => $day->toDateString(), 'start_date' => $day->toDateString(),
                'person_charged' => $holder?->name ?? __('Warehouse'), 'description' => $description,
                'warehouse_id' => $warehouse->id, 'branch_id' => $warehouse->branch_id, 'status' => 'open', 'kind' => $kind, 'created_by' => $system->id,
            ]);
            $order->items()->attach($items);
            if ($holder !== null) {
                $order->users()->attach($holder->id);
            }
            $result = StockOpnameResult::query()->create([
                'number' => $this->numbers->next($resultSeries, $day, $branch), 'series_id' => $resultSeries->id,
                'trans_date' => $day->toDateString(), 'stock_opname_order_id' => $order->id, 'description' => $description,
                'status' => StockOpnameResult::DRAFT, 'created_by' => $system->id,
            ]);
            $sort = 0;
            foreach ($order->itemsToCount()->whereIn('items.id', $items)->get() as $item) {
                $qty = (string) (ItemCost::query()->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->value('qty_on_hand') ?? '0');
                $result->lines()->create(['sort' => $sort++, 'item_id' => $item->id, 'counted_qty' => $qty, 'unit_id' => $item->unit1_id, 'base_quantity' => $qty, 'system_qty' => $qty]);
            }

            return $result->fresh(['order', 'lines']);
        });
    }

    private static function day(\DateTimeInterface|string $day): CarbonImmutable
    {
        return CarbonImmutable::parse($day instanceof \DateTimeInterface ? $day->format('Y-m-d') : (string) $day)->startOfDay();
    }
}
