<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Currency\Currencies;
use App\Domain\Fulfilment\StatusDeriver;
use App\Domain\Settlement\SettlementService;
use App\Models\Company\OpeningBalance;
use App\Models\Purchasing\PurchaseDownPayment;
use App\Models\Purchasing\PurchaseInvoice;
use App\Models\Purchasing\PurchaseInvoiceLine;
use App\Models\Purchasing\PurchaseOrderLine;
use App\Models\Purchasing\PurchasePayment;
use App\Models\Purchasing\PurchaseReturn;
use App\Models\Purchasing\Vendor;
use App\Models\Sales\Customer;
use App\Models\Sales\SalesDownPayment;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesInvoiceLine;
use App\Models\Sales\SalesOrderLine;
use App\Models\Sales\SalesReceipt;
use App\Models\Sales\SalesReturn;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales (R-07, R-08, R-09) and purchasing (R-10, R-11, R-12) reports: what
 * was sold or bought and to whom, what is still open, and how old the debt is.
 */
final class TradeReports
{
    /** @return list<array{id: int|string, name: string, invoices: int, quantity: string, amount: int, tax: int, total: int}> */
    public static function salesBy(string $dimension, Period $period): array
    {
        return self::linesBy(SalesInvoiceLine::query(), 'sales_invoice', $dimension, $period, 'customer');
    }

    /** @return list<array{id: int|string, name: string, invoices: int, quantity: string, amount: int, tax: int, total: int}> */
    public static function purchasesBy(string $dimension, Period $period): array
    {
        return self::linesBy(PurchaseInvoiceLine::query(), 'purchase_invoice', $dimension, $period, 'vendor');
    }

    /** @param  'customer'|'vendor'  $party @param 'party'|'item'|'salesman' $dimension */
    private static function linesBy(Builder $lines, string $doc, string $dimension, Period $period, string $party): array
    {
        $partyTable = $party === 'customer' ? 'customers' : 'vendors';
        $docTable = "{$doc}s";
        $query = $lines
            ->join($docTable, "{$docTable}.id", '=', "{$doc}_lines.{$doc}_id")
            ->whereBetween("{$docTable}.trans_date", [$period->fromDate(), $period->untilDate()])
            ->when($period->branchId, fn (Builder $q) => $q->where("{$docTable}.branch_id", $period->branchId));
        [$keyExpr, $nameExpr] = match ($dimension) {
            'item' => ["{$doc}_lines.item_id", "items.number || ' · ' || items.name"],
            'salesman' => ["{$doc}_lines.salesman_id", 'employees.name'],
            default => ["{$docTable}.{$party}_id", "{$partyTable}.name"],
        };
        $query = match ($dimension) {
            'item' => $query->join('items', 'items.id', '=', "{$doc}_lines.item_id"),
            'salesman' => $query->leftJoin('employees', 'employees.id', '=', "{$doc}_lines.salesman_id"),
            default => $query->join($partyTable, "{$partyTable}.id", '=', "{$docTable}.{$party}_id"),
        };
        $rows = $query
            ->selectRaw("{$keyExpr} AS key_id, {$nameExpr} AS name, COUNT(DISTINCT {$docTable}.id) AS invoices, SUM({$doc}_lines.base_quantity) AS quantity, SUM({$doc}_lines.amount - {$doc}_lines.header_discount) AS amount, SUM({$doc}_lines.tax_amount) AS tax")
            ->groupByRaw("{$keyExpr}, {$nameExpr}")
            ->orderByRaw('SUM('.$doc.'_lines.amount - '.$doc.'_lines.header_discount) DESC')
            ->get();

        $out = [];
        $sum = ['invoices' => 0, 'quantity' => BigDecimal::zero(), 'amount' => 0, 'tax' => 0];
        foreach ($rows as $row) {
            $out[] = ['id' => $row->key_id ?? 'none', 'name' => $row->name ?? '(no salesperson)', 'invoices' => (int) $row->invoices, 'quantity' => (string) BigDecimal::of((string) $row->quantity)->toScale(4), 'amount' => (int) $row->amount, 'tax' => (int) $row->tax, 'total' => (int) $row->amount + (int) $row->tax];
            $sum['invoices'] += (int) $row->invoices;
            $sum['quantity'] = $sum['quantity']->plus((string) $row->quantity);
            $sum['amount'] += (int) $row->amount;
            $sum['tax'] += (int) $row->tax;
        }
        $out[] = ['id' => 'total', 'name' => 'Total', 'invoices' => $sum['invoices'], 'quantity' => (string) $sum['quantity']->toScale(4), 'amount' => $sum['amount'], 'tax' => $sum['tax'], 'total' => $sum['amount'] + $sum['tax'], 'is_total' => true];

        return $out;
    }

