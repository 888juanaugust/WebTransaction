<?php

declare(strict_types=1);

namespace App\Client\Domain\Ops\Integrity;

use App\Domain\Documents\PaymentStatus;
use App\Domain\Inventory\Costing\CostEngine;
use App\Domain\Pengaturan\BusinessRule;
use App\Domain\Settlement\SettlementService;
use App\Models\Company\OpeningBalance;
use App\Models\Company\PayrollEntry;
use App\Models\GeneralLedger\ExpenseAccrual;
use App\Models\Inventory\ItemCost;
use App\Models\Inventory\StockMovement;
use App\Models\Purchasing\PurchaseDownPayment;
use App\Models\Purchasing\PurchaseInvoice;
use App\Models\Purchasing\PurchaseReturn;
use App\Models\Sales\SalesDownPayment;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesReturn;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Reads the ledgers against the caches derived from them and names every
 * disagreement. Every cached column must be reconstructible from the
 * ledgers (CLAUDE.md, invariant 1); this is the sweep that proves it,
 * nightly and on demand. It repairs nothing: a drift is a bug to find,
 * not a number to overwrite.
 */
class LedgerIntegrity
{
    public const JOURNAL = 'journal';

    public const STOCK = 'stock';

    public const SETTLEMENT = 'settlement';

    public const RESERVATIONS = 'reservations';

    /** @var list<class-string<Model>> documents with a paid_amount cache */
    public const SETTLED = [SalesInvoice::class, SalesReturn::class, SalesDownPayment::class, PurchaseInvoice::class, PurchaseReturn::class, PurchaseDownPayment::class, ExpenseAccrual::class, PayrollEntry::class, OpeningBalance::class];

    public function __construct(private readonly CostEngine $costs, private readonly SettlementService $settlement) {}

    /** @return list<IntegrityFinding> */
    public function findings(): array
    {
        return [...$this->journal(), ...$this->stock(), ...$this->settlement(), ...$this->reservations()];
    }

    public function isClean(): bool
    {
        return $this->findings() === [];
    }

    /** Every active journal entry balances, and so does the trial balance of the active lines. @return list<IntegrityFinding> */
    private function journal(): array
    {
        $findings = [];
        $unbalanced = DB::table('journal_lines')
            ->join('postings', 'postings.id', '=', 'journal_lines.posting_id')->whereNull('postings.superseded_at')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->groupBy('journal_entries.id', 'journal_entries.number')
            ->havingRaw('SUM(journal_lines.debit) <> SUM(journal_lines.credit)')
            ->orderBy('journal_entries.id')
            ->get(['journal_entries.number', DB::raw('SUM(journal_lines.debit) AS debit'), DB::raw('SUM(journal_lines.credit) AS credit')]);
        foreach ($unbalanced as $entry) {
            $findings[] = new IntegrityFinding(self::JOURNAL, (string) $entry->number, "debit {$entry->debit}, credit {$entry->credit}");
        }
        $totals = DB::table('journal_lines')->join('postings', 'postings.id', '=', 'journal_lines.posting_id')->whereNull('postings.superseded_at')
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) AS debit, COALESCE(SUM(journal_lines.credit), 0) AS credit')->first();
        if ((int) $totals->debit !== (int) $totals->credit) {
            $findings[] = new IntegrityFinding(self::JOURNAL, 'trial balance', "debit {$totals->debit}, credit {$totals->credit}");
        }

