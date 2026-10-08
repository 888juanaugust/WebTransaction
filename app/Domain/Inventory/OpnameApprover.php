<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\ApprovalType;
use App\Domain\Audit\Auditor;
use App\Domain\Numbering\TransactionType;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Shared\Format;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\InventoryAdjustmentLine;
use App\Models\Inventory\ItemCost;
use App\Models\Inventory\StockOpnameResult;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A stock opname result always waits for approval, on the shared approval
 * engine: under an approval rule for stock counts when one covers it, else
 * for anyone with the "approve transactions" right; never the person who
 * counted while segregation of duties is on. The approval that completes it
 * posts the differences between the count and the system as one inventory
 * adjustment in the order's warehouse.
 */
final class OpnameApprover
{
    public function __construct(private readonly DocumentRepository $documents, private readonly ApprovalEngine $approvals) {}

    /** How the engine treats stock counts; registered by the inventory module. */
    public static function type(): ApprovalType
    {
        return new ApprovalType(
            model: StockOpnameResult::class,
            transactionType: TransactionType::StockOpnameResult,
            requiredWithoutRule: true,
            settledBefore: fn (StockOpnameResult $result): bool => $result->isApproved(),
        );
    }

    public function canApprove(StockOpnameResult $result, ?User $user): bool
    {
        return ! $result->isApproved() && $this->approvals->canApprove($result, $user);
    }

    /** Records the approval; the adjustment when it completes the count's approval, else null. */
    public function approve(StockOpnameResult $result, User $approver): ?InventoryAdjustment
    {
        if ($result->isApproved()) {
            throw new RuntimeException(__(':number is already approved.', ['number' => $result->number]));
        }

        // One transaction: if the variance cannot post (a closed month, stock going negative, no back-date right),
        // the approval is not recorded either, and the count can be approved again once that is put right.
        return DB::transaction(function () use ($result, $approver): ?InventoryAdjustment {
            if (! $this->approvals->approve($result, $approver)) {
                return null;
            }
            $result->load(['order', 'lines.item.units']);
            $warehouse = $result->order->warehouse_id;
            $lines = [];
            $sort = 0;

            foreach ($result->lines as $line) {
                $diff = BigDecimal::of((string) $line->base_quantity)->minus((string) $line->system_qty);
                if ($diff->isZero()) {
                    continue;
                }
                $lines[] = [
                    'sort' => $sort++,
                    'item_id' => $line->item_id,
                    'adjustment_type' => InventoryAdjustmentLine::QUANTITY,
                    'quantity' => (string) $diff,
                    'unit_id' => $line->item->unit1_id,
                    'base_quantity' => (string) $diff,
                    'unit_cost' => $diff->isPositive() ? (string) (ItemCost::query()->where('item_id', $line->item_id)->where('warehouse_id', $warehouse)->value('avg_cost') ?? $line->item->purchase_price) : '0',
                    'total_cost' => 0,
                    'warehouse_id' => $warehouse,
                    'memo' => "Stock count {$result->number}: counted ".Format::quantity((string) $line->base_quantity).', system '.Format::quantity((string) $line->system_qty),
                ];
            }

            $adjustment = null;
            if ($lines !== []) {
                $adjustment = InventoryAdjustment::query()->create([
                    'number' => 'OPN-'.$result->number,
                    'trans_date' => $result->trans_date,
                    'description' => "Stock count variance, {$result->number}",
                    'created_by' => $approver->id,
                ]);
                $adjustment->lines()->createMany($lines);
                $this->documents->created($adjustment);
            }

            $result->forceFill([
                'status' => StockOpnameResult::APPROVED,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'inventory_adjustment_id' => $adjustment?->id,
            ])->saveQuietly();
            $result->order->forceFill(['status' => 'counted'])->saveQuietly();
            Auditor::log('approved', $result, $result->number, ['adjustment' => $adjustment?->number]);

            return $adjustment;
        });
    }

    /** Fills system_qty on every line from the stock cache of the order's warehouse. */
    public function snapshotSystemQuantities(StockOpnameResult $result): void
    {
        $warehouse = $result->order->warehouse_id;
        foreach ($result->lines as $line) {
            $qty = ItemCost::query()->where('item_id', $line->item_id)->where('warehouse_id', $warehouse)->value('qty_on_hand') ?? 0;
            $line->forceFill(['system_qty' => (string) $qty])->saveQuietly();
        }
    }
}