    /** Sales order lines not yet fully delivered or invoiced (R-08). @return list<array<string, mixed>> */
    public static function openSalesOrders(Period $period): array
    {
        return self::openOrderLines(SalesOrderLine::query()->with(['salesOrder.customer', 'item', 'unit']), 'salesOrder', 'customer', $period);
    }

    /** Purchase order lines not yet fully received (R-11). @return list<array<string, mixed>> */
    public static function openPurchaseOrders(Period $period): array
    {
        return self::openOrderLines(PurchaseOrderLine::query()->with(['purchaseOrder.vendor', 'item', 'unit']), 'purchaseOrder', 'vendor', $period);
    }

    private static function openOrderLines(Builder $lines, string $doc, string $party, Period $period): array
    {
        $table = $doc === 'salesOrder' ? 'sales_orders' : 'purchase_orders';
        $rows = $lines
            ->whereHas($doc, fn (Builder $q) => $q
                ->whereIn('status', [StatusDeriver::PENDING, StatusDeriver::PARTIAL])
                ->whereBetween('trans_date', [$period->fromDate(), $period->untilDate()])
                ->when($period->branchId, fn (Builder $b) => $b->where('branch_id', $period->branchId))
                ->when($doc === 'salesOrder', fn (Builder $b) => $b->where('approval_status', '!=', 'rejected')))
            ->whereColumn('processed_quantity', '<', 'base_quantity')
            ->get();
        $out = [];
        $amount = 0;
        foreach ($rows as $line) {
            $header = $line->{$doc};
            $remaining = BigDecimal::of((string) $line->base_quantity)->minus((string) $line->processed_quantity);
            $value = (int) BigDecimal::of((string) $line->unit_price)->multipliedBy($remaining)->toScale(0, RoundingMode::HalfUp)->toInt();
            $out[] = [
                'id' => $line->id, 'number' => $header->number, 'trans_date' => $header->trans_date, 'party' => $header->{$party}?->name,
                'item' => $line->item ? "{$line->item->number} · {$line->item->name}" : '', 'ordered' => (string) $line->base_quantity, 'processed' => (string) $line->processed_quantity,
                'remaining' => (string) $remaining->toScale(4), 'value' => $value, 'status' => $header->status,
            ];
            $amount += $value;
        }
        $out[] = ['id' => 'total', 'number' => 'Total', 'trans_date' => null, 'party' => '', 'item' => '', 'ordered' => '', 'processed' => '', 'remaining' => '', 'value' => $amount, 'status' => '', 'is_total' => true];

        return $out;
    }

    /**
     * Receivables by age (R-09): every currency in the base currency, or (given a foreign currency) only that
     * currency's documents in its own minor units.
     *
     * @return list<array<string, mixed>>
     */
    public static function receivableAging(Period $period, ?string $basis = null, ?int $currencyId = null): array
    {
        return self::aging(SalesInvoice::query()->with('customer'), 'customer', $period, $basis ?? AgingBuckets::defaultBasis(), $currencyId);
    }

    /** Payables by age (R-12), as receivableAging(). @return list<array<string, mixed>> */
    public static function payableAging(Period $period, ?string $basis = null, ?int $currencyId = null): array
    {
        return self::aging(PurchaseInvoice::query()->with('vendor'), 'vendor', $period, $basis ?? AgingBuckets::defaultBasis(), $currencyId);
    }