        return $findings;
    }

    /** Every item_costs row equals a replay of the active movements, and every (item, warehouse) with movements has a row. @return list<IntegrityFinding> */
    private function stock(): array
    {
        $findings = [];
        $pairs = StockMovement::query()->active()->select('item_id', 'warehouse_id')->distinct()->get()
            ->map(fn (StockMovement $m) => [(int) $m->item_id, (int) $m->warehouse_id]);
        $cached = ItemCost::query()->get()->keyBy(fn (ItemCost $c) => "{$c->item_id}:{$c->warehouse_id}");
        $seen = [];
        foreach ($pairs as [$itemId, $warehouseId]) {
            $key = "{$itemId}:{$warehouseId}";
            $seen[$key] = true;
            $replay = $this->costs->replay($itemId, $warehouseId);
            $row = $cached->get($key);
            if ($row === null) {
                $findings[] = new IntegrityFinding(self::STOCK, "item {$itemId} in warehouse {$warehouseId}", "movements sum to {$replay['qty']} but there is no item_costs row");

                continue;
            }
            $qty = BigDecimal::of((string) $row->qty_on_hand)->toScale(4);
            if (! $qty->isEqualTo(BigDecimal::of($replay['qty'])) || (int) $row->total_value !== (int) $replay['value']) {
                $findings[] = new IntegrityFinding(self::STOCK, "item {$itemId} in warehouse {$warehouseId}", "cache {$qty} worth {$row->total_value}, movements {$replay['qty']} worth {$replay['value']}");
            }
        }
        foreach ($cached as $key => $row) {
            if (! isset($seen[$key]) && (BigDecimal::of((string) $row->qty_on_hand)->isPositive() || (int) $row->total_value !== 0)) {
                $findings[] = new IntegrityFinding(self::STOCK, "item {$row->item_id} in warehouse {$row->warehouse_id}", "cache {$row->qty_on_hand} worth {$row->total_value} with no movements behind it");
            }
        }

        return $findings;
    }

    /** Every cached paid_amount and payment_status equals the active allocations. @return list<IntegrityFinding> */
    private function settlement(): array
    {
        $findings = [];
        foreach (self::SETTLED as $class) {
            $class::query()->orderBy('id')->chunk(200, function ($documents) use (&$findings): void {
                foreach ($documents as $document) {
                    if (($document->getAttribute('approval_status') ?? 'approved') !== 'approved') {
                        continue;
                    }
                    $paid = $this->settlement->paidAmount($document);
                    if (method_exists($document, 'isCredit') && $document->isCredit()) {
                        $paid = -$paid;
                    }
                    $total = (int) ($document->getAttribute('total') ?? $document->getAttribute('amount') ?? 0) - (int) ($document->getAttribute('down_payment_total') ?? 0);
                    $expected = PaymentStatus::derive($total, $paid);
                    $cachedPaid = (int) $document->getAttribute('paid_amount');
                    $cachedStatus = (string) $document->getAttribute('payment_status');
                    if ($cachedPaid !== $paid || ($cachedStatus !== $expected && ! $this->foreign($document))) {
                        $findings[] = new IntegrityFinding(self::SETTLEMENT, $document->getMorphClass().' '.($document->getAttribute('number') ?? $document->getKey()), "cache paid {$cachedPaid} ({$cachedStatus}), allocations {$paid} ({$expected})");
                    }
                }
            });
        }

        return $findings;
    }

    /** No hold below zero, no reversal applied twice, and nothing held beyond what is on hand unless negative stock is allowed. @return list<IntegrityFinding> */
    private function reservations(): array
    {
        $findings = [];
        $negative = DB::table('stock_reservations')->select('sales_order_id', 'sales_order_line_id', 'item_id', 'warehouse_id', DB::raw('SUM(quantity) AS held'))
            ->groupBy('sales_order_id', 'sales_order_line_id', 'item_id', 'warehouse_id')->havingRaw('SUM(quantity) < 0')->get();
        foreach ($negative as $row) {
            $findings[] = new IntegrityFinding(self::RESERVATIONS, "order {$row->sales_order_id} line {$row->sales_order_line_id} item {$row->item_id} warehouse {$row->warehouse_id}", "holds {$row->held}");
        }
        $twice = DB::table('stock_reservations')->whereNotNull('reverses_id')->select('reverses_id', DB::raw('COUNT(*) AS n'))->groupBy('reverses_id')->havingRaw('COUNT(*) > 1')->get();
        foreach ($twice as $row) {
            $findings[] = new IntegrityFinding(self::RESERVATIONS, "row {$row->reverses_id}", "reversed {$row->n} times");
        }
        if (! BusinessRule::AllowNegativeStock->isOn()) {
            $held = DB::table('stock_reservations')->select('item_id', 'warehouse_id', DB::raw('SUM(quantity) AS held'))->groupBy('item_id', 'warehouse_id')->havingRaw('SUM(quantity) > 0')->get();
            foreach ($held as $row) {
                $onHand = (string) (ItemCost::query()->where('item_id', $row->item_id)->where('warehouse_id', $row->warehouse_id)->value('qty_on_hand') ?? '0');
                if (BigDecimal::of($onHand)->isLessThan(BigDecimal::of((string) $row->held))) {
                    $findings[] = new IntegrityFinding(self::RESERVATIONS, "item {$row->item_id} in warehouse {$row->warehouse_id}", "{$row->held} held, {$onHand} on hand");
                }
            }
        }

        return $findings;
    }

    /** A foreign-currency document's status is derived from its foreign amounts; the base paid cache is still compared. */
    private function foreign(Model $document): bool
    {
        return $this->settlement->isForeign($document);
    }
}
