<?php

declare(strict_types=1);

namespace App\Client\Domain\Stock;

use App\Domain\Numbering\NumberGenerator;
use App\Domain\Numbering\TransactionType;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Shared\Enums\AccountType;
use App\Models\Company\Branch;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\InventoryAdjustmentLine;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemCost;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\SalesReturnLine;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Damaged goods: the warehouses flagged for them (one per cabang, "Gudang
 * Rusak"), what sits there and how old it is, and the write-off that takes
 * it off the books through the base's inventory adjustment. Damaged stock
 * never counts as available to the portal, the reservations or the order
 * splitter; the base's own screens keep counting it as stock.
 */
final class DamagedGoods
{
    public const GOOD = 'good';

    public const DAMAGED = 'damaged';

    public function __construct(private readonly NumberGenerator $numbers, private readonly DocumentRepository $documents, private readonly StockAge $age) {}

    /** @return array<string, string> */
    public static function conditionLabels(): array
    {
        return [self::GOOD => __('Good'), self::DAMAGED => __('Damaged')];
    }

    /** The damaged-goods warehouse of a branch, else the company's one, else none. */
    public static function warehouseFor(?int $branchId): ?Warehouse
    {
        $query = Warehouse::query()->where('scrap_warehouse', true)->where('is_active', true)->orderBy('id');

        return ($branchId !== null ? (clone $query)->where('branch_id', $branchId)->first() : null) ?? $query->first();
    }

    /** The warehouses whose stock is for sale: active, not the system's, not damaged goods. */
    public static function saleable(Builder $warehouses): Builder
    {
        return $warehouses->where('is_system', false)->where('is_active', true)->where('scrap_warehouse', false);
    }

    /** What sits in the damaged-goods warehouses: one row per item and warehouse, with its age and the return it last came from. */
    public function rows(?int $warehouseId = null): Collection
    {
        $warehouses = Warehouse::query()->where('scrap_warehouse', true)->when($warehouseId, fn ($q, $id) => $q->whereKey($id))->pluck('name', 'id');
        $rows = collect();
        foreach (ItemCost::query()->with(['item.unit1'])->whereIn('warehouse_id', $warehouses->keys())->where('qty_on_hand', '>', 0)->get() as $cost) {
            $layers = $this->age->layers($cost->item_id, $cost->warehouse_id);
            $lastIn = StockMovement::query()->active()->where('item_id', $cost->item_id)->where('warehouse_id', $cost->warehouse_id)->where('direction', StockMovement::IN)
                ->where('source_line_type', 'sales_return_line')->orderByDesc('trans_date')->orderByDesc('id')->first();
            $return = $lastIn ? SalesReturnLine::query()->with('salesReturn')->find($lastIn->source_line_id)?->salesReturn : null;
            $rows->push([
                'key' => $cost->item_id.'-'.$cost->warehouse_id, 'item_id' => $cost->item_id, 'warehouse_id' => $cost->warehouse_id,
                'number' => $cost->item?->number, 'name' => $cost->item?->name, 'unit' => $cost->item?->unit1?->name, 'warehouse' => $warehouses[$cost->warehouse_id] ?? '',
                'on_hand' => (string) BigDecimal::of((string) $cost->qty_on_hand)->toScale(4), 'oldest_days' => $layers[0]['days'] ?? 0, 'oldest_date' => $layers[0]['date'] ?? null,
                'value' => (int) BigDecimal::of((string) $cost->qty_on_hand)->multipliedBy((string) $cost->avg_cost)->toScale(0, RoundingMode::HalfUp)->toInt(),
                'return' => $return?->number, 'return_id' => $return?->id,
            ]);
        }

        return $rows->sortByDesc('oldest_days')->values();
    }

    /** The expense account a write-off goes to: the configured number, created in the chart when missing. */
    public static function lossAccount(): Account
    {
        $no = (string) config('claims.damaged_account', '6600');

        return Account::query()->firstOrCreate(['no' => $no], ['name' => 'Damaged Goods Loss', 'account_type' => AccountType::Expense, 'is_system' => false, 'is_active' => true, 'used_all_user' => true]);
    }

    /** Writes damaged stock off: a negative inventory adjustment on the loss account, posted through the posting layer. */
    public function writeOff(Item $item, Warehouse $warehouse, string $quantity, User $actor, ?string $memo = null, \DateTimeInterface|string|null $date = null, ?int $accountId = null): InventoryAdjustment
    {
        if (! $warehouse->scrap_warehouse) {
            throw new RuntimeException(__(':warehouse is not a damaged-goods warehouse.', ['warehouse' => $warehouse->name]));
        }
        $qty = BigDecimal::of($quantity);
        $onHand = BigDecimal::of((string) (ItemCost::query()->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->value('qty_on_hand') ?? '0'));
        if ($qty->isLessThanOrEqualTo(0) || $qty->isGreaterThan($onHand)) {
            throw new RuntimeException(__(':item: :warehouse holds :held, not :wanted.', ['item' => $item->name, 'warehouse' => $warehouse->name, 'held' => (string) $onHand->toScale(4), 'wanted' => (string) $qty->toScale(4)]));
        }
        $day = $date === null ? CarbonImmutable::today() : CarbonImmutable::parse($date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date);
        $account = $accountId ? Account::query()->findOrFail($accountId) : self::lossAccount();

        return DB::transaction(function () use ($item, $warehouse, $qty, $actor, $memo, $day, $account): InventoryAdjustment {
            $series = $this->numbers->defaultSeries(TransactionType::InventoryAdjustment, $actor) ?? throw new RuntimeException(__('No numbering series for inventory adjustments.'));
            $branch = $warehouse->branch_id ? Branch::query()->find($warehouse->branch_id)?->code : null;
            $adjustment = InventoryAdjustment::query()->create([
                'number' => $this->numbers->next($series, $day, $branch), 'series_id' => $series->id, 'trans_date' => $day->toDateString(),
                'description' => __('Damaged goods written off: :item', ['item' => $item->name]).($memo ? ' — '.$memo : ''),
                'branch_id' => $warehouse->branch_id, 'created_by' => $actor->id,
            ]);
            $adjustment->lines()->create([
                'sort' => 0, 'item_id' => $item->id, 'adjustment_type' => InventoryAdjustmentLine::QUANTITY,
                'quantity' => (string) $qty->negated(), 'unit_id' => $item->unit1_id, 'base_quantity' => (string) $qty->negated(),
                'unit_cost' => '0', 'total_cost' => 0, 'warehouse_id' => $warehouse->id, 'adjustment_account_id' => $account->id, 'memo' => $memo,
            ]);
            $this->documents->created($adjustment);

            return $adjustment->fresh();
        });
    }
}
