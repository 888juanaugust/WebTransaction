<?php

namespace Tests\Feature\Client\Portal;

use App\Client\Portal\PortalDocuments;
use App\Domain\Approval\ApprovalEngine;
use App\Models\Sales\SalesInvoice;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Client\Support\Buyer;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** A buyer's documents as PDF: from a signed, short-lived link, their own customer's and approved only, rendered in the Portal user's name. */
class PortalDocumentsTest extends TestCase
{
    use Buyer, OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        $this->stock($this->gudangJakarta, 50);
    }

    public function test_the_invoice_the_order_and_the_surat_jalan_download_from_signed_links(): void
    {
        $invoice = $this->invoice(1, 100_000);
        $order = $this->order(3, $this->gudangJakarta);
        app(ApprovalEngine::class)->approve($order, $this->marketing);
        $delivery = $this->deliver($order, 3);
        $this->actingAsBuyer($this->buyer());

        foreach ([$invoice, $order, $delivery] as $document) {
            $url = PortalDocuments::url($document);
            $this->assertStringContainsString('/portal/document/', (string) $url);
            $response = $this->get((string) $url);
            $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent());
            $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        }
        $this->assertFalse($invoice->fresh()->is_printed, 'a download is not the staff Print button');
    }

    public function test_an_unsigned_an_expired_or_another_customers_link_fails(): void
    {
        $invoice = $this->invoice(1, 100_000);
        $other = $this->sampleCustomer(['name' => 'Other', 'number' => 'C-O', 'branch_id' => $this->jakarta->id]);
        $mine = $this->customer;
        $this->customer = $other;
        $foreign = $this->invoice(1, 100_000);
        $this->customer = $mine;
        $this->actingAsBuyer($this->buyer());

        $this->get('/portal/document/sales_invoice/'.$invoice->id)->assertForbidden();
        $this->get(URL::temporarySignedRoute('filament.portal.document', now()->subMinute(), ['alias' => 'sales_invoice', 'id' => $invoice->id]))->assertForbidden();
        $this->get((string) PortalDocuments::url($foreign))->assertNotFound();
        $this->get(URL::temporarySignedRoute('filament.portal.document', now()->addMinutes(5), ['alias' => 'journal_voucher', 'id' => 1]))->assertNotFound();
        $this->assertNull(PortalDocuments::url($this->customer), 'only the three document kinds');
    }

    public function test_an_order_still_awaiting_approval_does_not_print(): void
    {
        $order = $this->order(1, $this->gudangJakarta);
        $this->actingAsBuyer($this->buyer());

        $this->get((string) PortalDocuments::url($order))->assertForbidden();
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $invoice = $this->invoice(1, 100_000);
        $url = PortalDocuments::url($invoice);
        auth()->forgetUser();

        $this->get((string) $url)->assertRedirect('/portal/login');
        $this->assertInstanceOf(SalesInvoice::class, $invoice);
    }
}
