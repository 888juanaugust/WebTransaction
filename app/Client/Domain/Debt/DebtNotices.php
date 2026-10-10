<?php

declare(strict_types=1);

namespace App\Client\Domain\Debt;

use App\Client\Mail\DebtNoticeMessage;
use App\Client\Models\DebtNotice;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Sales\Contracts\AgingDate;
use App\Domain\Sales\CreditCheck;
use App\Domain\Shared\Locales;
use App\Models\Sales\SalesInvoice;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The aging notice: an unpaid invoice older than the notice days (Business
 * Rules, 120 for Central) is told once to the customer and the team, by
 * email in the company's language. The row is written before the mail, so
 * a job that runs twice sends once. The freeze itself is the base's credit
 * check at order approval; nothing is stored for it.
 */
final class DebtNotices
{
    public function __construct(private readonly CreditCheck $credit, private readonly ApprovalEngine $approvals, private readonly AgingDate $aging) {}

    /** The invoices due a notice today: approved, unpaid, old enough, not noticed yet. */
    public function due(): Collection
    {
        $days = $this->credit->noticeDays();
        if ($days <= 0) {
            return new Collection;
        }

        return SalesInvoice::query()->with('customer')
            ->where('payment_status', '!=', 'paid')
            ->whereRaw($this->aging->issuedColumn().' <= ?', [today()->subDays($days)->toDateString()])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('debt_notices')->whereColumn('debt_notices.sales_invoice_id', 'sales_invoices.id'))
            ->orderBy('trans_date')
            ->get()
            ->filter(fn (SalesInvoice $invoice) => $this->approvals->isApproved($invoice))
            ->values();
    }

    /** Sends the notice for one invoice, once: returns the notice, or null when it was sent before. */
    public function send(SalesInvoice $invoice): ?DebtNotice
    {
        $days = (int) $this->aging->issued($invoice)->diffInDays(today(), false);
        $inserted = DebtNotice::query()->insertOrIgnore([
            'sales_invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'days' => max(0, $days), 'created_at' => now(),
        ]);
        if ($inserted === 0) {
            return null;
        }
        $notice = DebtNotice::query()->where('sales_invoice_id', $invoice->id)->firstOrFail();

        $recipients = $this->recipients($invoice);
        if ($recipients === []) {
            Log::warning("Debt notice for {$invoice->number}: nobody to send it to (no customer email, no active seat).");
            $notice->forceFill(['sent_to' => [], 'sent_at' => now()])->save();

            return $notice;
        }
        Locales::using(Locales::companyDefault(), function () use ($invoice, $recipients, $days): void {
            Mail::to($recipients)->send(new DebtNoticeMessage($invoice, max(0, $days), $this->credit->freezeDays()));
        });
        $notice->forceFill(['sent_to' => $recipients, 'sent_at' => now()])->save();

        return $notice;
    }

    /** The customer's email and the active seats' emails, each once. @return list<string> */
    public function recipients(SalesInvoice $invoice): array
    {
        $customer = $invoice->customer;
        $addresses = [trim((string) $customer?->email)];
        foreach ([$customer?->sales_user_id, $customer?->marketing_user_id] as $seatId) {
            $seat = $seatId ? User::query()->find($seatId) : null;
            if ($seat !== null && $seat->is_active) {
                $addresses[] = trim((string) $seat->email);
            }
        }

        return array_values(array_unique(array_filter($addresses, fn (string $a) => $a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL) !== false)));
    }

    /** The day new transactions freeze for an invoice: strictly after the freeze days, so the day after them; null when the freeze is off. */
    public function freezesOn(SalesInvoice $invoice): ?CarbonInterface
    {
        $freeze = $this->credit->freezeDays();

        return $freeze > 0 ? $invoice->trans_date->copy()->addDays($freeze + 1) : null;
    }
}
