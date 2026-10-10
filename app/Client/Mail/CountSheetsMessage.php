<?php

declare(strict_types=1);

namespace App\Client\Mail;

use App\Domain\Company\CompanyIdentity;
use App\Domain\Shared\Format;
use App\Models\Inventory\StockOpnameResult;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The count sheets waiting: to be counted by the gudang, or counted and waiting for Purchasing's approval. */
class CountSheetsMessage extends Mailable
{
    /** @param  list<StockOpnameResult>  $sheets @param 'count'|'approve' $what */
    public function __construct(public array $sheets, public string $what) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->what === 'approve'
            ? __(':n count(s) waiting for approval', ['n' => count($this->sheets)])
            : __(':n count sheet(s) waiting to be counted', ['n' => count($this->sheets)]));
    }

    public function content(): Content
    {
        return new Content(text: 'client.mail.count-sheets', with: [
            'company' => app(CompanyIdentity::class)->letterhead()['name'],
            'what' => $this->what,
            'lines' => array_map(fn (StockOpnameResult $r) => ['number' => $r->number, 'warehouse' => $r->order?->warehouse?->name ?? '', 'date' => Format::date($r->trans_date), 'items' => $r->lines()->count()], $this->sheets),
        ]);
    }
}
