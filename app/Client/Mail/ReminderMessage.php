<?php

declare(strict_types=1);

namespace App\Client\Mail;

use App\Domain\Company\CompanyIdentity;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A plain reminder to staff: a subject, a few lines, the company's name. */
class ReminderMessage extends Mailable
{
    /** @param  list<string>  $lines */
    public function __construct(public string $subjectLine, public array $lines) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'client.mail.reminder', with: ['company' => app(CompanyIdentity::class)->letterhead()['name'], 'lines' => $this->lines]);
    }
}
