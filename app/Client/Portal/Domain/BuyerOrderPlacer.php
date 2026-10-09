<?php

declare(strict_types=1);

namespace App\Client\Portal\Domain;

use App\Client\Domain\Pricing\PriceReason;
use App\Client\Models\CustomerUser;
use App\Client\Models\PortalCart;
use App\Client\Portal\PortalActor;
use App\Domain\Audit\Auditor;
use App\Domain\Inventory\Units\UnitConverter;
use App\Domain\Numbering\NumberGenerator;
use App\Domain\Numbering\TransactionType;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Sales\Contracts\Prices;
use App\Domain\Sales\CreditCheck;
use App\Models\Company\TaxCode;
use App\Models\Inventory\Item;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\SalesOrder;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A buyer's order: the cart's lines (or a past order's) become a sales
 * order awaiting approval, written in the Portal user's name with the buyer
 * on it. Every quantity is derived from the item, every price from the
 * customer's rules on the server; a frozen account or an unpriced line
 * refuses; the amount limit is marketing's to judge at approval.
 */
final class BuyerOrderPlacer
{
    public function __construct(
        private readonly Prices $prices,
        private readonly CreditCheck $credit,
        private readonly NumberGenerator $numbers,
        private readonly DocumentRepository $documents,
    ) {}

    /** @param  list<array{item_id: int, unit_id: int, quantity: string|int|float}>  $lines */
    public function place(CustomerUser $buyer, array $lines, ?string $poNumber = null, ?string $note = null): SalesOrder
    {
        $customer = $buyer->customer?->fresh();
        if ($customer === null || ! $customer->is_active) {
            throw new RuntimeException(__('This account cannot order: the customer is not active.'));
        }
        if (! $buyer->is_active) {
            throw new RuntimeException(__('This login is deactivated.'));
        }
        $freeze = $this->credit->freezeDays();
        if ($freeze > 0 && $this->credit->oldestUnpaidDays($customer) > $freeze) {
            throw new RuntimeException(__('New orders are frozen: an invoice is more than :days days old. Settle it, then order again.', ['days' => $freeze]));
        }

        $warehouse = $customer->defaultWarehouse ?? Warehouse::default();
        $tax = TaxCode::default();
        $today = today();
        $rows = [];
        foreach ($lines as $given) {
            $item = Item::query()->with('units')->find((int) ($given['item_id'] ?? 0));
            if ($item === null || ! $item->is_active) {
                throw new RuntimeException(__('An item in the order is no longer on sale.'));
            }
            $unitId = (int) ($given['unit_id'] ?? $item->unit1_id);
            Cart::assertUnit($item, $unitId);
            $quantity = BigDecimal::of((string) ($given['quantity'] ?? 0));
            if ($quantity->isLessThanOrEqualTo(0)) {
                continue;
            }
            $base = UnitConverter::toBase($item, $quantity->__toString(), $unitId);
            $answer = $this->prices->resolve($customer, $item, $unitId, $today, $base);
            if (($answer['reason'] ?? null) === PriceReason::Unpriced->value || ! BigDecimal::of($answer['price'])->isPositive()) {
                throw new RuntimeException(__(':item has no price yet; ask us before ordering it.', ['item' => $item->name]));
            }
            $rows[] = [
                'sort' => count($rows), 'item_id' => $item->id, 'unit_id' => $unitId,
                'quantity' => $quantity->toScale(4)->__toString(), 'base_quantity' => $base,
                'unit_price' => $answer['price'], 'discount_percent' => $answer['discount_percent'],
                'tax_code_id' => $tax?->id, 'warehouse_id' => $warehouse?->id,
            ];
        }
        if ($rows === []) {
            throw new RuntimeException(__('An order needs at least one line.'));
        }

        return PortalActor::run(function ($portal) use ($buyer, $customer, $rows, $poNumber, $note, $today): SalesOrder {
            return DB::transaction(function () use ($portal, $buyer, $customer, $rows, $poNumber, $note, $today): SalesOrder {
                $series = $this->numbers->defaultSeries(TransactionType::SalesOrder, $portal)
                    ?? throw new RuntimeException(__('No numbering series for sales orders.'));
                $order = SalesOrder::query()->create([
                    'number' => $this->numbers->next($series, $today->toImmutable(), $customer->branch?->code),
                    'series_id' => $series->id,
                    'trans_date' => $today->toDateString(),
                    'customer_id' => $customer->id,
                    'branch_id' => $customer->branch_id,
                    'taxable' => true,
                    'inclusive_tax' => (bool) $customer->default_inc_tax,
                    'payment_term_id' => $customer->payment_term_id,
                    'po_number' => $poNumber !== null && trim($poNumber) !== '' ? mb_substr(trim($poNumber), 0, 60) : null,
                    'description' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
                    'to_address' => $customer->billAddress() ?: null,
                    'approval_status' => SalesOrder::AWAITING,
                    'placed_by_customer_user_id' => $buyer->id,
                    'created_by' => $portal->id,
                ]);
                foreach ($rows as $row) {
                    $order->lines()->create($row);
                }
                $order->refreshTotal();
                $this->documents->created($order);
                Auditor::log('portal_order_placed', $order, $order->number, ['buyer' => $buyer->email, 'customer' => $customer->name, 'total' => (int) $order->fresh()->total]);

                return $order->fresh();
            });
        });
    }

    /** The cart becomes the order and is emptied in the same transaction. */
    public function checkout(CustomerUser $buyer, Cart $cart): SalesOrder
    {
        return DB::transaction(function () use ($buyer, $cart): SalesOrder {
            $portalCart = PortalCart::query()->whereKey($cart->forBuyer($buyer)->id)->lockForUpdate()->firstOrFail();
            $lines = $cart->lines($portalCart)->map(fn ($line) => ['item_id' => (int) $line->item_id, 'unit_id' => (int) $line->unit_id, 'quantity' => (string) $line->quantity])->all();
            if ($lines === []) {
                throw new RuntimeException(__('Your cart is empty.'));
            }
            $order = $this->place($buyer, $lines, $portalCart->po_number, $portalCart->note);
            $cart->clear($buyer);

            return $order;
        });
    }

    /** A past order again, with the quantities the buyer typed (the order's by default; zero drops a line). @param  array<int, string|int|float>  $quantities  line id → quantity */
    public function repeat(CustomerUser $buyer, SalesOrder $previous, array $quantities = []): SalesOrder
    {
        if ((int) $previous->customer_id !== (int) $buyer->customer_id) {
            throw new RuntimeException(__('That order is not yours.'));
        }
        $lines = [];
        foreach ($previous->lines()->get() as $line) {
            $qty = array_key_exists($line->id, $quantities) ? (string) $quantities[$line->id] : (string) $line->quantity;
            if (BigDecimal::of($qty === '' ? '0' : $qty)->isLessThanOrEqualTo(0)) {
                continue;
            }
            $lines[] = ['item_id' => (int) $line->item_id, 'unit_id' => (int) ($line->unit_id ?? 0), 'quantity' => $qty];
        }

        return $this->place($buyer, $lines, $previous->po_number, __('Again from :number', ['number' => $previous->number]));
    }
}