    /**
     * A customer's or vendor's statement: the balance brought forward, then
     * every invoice, down payment, opening balance, return and receipt or
     * payment in the period with the running balance owed. Given a foreign
     * currency, only that currency's documents, in its own minor units.
     *
     * @return list<array{id: string, trans_date: ?string, number: string, kind: string, charge: ?int, payment: ?int, balance: int, is_total?: bool}>
     */
    public static function statement(string $party, int $partyId, Period $period, ?int $currencyId = null): array
    {
        $entries = self::statementEntries($party, $partyId, $period, Currencies::isForeign($currencyId) ? $currencyId : null)->sortBy(fn (array $e) => $e['trans_date'].'|'.$e['order'].'|'.$e['number'])->values();
        $from = $period->fromDate();
        $balance = 0;
        foreach ($entries as $entry) {
            if ($entry['trans_date'] < $from) {
                $balance += (int) $entry['charge'] - (int) $entry['payment'];
            }
        }
        $rows = [['id' => 'b', 'trans_date' => $from, 'number' => '', 'kind' => __('Balance brought forward'), 'charge' => null, 'payment' => null, 'balance' => $balance]];
        $charges = 0;
        $payments = 0;
        foreach ($entries->filter(fn (array $e) => $e['trans_date'] >= $from) as $entry) {
            $balance += (int) $entry['charge'] - (int) $entry['payment'];
            $charges += (int) $entry['charge'];
            $payments += (int) $entry['payment'];
            $rows[] = ['id' => $entry['id'], 'trans_date' => $entry['trans_date'], 'number' => $entry['number'], 'kind' => $entry['kind'], 'charge' => $entry['charge'], 'payment' => $entry['payment'], 'balance' => $balance];
        }
        $rows[] = ['id' => 'c', 'trans_date' => $period->untilDate(), 'number' => '', 'kind' => __('Balance owed'), 'charge' => $charges, 'payment' => $payments, 'balance' => $balance, 'is_total' => true];

        return $rows;
    }

    /** @return Collection<int, array{id: string, trans_date: string, order: int, number: string, kind: string, charge: ?int, payment: ?int}> up to the period's end */
    private static function statementEntries(string $party, int $partyId, Period $period, ?int $currencyId): Collection
    {
        $foreign = $currencyId !== null;
        $customer = $party === 'customer';
        $sources = $customer
            ? [[SalesInvoice::class, __('Invoice'), 1], [SalesDownPayment::class, __('Down payment'), 1], [SalesReturn::class, __('Return'), -1], [SalesReceipt::class, __('Receipt'), -1]]
            : [[PurchaseInvoice::class, __('Bill'), 1], [PurchaseDownPayment::class, __('Down payment'), 1], [PurchaseReturn::class, __('Return'), -1], [PurchasePayment::class, __('Payment'), -1]];
        $column = $customer ? 'customer_id' : 'vendor_id';
        $out = collect();
        foreach ($sources as [$class, $kind, $sign]) {
            $docs = $class::query()->where($column, $partyId)->where('trans_date', '<=', $period->untilDate())
                ->when($period->branchId, fn (Builder $q) => $q->where('branch_id', $period->branchId))
                ->when($foreign, fn (Builder $q) => $q->where('currency_id', $currencyId))->get();
            foreach ($docs as $doc) {
                $amount = self::statementAmount($doc, $foreign);
                if ($amount === null) {
                    continue;
                }
                $out->push(['id' => $doc->getMorphClass().':'.$doc->id, 'trans_date' => $doc->trans_date->toDateString(), 'order' => $sign > 0 ? 1 : 2, 'number' => (string) $doc->number, 'kind' => $kind,
                    'charge' => $sign > 0 ? $amount : null, 'payment' => $sign < 0 ? $amount : null]);
            }
        }
        $type = $customer ? (new Customer)->getMorphClass() : (new Vendor)->getMorphClass();
        foreach (OpeningBalance::query()->where('party_type', $type)->where('party_id', $partyId)->where('trans_date', '<=', $period->untilDate())
            ->when($period->branchId, fn (Builder $q) => $q->where('branch_id', $period->branchId))
            ->when($foreign, fn (Builder $q) => $q->where('currency_id', $currencyId))->get() as $opening) {
            $out->push(['id' => 'opening_balance:'.$opening->id, 'trans_date' => $opening->trans_date->toDateString(), 'order' => 0, 'number' => $opening->postingNumber(), 'kind' => __('Opening balance'), 'charge' => (int) ($foreign ? $opening->fc_amount : $opening->amount), 'payment' => null]);
        }

        return $out;
    }

