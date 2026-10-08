<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Tax\TaxInvoiceMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sends one queued tax invoice email; running it twice sends it once. */
class SendTaxInvoiceMail implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $requestId) {}

    public function handle(TaxInvoiceMailer $mailer): void
    {
        $mailer->deliver($this->requestId);
    }
}
