<?php

declare(strict_types=1);

namespace App\Filament\Portal\Support;

use App\Domain\Money;
use App\Models\Invoice;
use App\Models\Order;

/**
 * The handful of phrases the buyer portal repeats.
 *
 * The dashboard widgets and the full pages show the same rows, so the same
 * order or invoice appeared twice on one screen described two different ways —
 * "belum dihitung" on the dashboard and "menunggu konfirmasi" on the orders
 * page, "1 minggu dari sekarang" against "dalam 8 hari". Both are about money,
 * and a customer reading two answers to one question is a support call.
 */
class PortalLabels
{
    /**
     * An order's total, or an honest statement that it does not have one yet.
     *
     * Nothing is priced until staff confirm, and "Rp 0" would read as free
     * rather than as not-yet-priced — on exactly the number a customer will
     * hold us to.
     */
    public static function orderTotal(Order $order): string
    {
        return $order->confirmed_at === null
            ? '— menunggu konfirmasi —'
            : Money::format((int) $order->total_rupiah);
    }

    /** Whether an invoice is genuinely late, as opposed to merely unpaid. */
    public static function isLate(Invoice $invoice): bool
    {
        return $invoice->status === Invoice::STATUS_OPEN && $invoice->due_date->isPast();
    }

    public static function dueLabel(Invoice $invoice): string
    {
        if ($invoice->status === Invoice::STATUS_PAID) {
            return 'Lunas';
        }

        if ($invoice->status === Invoice::STATUS_VOID) {
            return 'Dibatalkan';
        }

        /*
         * abs() and the int cast are both load-bearing.
         *
         * Carbon returns this signed and as a float, so the obvious expression
         * put "Jatuh tempo dalam -8 hari" in front of a buyer for a bill due
         * next week, and "Lewat 45.811393477072 hari" for a late one. The
         * direction is already carried by the branch.
         */
        $days = (int) abs($invoice->due_date->startOfDay()->diffInDays(now()->startOfDay()));

        return $invoice->due_date->isPast()
            ? "Lewat {$days} hari"
            : "Jatuh tempo dalam {$days} hari";
    }

    /** Red for late, green for settled, neutral for merely open. */
    public static function dueColor(Invoice $invoice): string
    {
        return match (true) {
            $invoice->status === Invoice::STATUS_PAID => 'success',
            self::isLate($invoice) => 'danger',
            default => 'gray',
        };
    }
}
