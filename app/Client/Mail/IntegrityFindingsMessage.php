<?php

declare(strict_types=1);

namespace App\Client\Mail;

use App\Client\Domain\Ops\Integrity\IntegrityFinding;
use App\Domain\Company\CompanyIdentity;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The nightly sweep's findings: every drift on a line, nothing repaired. */
class IntegrityFindingsMessage extends Mailable
{
    /** @param  list<IntegrityFinding>  $findings */
    public function __construct(public array $findings) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':count ledger difference(s) at :company', ['count' => count($this->findings), 'company' => app(CompanyIdentity::class)->letterhead()['name']]));
    }

    public function content(): Content
    {
        return new Content(text: 'client.mail.integrity-findings', with: ['company' => app(CompanyIdentity::class)->letterhead()['name']]);
    }
}
