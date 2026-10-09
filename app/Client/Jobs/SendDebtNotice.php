<?php

declare(strict_types=1);

namespace App\Client\Jobs;

use App\Client\Domain\Debt\DebtNotices;
use App\Models\Sales\SalesInvoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sends the aging notice for one invoice; running it twice sends it once. */
class SendDebtNotice implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $invoiceId) {}

    public function handle(DebtNotices $notices): void
    {
        $invoice = SalesInvoice::query()->find($this->invoiceId);
        if ($invoice !== null && $invoice->payment_status !== 'paid') {
            $notices->send($invoice);
        }
    }
}