    /** What a document adds to or takes from the balance owed (in its own currency when $foreign); null when it counts for nothing (a bounced giro). */
    private static function statementAmount(Model $doc, bool $foreign = false): ?int
    {
        $fc = $foreign ? 'fc_' : '';
        if ($doc instanceof SalesReceipt || $doc instanceof PurchasePayment) {
            if ($doc->giro?->isBounced()) {
                return null;
            }

            return (int) $doc->lines()->sum(DB::raw($foreign ? 'coalesce(fc_amount, 0) + coalesce(fc_discount, 0)' : 'amount + discount'));
        }
        if ($doc instanceof SalesInvoice || $doc instanceof PurchaseInvoice) {
            return (int) $doc->{$fc.'total'} - (int) $doc->{$fc.'down_payment_total'};
        }

        return (int) $doc->{$fc.'total'};
    }

    /** @return Collection<int, OpeningBalance> the party kind's opening balances still open, posted by the period's end */
    private static function openOpenings(string $party, Period $period, bool $past = false): Collection
    {
        $type = $party === 'customer' ? (new Customer)->getMorphClass() : (new Vendor)->getMorphClass();

        return OpeningBalance::query()->with('party')
            ->where('party_type', $type)
            ->where('trans_date', '<=', $period->untilDate())
            ->when(! $past, fn (Builder $q) => $q->where('payment_status', '!=', 'paid'))
            ->when($period->branchId, fn (Builder $q) => $q->where('branch_id', $period->branchId))
            ->get();
    }

    /** Buckets (from Preferences) by days since the invoice (or its due) date, as at the period's end. */
    private static function aging(Builder $invoices, string $party, Period $period, string $basis, ?int $currencyId = null): array
    {
        $settlement = app(SettlementService::class);
        $foreign = Currencies::isForeign($currencyId);
        $asOf = $period->until;
        // As at a past date, an invoice paid since was still open then: it is read with the settlements up to that date.
        $past = $period->untilDate() < today()->toDateString();
        $open = $invoices
            ->where('trans_date', '<=', $period->untilDate())
            ->when(! $past, fn (Builder $q) => $q->where('payment_status', '!=', 'paid'))
            ->when($period->branchId, fn (Builder $q) => $q->where('branch_id', $period->branchId))
            ->get()
            ->concat(self::openOpenings($party, $period, $past))
            ->when($foreign, fn (Collection $docs) => $docs->filter(fn (Model $doc) => (int) $doc->getAttribute('currency_id') === $currencyId));
        $columns = AgingBuckets::all();
        $buckets = array_fill_keys(array_column($columns, 'key'), 0);
        $byParty = [];
        foreach ($open as $invoice) {
            $balance = $past ? $settlement->balanceAsOf($invoice, $period->untilDate(), $foreign)
                : ($foreign ? $settlement->foreignBalance($invoice) : $settlement->balance($invoice));
            if ($balance <= 0) {
                continue;
            }
            $opening = $invoice instanceof OpeningBalance;
            $issued = $opening ? $invoice->agingDate() : $invoice->trans_date;
            $reference = CarbonImmutable::parse($basis === 'due_date' && $invoice->due_date ? $invoice->due_date : $issued);
            $days = (int) $reference->diffInDays($asOf, false);
            $bucket = AgingBuckets::keyFor($days, $columns);
            $name = ($opening ? $invoice->party?->name : $invoice->{$party}?->name) ?? '—';
            $id = $opening ? $invoice->party_id : $invoice->{"{$party}_id"};
            $byParty[$id] ??= ['id' => $id, 'name' => $name, 'invoices' => 0] + $buckets + ['total' => 0, 'oldest_days' => 0];
            $byParty[$id]['invoices']++;
            $byParty[$id][$bucket] += $balance;
            $byParty[$id]['total'] += $balance;
            $byParty[$id]['oldest_days'] = max($byParty[$id]['oldest_days'], $days);
        }
        usort($byParty, fn ($a, $b) => $b['total'] <=> $a['total']);
        $total = ['id' => 'total', 'name' => 'Total', 'invoices' => 0] + $buckets + ['total' => 0, 'oldest_days' => 0, 'is_total' => true];
        foreach ($byParty as $row) {
            foreach (array_keys($buckets) as $k) {
                $total[$k] += $row[$k];
            }
            $total['invoices'] += $row['invoices'];
            $total['total'] += $row['total'];
            $total['oldest_days'] = max($total['oldest_days'], $row['oldest_days']);
        }
        $byParty[] = $total;

        return array_values($byParty);
    }
}
