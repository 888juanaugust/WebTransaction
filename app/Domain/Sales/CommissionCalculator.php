<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\Company\Employee;
use App\Models\Inventory\StockMovement;
use App\Models\Sales\DeliveryLine;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesInvoiceLine;
use App\Models\Sales\SalesmanCommission;
use App\Models\Sales\SalesReturn;
use App\Models\Sales\SalesReturnLine;
use App\Models\Settlement\PaymentAllocation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * What each salesperson earned in a period under the commission rules. Sales
 * are the net of their invoice lines (each line names its salesperson) less
 * their return lines; the basis in Preferences decides when a sale counts:
 * on the invoice's date ("invoiced amount"), or as customers pay ("amount
 * actually paid": each payment in the period counts its share of the
 * invoice, a credit used counts its share of the return). Gross profit takes
 * the cost the goods left with. Nothing is posted: this is a statement.
 *
 * A rule applies when it is active and in force in the period, covers the
 * salesperson, and its requirement is met (sales value or quantity within a
 * range; "per quantity" pays its fixed gain once per block sold). Its gain is
 * a percentage of sales value or gross profit, or a fixed amount.
 */
final class CommissionCalculator
{
    /**
     * @return list<array{salesman_id: int|null, name: string, sales: int, quantity: string, profit: int, commission: int, rules: list<array{name: string, amount: int}>}>
     */
    public function statement(CarbonImmutable|string $from, CarbonImmutable|string $until, ?string $basis = null): array
    {
        $from = CarbonImmutable::parse($from)->startOfDay();
        $until = CarbonImmutable::parse($until)->startOfDay();
        $basis ??= (string) app(Preferensi::class)->get(PreferensiKey::CommissionBasis);

        $figures = $basis === 'invoice' ? $this->invoiced($from, $until) : $this->paid($from, $until);
        $rules = SalesmanCommission::query()->where('is_active', true)->with('salesmen')->orderBy('name')->get()
            ->filter(fn (SalesmanCommission $rule) => $rule->active_period !== 'period'
                || (($rule->from_date === null || $rule->from_date->lte($until)) && ($rule->to_date === null || $rule->to_date->gte($from))));
        $names = Employee::query()->whereKey(array_filter(array_keys($figures)))->pluck('name', 'id');

        $rows = [];
        foreach ($figures as $salesmanId => $f) {
            $applied = [];
            if ($salesmanId !== 0) {
                foreach ($rules as $rule) {
                    $amount = $this->gain($rule, (int) $salesmanId, $f);
                    if ($amount !== null) {
                        $applied[] = ['name' => $rule->name, 'amount' => $amount];
                    }
                }
            }
            $rows[] = [
                'salesman_id' => $salesmanId ?: null,
                'name' => $salesmanId ? ($names[$salesmanId] ?? '?') : __('No salesperson'),
                'sales' => $f['sales']->toScale(0, RoundingMode::HalfUp)->toInt(),
                'quantity' => (string) $f['quantity']->toScale(4, RoundingMode::HalfUp),
                'profit' => $f['profit']->toScale(0, RoundingMode::HalfUp)->toInt(),
                'commission' => array_sum(array_column($applied, 'amount')),
                'rules' => $applied,
            ];
        }
        usort($rows, fn ($a, $b) => [$a['salesman_id'] === null, $a['name']] <=> [$b['salesman_id'] === null, $b['name']]);

        return $rows;
    }

    /** The rule's commission for one salesperson's figures, or null when it does not apply. */
    private function gain(SalesmanCommission $rule, int $salesmanId, array $f): ?int
    {
        if ($rule->salesman_scope === 'specific' && ! $rule->salesmen->contains('id', $salesmanId)) {
            return null;
        }
        $sales = $f['sales'];
        $quantity = $f['quantity'];
        $within = fn (BigDecimal $value, $from, $to): bool => $value->isGreaterThanOrEqualTo((string) $from) && ((float) $to <= 0 || $value->isLessThanOrEqualTo((string) $to));
        $blocks = 1;
        switch ($rule->requirement) {
            case 'sales_value':
                if (! $within($sales, $rule->requirement_from, $rule->requirement_to)) {
                    return null;
                }
                break;
            case 'sales_qty':
                if (! $within($quantity, $rule->requirement_from, $rule->requirement_to)) {
                    return null;
                }
                break;
            case 'per_qty':
                $per = BigDecimal::of((string) $rule->requirement_qty);
                if (! $per->isPositive()) {
                    return null;
                }
                $blocks = $quantity->dividedBy($per, 0, RoundingMode::Down)->toInt();
                if ($blocks <= 0) {
                    return null;
                }
                break;
        }

        if ($rule->gain_type === 'fixed') {
            return (int) $rule->gain_amount * $blocks;
        }
        $base = $rule->gain_basis === 'gross_profit' ? $f['profit'] : $sales;

        return $base->multipliedBy((string) $rule->gain_value)->dividedBy(100, 0, RoundingMode::HalfUp)->toInt();
    }

