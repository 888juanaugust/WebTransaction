<?php

declare(strict_types=1);

namespace App\Client\Portal\Domain;

use App\Client\Domain\Pricing\PriceReason;
use App\Client\Domain\Stock\Reservations;
use App\Client\Models\PortalCart;
use App\Client\Models\PortalCartLine;
use App\Domain\Documents\LineCalculator;
use App\Domain\Inventory\Units\UnitConverter;
use App\Domain\Sales\Contracts\Prices;
use App\Domain\Sales\CreditCheck;
use App\Models\Company\TaxCode;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\Customer;
use Brick\Math\BigDecimal;

/**
 * What the cart would cost today, line by line, from the customer's own
 * rules — never stored. Availability from every warehouse's stock as a
 * badge, the customer's free credit and whether the cart exceeds it, and
 * whether the account is frozen. The order itself is priced again when placed.
 */
final class CartEstimate
{
    public const AVAILABLE = 'available';

    public const LIMITED = 'limited';

    public const ASK = 'ask';

    public function __construct(
        private readonly Prices $prices,
        private readonly Reservations $reservations,
        private readonly CreditCheck $credit,
        private readonly Cart $cart,
    ) {}

    /**
     * @return array{lines: list<array{line: PortalCartLine, base_quantity: string, unit_price: string, discount_percent: string, amount: int, tax: int, reason: string, unpriced: bool, availability: string}>,
     *     subtotal: int, tax_total: int, total: int, unpriced: int, free_credit: int|null, over_credit: bool, frozen: bool, warehouse: ?Warehouse}
     */
    public function of(PortalCart $cart): array
    {
        $customer = $cart->customer;
        $warehouse = $customer->defaultWarehouse ?? Warehouse::default();
        $tax = TaxCode::default();
        $today = today()->toDateString();

        $rows = [];
        $calc = [];
        foreach ($this->cart->lines($cart) as $line) {
            $item = $line->item;
            $base = UnitConverter::toBase($item, (string) $line->quantity, (int) $line->unit_id);
            $answer = $this->prices->resolve($customer, $item, (int) $line->unit_id, $today, $base);
            $unpriced = ($answer['reason'] ?? null) === PriceReason::Unpriced->value || ! BigDecimal::of($answer['price'])->isPositive();
            $rows[] = ['line' => $line, 'base_quantity' => $base, 'unit_price' => $answer['price'], 'discount_percent' => $answer['discount_percent'], 'reason' => (string) ($answer['reason'] ?? ''), 'unpriced' => $unpriced, 'availability' => $this->availability($item->id, $base)];
            $calc[] = ['quantity' => (string) $line->quantity, 'unit_price' => $unpriced ? '0' : $answer['price'], 'discount_percent' => $answer['discount_percent'], 'discount_amount' => 0, 'tax_code_id' => $tax?->id];
        }
        $totals = LineCalculator::compute($calc, $tax !== null, (bool) $customer->default_inc_tax);
        foreach ($rows as $i => $row) {
            $rows[$i]['amount'] = (int) ($totals['lines'][$i]['amount'] ?? 0);
            $rows[$i]['tax'] = (int) ($totals['lines'][$i]['tax_amount'] ?? 0);
        }

        $free = $this->freeCredit($customer);

        return [
            'lines' => $rows,
            'subtotal' => (int) $totals['subtotal'],
            'tax_total' => (int) $totals['tax_total'],
            'total' => (int) $totals['total'],
            'unpriced' => count(array_filter($rows, fn (array $r) => $r['unpriced'])),
            'free_credit' => $free,
            'over_credit' => $free !== null && (int) $totals['total'] > $free,
            'frozen' => $this->frozen($customer),
            'warehouse' => $warehouse,
        ];
    }

    /** The customer's free credit when the amount limit is on: the limit less exposure and open orders; null when off. */
    public function freeCredit(Customer $customer): ?int
    {
        $holder = $customer->credit_limit_mode === 'parent' && $customer->parentCustomer ? $customer->parentCustomer : $customer;
        if (! $holder->credit_limit_amount_enabled) {
            return null;
        }

        return (int) $holder->credit_limit_amount - $this->credit->exposure($customer) - $this->credit->openOrders($customer);
    }

    public function frozen(Customer $customer): bool
    {
        $freeze = $this->credit->freezeDays();

        return $freeze > 0 && $this->credit->oldestUnpaidDays($customer) > $freeze;
    }

    /** Against the stock of every warehouse: a buyer's branch is the admin's choice, and goods ship from wherever they sit. */
    public function availability(int $itemId, string $baseQuantity): string
    {
        $available = BigDecimal::of($this->reservations->availableAnywhere($itemId));
        if ($available->isLessThanOrEqualTo(0)) {
            return self::ASK;
        }

        return $available->isGreaterThanOrEqualTo($baseQuantity) ? self::AVAILABLE : self::LIMITED;
    }

    public static function availabilityLabel(string $state): string
    {
        return match ($state) {
            self::AVAILABLE => __('Available'),
            self::LIMITED => __('Limited'),
            default => __('Ask us'),
        };
    }

    public static function availabilityColor(string $state): string
    {
        return match ($state) {
            self::AVAILABLE => 'success',
            self::LIMITED => 'warning',
            default => 'gray',
        };
    }
}
