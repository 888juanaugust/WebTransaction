<?php

namespace Tests\Feature;

use App\Domain\Posting\DocumentRepository;
use App\Domain\Printing\PdfRenderer;
use App\Domain\Tax\TaxInvoiceMailer;
use App\Filament\Pages\Tax\EmailTaxInvoice;
use App\Mail\TaxInvoiceMessage;
use App\Models\Company\TaxCode;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\SalesInvoice;
use App\Models\Tax\TaxInvoiceMail;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** Tax invoices emailed with the Coretax PDF and the company's own invoice, logged, sent once unless asked again. */
class TaxInvoiceMailTest extends TestCase
{
    private SalesInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00'));
        $this->enableAllModules();
        $this->seed();
        $this->actingAsAdmin();
        Storage::fake('local');
        Mail::fake();
        $customer = $this->sampleCustomer(['email' => 'office@example.test', 'tax_invoice_email' => 'tax@example.test']);
        $service = $this->sampleItem(['number' => 'SVC-1', 'name' => 'Consulting', 'item_type' => 'service']);
        $invoice = SalesInvoice::query()->create(['number' => 'INV-1', 'trans_date' => '2026-11-10', 'customer_id' => $customer->id, 'taxable' => true, 'inclusive_tax' => false, 'nsfp' => '04002600000000123', 'created_by' => auth()->id()]);
        $invoice->lines()->create(['sort' => 0, 'item_id' => $service->id, 'quantity' => 1, 'unit_id' => $service->unit1_id, 'base_quantity' => 1, 'unit_price' => 1_000_000, 'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => Warehouse::default()->id]);
        $invoice->refreshTotal();
        app(DocumentRepository::class)->created($invoice);
        $this->invoice = $invoice->fresh();
    }

    private function upload(string $name, string $content = '%PDF-1.4 test'): string
    {
        $path = 'tax-invoices/uploads/'.md5($name).'.pdf';
        Storage::disk('local')->put($path, $content);

        return $path;
    }

    private function mailer(): TaxInvoiceMailer
    {
        return app(TaxInvoiceMailer::class);
    }

    public function test_coretax_pdfs_match_their_invoice_by_serial(): void
    {
        $result = $this->mailer()->attachMany([
            'Faktur Pajak 040026-00000000123.pdf' => $this->upload('a'),
            'scan.pdf' => $this->upload('b'),
        ]);
        $this->assertSame(['Faktur Pajak 040026-00000000123.pdf' => 'INV-1'], $result['matched']);
        $this->assertSame(['scan.pdf'], $result['unmatched']);
        $this->assertSame('tax-invoices/coretax/'.$this->invoice->id.'-04002600000000123.pdf', $this->invoice->fresh()->coretax_pdf_path);
        $this->assertCount(1, Storage::disk('local')->allFiles('tax-invoices/coretax'));
        $this->assertSame([], Storage::disk('local')->allFiles('tax-invoices/uploads'), 'the unmatched upload is not kept');

        // A file whose name says nothing is matched by the serial in its text.
        $this->assertSame(['download.pdf' => 'INV-1'], $this->mailer()->attachMany(['download.pdf' => $this->upload('c', '%PDF-1.4 Nomor Seri 04002600000000123 ...')])['matched']);
    }

    public function test_a_tax_invoice_is_sent_once_with_both_pdfs(): void
    {
        $this->mailer()->attach($this->invoice, $this->upload('a'));
        $request = $this->mailer()->queue($this->invoice->fresh());

        Mail::assertSent(TaxInvoiceMessage::class, fn (TaxInvoiceMessage $mail) => $mail->hasTo('tax@example.test') && count($mail->attachments()) === 2
            && $mail->envelope()->subject === 'Tax invoice 04002600000000123 for INV-1');
        $this->assertSame(['queued', 'sent'], TaxInvoiceMail::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame('sent', $this->mailer()->status($this->invoice));

        // The job running again sends nothing more.
        $this->mailer()->deliver($request->id);
        Mail::assertSentCount(1);
        $this->assertSame(2, TaxInvoiceMail::query()->count());

        try {
            $this->mailer()->queue($this->invoice->fresh());
            $this->fail('a sent invoice is not sent twice by accident');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already sent', $e->getMessage());
        }
        $this->mailer()->queue($this->invoice->fresh(), again: true);
        Mail::assertSentCount(2);

        $this->assertThrows(fn () => TaxInvoiceMail::query()->first()->update(['status' => 'sent']), QueryException::class);
    }

    public function test_a_failure_is_logged(): void
    {
        $this->mailer()->attach($this->invoice, $this->upload('a'));
        $this->actingAs(User::factory()->create()); // no print right on sales invoices: the invoice PDF cannot be made
        $this->mailer()->queue($this->invoice->fresh());

        Mail::assertNothingSent();
        $failed = TaxInvoiceMail::query()->where('status', 'failed')->sole();
        $this->assertStringContainsString('print right', (string) $failed->error);
        $this->assertSame('failed', $this->mailer()->status($this->invoice));
    }

    public function test_the_invoice_pdf_renders_and_the_screen_sends(): void
    {
        $this->assertStringStartsWith('%PDF', app(PdfRenderer::class)->render('sales_invoice', $this->invoice->id, auth()->user()));
        $this->assertFalse((bool) $this->invoice->fresh()->is_printed, 'a PDF for email does not mark the invoice printed');

        $this->mailer()->attach($this->invoice, $this->upload('a'));
        Livewire::test(EmailTaxInvoice::class)
            ->assertCanSeeTableRecords([$this->invoice])
            ->assertSee('tax@example.test')
            ->callTableAction('send', $this->invoice)
            ->assertNotified();
        Mail::assertSent(TaxInvoiceMessage::class);
        Livewire::test(EmailTaxInvoice::class)->assertTableActionHidden('send', $this->invoice)->assertTableActionVisible('sendAgain', $this->invoice);
    }

    public function test_a_path_typed_into_an_upload_field_is_refused(): void
    {
        Storage::disk('local')->put('tax-filings/annual-2026.xml', '<secret/>');

        // Through the screen: the upload field refuses a path it did not upload.
        Livewire::test(EmailTaxInvoice::class)
            ->mountTableAction('attachPdf', $this->invoice)
            ->setTableActionData(['file' => ['x' => 'tax-filings/annual-2026.xml']])
            ->callMountedTableAction();
        $this->assertTrue(Storage::disk('local')->exists('tax-filings/annual-2026.xml'), 'not moved');
        $this->assertNull($this->invoice->fresh()->coretax_pdf_path);

        // And the mailer takes nothing from outside its own upload folder.
        $this->assertThrows(fn () => $this->mailer()->attach($this->invoice, 'tax-filings/annual-2026.xml'), RuntimeException::class, 'Only a PDF uploaded here');
        $this->assertThrows(fn () => $this->mailer()->attachMany(['a.pdf' => 'tax-filings/annual-2026.xml']), RuntimeException::class);
        $this->assertTrue(Storage::disk('local')->exists('tax-filings/annual-2026.xml'), 'not deleted');
    }
}
