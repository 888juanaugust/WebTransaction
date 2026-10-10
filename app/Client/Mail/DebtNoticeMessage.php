<?php

declare(strict_types=1);

namespace App\Client\Mail;

use App\Domain\Company\CompanyIdentity;
use App\Domain\Sales\Contracts\AgingDate;
use App\Domain\Shared\Format;
use App\Models\Sales\SalesInvoice;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The aging notice to a customer and their team: the invoice, its balance and age, and the day the account freezes. */
class DebtNoticeMessage extends Mailable
{
    public function __construct(public SalesInvoice $invoice, public int $days, public int $freezeDays) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Invoice :number is :days days old', ['number' => $this->invoice->number, 'days' => $this->days]));
    }

    public function content(): Content
    {
        $freezesOn = $this->freezeDays > 0 ? app(AgingDate::class)->issued($this->invoice)->copy()->addDays($this->freezeDays + 1) : null;

        return new Content(text: 'client.mail.debt-notice', with: [
            'company' => app(CompanyIdentity::class)->letterhead()['name'],
            'balance' => Format::money($this->invoice->balance()),
            'date' => Format::date($this->invoice->trans_date),
            'freezesOn' => $freezesOn ? Format::date($freezesOn) : null,
            'frozen' => $freezesOn !== null && $freezesOn->lessThanOrEqualTo(today()),
        ]);
    }
}