    /** Sales of invoices dated in the period, less returns dated in it. @return array<int, array{sales: BigDecimal, quantity: BigDecimal, profit: BigDecimal}> salesman id (0 = none) → figures */
    private function invoiced(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $figures = [];
        $invoiceLines = SalesInvoiceLine::query()->with('salesInvoice')
            ->whereHas('salesInvoice', fn ($q) => $q->whereBetween('trans_date', [$from->toDateString(), $until->toDateString()]))->get();
        foreach ($invoiceLines as $line) {
            $this->add($figures, $line->salesman_id, $line->netAmount(), (string) $line->base_quantity, $this->invoiceLineCost($line), '1');
        }
        $returnLines = SalesReturnLine::query()->with('salesReturn')
            ->whereHas('salesReturn', fn ($q) => $q->whereBetween('trans_date', [$from->toDateString(), $until->toDateString()]))->get();
        foreach ($returnLines as $line) {
            $this->add($figures, $line->salesman_id, $line->netAmount(), (string) $line->base_quantity, $this->returnLineCost($line), '-1');
        }

        return $figures;
    }

    /**
     * Each payment in the period counts its share of the invoice (cash paid
     * over the invoice total; a down payment deducted counts on the invoice's
     * date); each credit used counts its share of the return.
     */
    private function paid(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $shares = [];
        $allocations = PaymentAllocation::query()->active()
            ->whereIn('receivable_type', ['sales_invoice', 'sales_return'])
            ->whereBetween('trans_date', [$from->toDateString(), $until->toDateString()])
            ->get(['receivable_type', 'receivable_id', 'amount']);
        foreach ($allocations as $a) {
            $shares[$a->receivable_type][(int) $a->receivable_id] = ($shares[$a->receivable_type][(int) $a->receivable_id] ?? 0) + (int) $a->amount;
        }
        foreach (SalesInvoice::query()->whereBetween('trans_date', [$from->toDateString(), $until->toDateString()])->where('down_payment_total', '>', 0)->get(['id', 'down_payment_total']) as $invoice) {
            $shares['sales_invoice'][$invoice->id] = ($shares['sales_invoice'][$invoice->id] ?? 0) + (int) $invoice->down_payment_total;
        }

        $figures = [];
        foreach (SalesInvoice::query()->whereKey(array_keys($shares['sales_invoice'] ?? []))->with('lines.salesInvoice')->get() as $invoice) {
            if ((int) $invoice->total === 0) {
                continue;
            }
            $fraction = (string) BigDecimal::of($shares['sales_invoice'][$invoice->id])->dividedBy((int) $invoice->total, 8, RoundingMode::HalfUp);
            foreach ($invoice->lines as $line) {
                $this->add($figures, $line->salesman_id, $line->netAmount(), (string) $line->base_quantity, $this->invoiceLineCost($line), $fraction);
            }
        }
        foreach (SalesReturn::query()->whereKey(array_keys($shares['sales_return'] ?? []))->with('lines.salesReturn')->get() as $return) {
            if ((int) $return->total === 0) {
                continue;
            }
            // A credit used comes as a negative allocation: its share takes sales away.
            $fraction = (string) BigDecimal::of($shares['sales_return'][$return->id])->dividedBy((int) $return->total, 8, RoundingMode::HalfUp);
            foreach ($return->lines as $line) {
                $this->add($figures, $line->salesman_id, $line->netAmount(), (string) $line->base_quantity, $this->returnLineCost($line), $fraction);
            }
        }

        return $figures;
    }

    private function add(array &$figures, ?int $salesmanId, int $net, string $quantity, int $cost, string $factor): void
    {
        $key = $salesmanId ?? 0;
        $figures[$key] ??= ['sales' => BigDecimal::zero(), 'quantity' => BigDecimal::zero(), 'profit' => BigDecimal::zero()];
        $figures[$key]['sales'] = $figures[$key]['sales']->plus(BigDecimal::of($net)->multipliedBy($factor));
        $figures[$key]['quantity'] = $figures[$key]['quantity']->plus(BigDecimal::of($quantity)->multipliedBy($factor));
        $figures[$key]['profit'] = $figures[$key]['profit']->plus(BigDecimal::of($net - $cost)->multipliedBy($factor));
    }

    /** The cost an invoice line's goods left with: its own movements, or its share of the delivery's. */
    private function invoiceLineCost(SalesInvoiceLine $line): int
    {
        if ($line->source_line_type === 'delivery_line' && $line->source_line_id) {
            $delivery = DeliveryLine::query()->find($line->source_line_id);
            if ($delivery === null || BigDecimal::of((string) $delivery->base_quantity)->isZero()) {
                return 0;
            }
            $cost = (int) StockMovement::query()->active()->where('source_line_type', 'delivery_line')->where('source_line_id', $delivery->id)->sum('total_cost');

            return BigDecimal::of($cost)->multipliedBy((string) $line->base_quantity)->dividedBy((string) $delivery->base_quantity, 0, RoundingMode::HalfUp)->toInt();
        }

        return (int) StockMovement::query()->active()->where('source_line_type', 'sales_invoice_line')->where('source_line_id', $line->id)->sum('total_cost');
    }

    private function returnLineCost(SalesReturnLine $line): int
    {
        return (int) StockMovement::query()->active()->where('source_line_type', 'sales_return_line')->where('source_line_id', $line->id)->sum('total_cost');
    }
}
