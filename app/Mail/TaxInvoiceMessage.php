<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Company\CompanyIdentity;
use App\Models\Sales\SalesInvoice;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A customer's tax invoice by email: the tax office's PDF and the company's own invoice. */
class TaxInvoiceMessage extends Mailable
{
    public function __construct(public SalesInvoice $invoice, public string $serial, private readonly string $coretaxPath, private readonly string $invoicePdf) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Tax invoice :serial for :number', ['serial' => $this->serial, 'number' => $this->invoice->number]));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.tax-invoice', with: ['company' => app(CompanyIdentity::class)->letterhead()['name']]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk('local', $this->coretaxPath)->as(self::fileName('tax-invoice-'.$this->serial))->withMime('application/pdf'),
            Attachment::fromData(fn () => $this->invoicePdf, self::fileName($this->invoice->number))->withMime('application/pdf'),
        ];
    }

    public static function fileName(string $name): string
    {
        return (preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?: 'document').'.pdf';
    }
}
