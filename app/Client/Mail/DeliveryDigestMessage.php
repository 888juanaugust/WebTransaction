<?php

declare(strict_types=1);

namespace App\Client\Mail;

use App\Client\Domain\Orders\DeliveryWatch;
use App\Domain\Company\CompanyIdentity;
use App\Domain\Shared\Format;
use App\Models\Sales\SalesOrder;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The daily digest of orders accepted 30 days ago or more: delivered, partly, or not at all. */
class DeliveryDigestMessage extends Mailable
{
    /** @param  list<array{order: SalesOrder, summary: array}>  $rows */
    public function __construct(public array $rows, public int $days) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':n order(s) accepted :days days ago or more', ['n' => count($this->rows), 'days' => $this->days]));
    }

    public function content(): Content
    {
        return new Content(text: 'client.mail.delivery-digest', with: [
            'company' => app(CompanyIdentity::class)->letterhead()['name'],
            'lines' => array_map(fn (array $r) => [
                'number' => $r['order']->number,
                'customer' => $r['order']->customer?->name ?? '',
                'days' => $r['summary']['days'],
                'state' => DeliveryWatch::stateLabel($r['summary']['state']),
                'delivered' => Format::quantity($r['summary']['delivered']).' / '.Format::quantity($r['summary']['ordered']),
                'deliveries' => implode(', ', $r['summary']['deliveries']),
                'warehouses' => implode(', ', $r['summary']['warehouses']),
            ], $this->rows),
        ]);
    }
}
