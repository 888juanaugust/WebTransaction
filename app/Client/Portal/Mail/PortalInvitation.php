<?php

declare(strict_types=1);

namespace App\Client\Portal\Mail;

use App\Client\Models\CustomerUser;
use App\Domain\Company\CompanyIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The invitation to the buyer portal: a link to set the password, valid an hour, used once. */
class PortalInvitation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public CustomerUser $buyer, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your portal account at :company', ['company' => app(CompanyIdentity::class)->letterhead()['name']]));
    }

    public function content(): Content
    {
        return new Content(text: 'client.mail.portal-invitation', with: [
            'company' => app(CompanyIdentity::class)->letterhead()['name'],
            'customer' => $this->buyer->customer?->name,
        ]);
    }
}
