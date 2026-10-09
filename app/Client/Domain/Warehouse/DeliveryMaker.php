<?php

declare(strict_types=1);

namespace App\Client\Domain\Warehouse;

use App\Client\Domain\Stock\Reservations;
use App\Domain\Inventory\Units\UnitConverter;
use App\Domain\Numbering\NumberGenerator;
use App\Domain\Numbering\TransactionType;
use App\Domain\Posting\DocumentRepository;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\Delivery;
use App\Models\Sales\SalesOrder;
use App\Models\Sales\SalesOrderLine;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The warehouse's Deliver: a delivery pulled from an approved order for what
 * the warehouse holds for it, never more. The base posts it (stock out) and
 * the reservations ledger consumes the held rows; the delivery's print is
 * the surat jalan.
 */
final class DeliveryMaker
{
    public function __construct(
        private readonly Reservations $reservations,
        private readonly NumberGenerator $numbers,
        private readonly DocumentRepository $documents,
    ) {}

    /** What the warehouse holds for the order, by line. @return list<array{line: SalesOrderLine, quantity: string}> */
    public function held(SalesOrder $order, Warehouse $warehouse): array
    {
        $out = [];
        foreach ($this->reservations->heldByOrder($order) as $hold) {
            if ($hold['warehouse'] === (int) $warehouse->id && $hold['line'] !== null) {
                $out[] = ['line' => $hold['line'], 'quantity' => $hold['quantity']];
            }
        }

        return $out;
    }

    /**
     * @param  array<int, string|int|float>  $quantities  order line id → base quantity to deliver (every held line in full when empty)
     */
    public function make(SalesOrder $order, Warehouse $warehouse, array $quantities, User $actor, string|CarbonImmutable|null $date = null): Delivery
    {
        $held = $this->held($order, $warehouse);
        if ($held === []) {
            throw new RuntimeException(__(':warehouse holds nothing for :number.', ['warehouse' => $warehouse->name, 'number' => $order->number]));
        }
        $rows = [];
        foreach ($held as $i => ['line' => $line, 'quantity' => $holding]) {
            $wanted = array_key_exists($line->id, $quantities) ? BigDecimal::of((string) $quantities[$line->id]) : BigDecimal::of($holding);
            if ($wanted->isLessThanOrEqualTo(0)) {
                continue;
            }
            if ($wanted->isGreaterThan($holding)) {
                throw new RuntimeException(__(':item: :warehouse holds :held for this order, not :wanted.', ['item' => $line->item?->name, 'warehouse' => $warehouse->name, 'held' => $holding, 'wanted' => $wanted->__toString()]));
            }
            $item = $line->item->load('units');
            $unit = (int) ($line->unit_id ?? $item->unit1_id);
            $rows[] = [
                'sort' => $i, 'item_id' => $line->item_id, 'unit_id' => $unit,
                'quantity' => UnitConverter::fromBase($item, $wanted->__toString(), $unit), 'base_quantity' => $wanted->toScale(4)->__toString(),
                'unit_price' => $line->unit_price, 'discount_percent' => $line->discount_percent, 'tax_code_id' => $line->tax_code_id,
                'warehouse_id' => $warehouse->id, 'memo' => $line->memo, 'salesman_id' => $line->salesman_id ?? null,
                'source_line_type' => 'sales_order_line', 'source_line_id' => $line->id,
            ];
        }
        if ($rows === []) {
            throw new RuntimeException(__('Name a quantity on at least one line.'));
        }

        return DB::transaction(function () use ($order, $warehouse, $rows, $actor, $date): Delivery {
            $day = $date === null ? CarbonImmutable::today() : CarbonImmutable::parse((string) ($date instanceof CarbonImmutable ? $date->toDateString() : $date));
            $series = $this->numbers->defaultSeries(TransactionType::DeliveryOrder, $actor)
                ?? throw new RuntimeException(__('No numbering series for deliveries.'));
            $delivery = Delivery::query()->create([
                'number' => $this->numbers->next($series, $day, $order->branch?->code ?? $warehouse->branch?->code),
                'series_id' => $series->id,
                'trans_date' => $day->toDateString(),
                'customer_id' => $order->customer_id,
                'branch_id' => $order->branch_id ?? $warehouse->branch_id,
                'taxable' => $order->taxable,
                'inclusive_tax' => $order->inclusive_tax,
                'payment_term_id' => $order->payment_term_id,
                'po_number' => $order->po_number,
                'to_address' => $order->to_address,
                'description' => $order->description,
                'created_by' => $actor->id,
            ]);
            foreach ($rows as $row) {
                $delivery->lines()->create($row);
            }
            $delivery->refreshTotal();
            $this->documents->created($delivery);

            return $delivery->fresh();
        });
    }
}
