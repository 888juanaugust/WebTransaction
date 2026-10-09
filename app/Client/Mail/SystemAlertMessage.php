<?php

declare(strict_types=1);

namespace App\Client\Mail;

use App\Client\Domain\Ops\Health\OpsCheck;
use App\Domain\Company\CompanyIdentity;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The critical alert: every failing check on one line each, and where to look. */
class SystemAlertMessage extends Mailable
{
    /** @param  list<OpsCheck>  $failing */
    public function __construct(public array $failing) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('[CRITICAL] System health of :company', ['company' => app(CompanyIdentity::class)->letterhead()['name']]));
    }

    public function content(): Content
    {
        return new Content(text: 'client.mail.system-alert', with: ['company' => app(CompanyIdentity::class)->letterhead()['name']]);
    }
}
