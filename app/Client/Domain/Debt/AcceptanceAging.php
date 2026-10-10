<?php

declare(strict_types=1);

namespace App\Client\Domain\Debt;

use App\Domain\Sales\Contracts\AgingDate;
use App\Models\Sales\DeliveryLine;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesOrderLine;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Central's clock: an invoice ages from the day its order was accepted,
 * the marketing seat's or the Owner's approval (sales_orders.approved_at).
 * The day is stamped on the invoice (accepted_at) the first time it is
 * asked for, once the lines exist: the earliest approval among the orders
 * its lines come from, straight or through a delivery; an invoice with no
 * order behind it takes its own date. The stamp is a cache of the ledger
 * of approvals, never edited by hand.
 */
final class AcceptanceAging implements AgingDate
{
    public function issued(SalesInvoice $invoice): CarbonInterface
    {
        if ($invoice->accepted_at !== null) {
            return CarbonImmutable::parse($invoice->accepted_at);
        }
        $accepted = $this->derive($invoice);
        if ($invoice->exists && $accepted !== null) {
            $invoice->forceFill(['accepted_at' => $accepted->toDateString()])->saveQuietly();
        }

        return $accepted ?? $invoice->trans_date;
    }

    public function issuedColumn(): string
    {
        return 'COALESCE(accepted_at, trans_date)';
    }

    /** The earliest approval among the orders the lines come from; the invoice's own date when none; null before the lines exist. */
    private function derive(SalesInvoice $invoice): ?CarbonImmutable
    {
        if (! $invoice->exists) {
            return null;
        }
        $lines = $invoice->lines()->get(['source_line_type', 'source_line_id']);
        if ($lines->isEmpty()) {
            return null;
        }
        $orderLineIds = $lines->where('source_line_type', 'sales_order_line')->pluck('source_line_id')->filter()->all();
        $deliveryLineIds = $lines->where('source_line_type', 'delivery_line')->pluck('source_line_id')->filter()->all();
        if ($deliveryLineIds !== []) {
            $orderLineIds = [...$orderLineIds, ...DeliveryLine::query()->whereKey($deliveryLineIds)->where('source_line_type', 'sales_order_line')->pluck('source_line_id')->filter()->all()];
        }
        $approved = $orderLineIds === [] ? null
            : SalesOrderLine::query()->whereKey($orderLineIds)->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')->whereNotNull('sales_orders.approved_at')->min('sales_orders.approved_at');

        return $approved ? CarbonImmutable::parse($approved)->startOfDay() : CarbonImmutable::parse($invoice->trans_date);
    }
}
