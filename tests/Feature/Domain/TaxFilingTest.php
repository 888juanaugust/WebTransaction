<?php

namespace Tests\Feature\Domain;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Tax\FilingDocuments;
use App\Domain\Tax\TaxFilingService;
use App\Models\Company\TaxCode;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\PurchaseDownPayment;
use App\Models\Sales\SalesDownPayment;
use App\Models\Sales\SalesInvoice;
use App\Models\Tax\TaxFiling;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TaxFilingTest extends TestCase
{
    private SalesInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-30 09:00:00');
        CarbonImmutable::setTestNow('2026-11-30 09:00:00');
        Storage::fake('local');
        $this->seed();
        $this->actingAsAdmin();
        app(Preferensi::class)->setMany([
            PreferensiKey::CompanyNpwp->value => '01.234.567.8-901.000',
            PreferensiKey::TaxCompanyName->value => 'Example Co',
        ]);

        $customer = $this->sampleCustomer(['wp_type' => 'npwp', 'wp_number' => '09.876.543.2-109.000', 'wp_name' => 'Acme Trading Ltd', 'bill_street' => 'Jl. Raya 1', 'bill_city' => 'Jakarta', 'bill_zip_code' => '12345']);
        $item = $this->sampleItem(['item_tax_code' => '270111']);
        $docs = app(DocumentRepository::class);
        $opening = InventoryAdjustment::query()->create(['number' => 'ADJ-OPEN', 'trans_date' => '2026-10-01', 'created_by' => auth()->id()]);
        $opening->lines()->create(['sort' => 0, 'item_id' => $item->id, 'adjustment_type' => 'quantity', 'quantity' => 20, 'unit_id' => $item->unit1_id, 'base_quantity' => 20, 'unit_cost' => 100_000, 'total_cost' => 0, 'warehouse_id' => Warehouse::default()->id]);
        $docs->created($opening);

        $this->invoice = SalesInvoice::query()->create(['number' => 'INV-2611-0001', 'trans_date' => '2026-11-05', 'customer_id' => $customer->id, 'taxable' => true, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $this->invoice->lines()->create(['sort' => 0, 'item_id' => $item->id, 'quantity' => 10, 'unit_id' => $item->unit1_id, 'base_quantity' => 10, 'unit_price' => 150_000, 'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => Warehouse::default()->id]);
        $this->invoice->refreshTotal();
        $docs->created($this->invoice);
        $this->invoice->refresh();
    }

    public function test_the_period_lists_taxable_invoices_and_the_coretax_file_carries_the_11_12_tax_base(): void
    {
        $this->assertSame(1_500_000, $this->invoice->subtotal);
        $this->assertSame(1_375_000, $this->invoice->dpp_total, '11/12 of the price');
        $this->assertSame(165_000, $this->invoice->tax_total, '12 % of the other tax base: an 11 % burden');

        $listed = FilingDocuments::query(TaxFiling::OUT, '2026-11-01', '2026-11-30');
        $this->assertSame(['INV-2611-0001'], $listed->pluck('number')->all());
        $this->assertSame('draft', FilingDocuments::status($this->invoice));
        $this->assertCount(0, FilingDocuments::query(TaxFiling::OUT, '2026-12-01', '2026-12-31')->get());

        $filing = app(TaxFilingService::class)->export($listed->get(), TaxFiling::CORETAX, 2026, 11);
        $this->assertSame(1, $filing->document_count);
        $this->assertSame(1_375_000, $filing->dpp_total);
        $this->assertSame(165_000, $filing->tax_total);
        Storage::disk('local')->assertExists($filing->file_path);
        $xml = Storage::disk('local')->get($filing->file_path);
        $this->assertStringContainsString('<TaxInvoiceBulk', $xml);
        $this->assertStringContainsString('<TIN>012345678901000</TIN>', $xml);
        $this->assertStringContainsString('<TrxCode>04</TrxCode>', $xml, 'the 11/12 base means transaction code 04');
        $this->assertStringContainsString('<BuyerTin>098765432109000</BuyerTin>', $xml);
        $this->assertStringContainsString('<BuyerName>Acme Trading Ltd</BuyerName>', $xml);
        $this->assertStringContainsString('<BuyerAdress>Jl. Raya 1, Jakarta, 12345</BuyerAdress>', $xml);
        $this->assertStringContainsString('<Code>270111</Code>', $xml);
        $this->assertStringContainsString('<Unit>UM.0018</Unit>', $xml);
        $this->assertStringContainsString('<Price>150000</Price>', $xml);
        $this->assertStringContainsString('<Qty>10</Qty>', $xml);
        $this->assertStringContainsString('<TaxBase>1500000</TaxBase>', $xml);
        $this->assertStringContainsString('<OtherTaxBase>1375000</OtherTaxBase>', $xml);
        $this->assertStringContainsString('<VAT>165000</VAT>', $xml);
        $this->assertTrue(simplexml_load_string($xml) !== false, 'well-formed');
        $this->assertSame('exported', FilingDocuments::status($this->invoice));

        $result = app(TaxFilingService::class)->storeSerials("INV-2611-0001\t010.002-26.00000123\nINV-NOPE, 010.002-26.00000124\n");
        $this->assertSame(['INV-2611-0001 → 010.002-26.00000123'], $result['stored']);
        $this->assertSame(['INV-NOPE, 010.002-26.00000124'], $result['unknown']);
        $this->assertSame('010.002-26.00000123', $this->invoice->fresh()->nsfp);
        $this->assertNotNull($this->invoice->fresh()->nsfp_filed_at);
        $this->assertSame('numbered', FilingDocuments::status($this->invoice->fresh()));
    }

    public function test_the_legacy_csv_reproduces_the_older_layout(): void
    {
        $this->invoice->forceFill(['nsfp' => '010.002-26.00000123'])->saveQuietly();
        $filing = app(TaxFilingService::class)->export(FilingDocuments::query(TaxFiling::OUT, '2026-11-01', '2026-11-30')->get(), TaxFiling::LEGACY, 2026, 11);
        $csv = Storage::disk('local')->get($filing->file_path);
        $lines = array_values(array_filter(explode("\n", $csv)));
        $this->assertStringStartsWith('FK,KD_JENIS_TRANSAKSI,FG_PENGGANTI,NOMOR_FAKTUR', $lines[0]);
        $this->assertStringStartsWith('LT,NPWP', $lines[1]);
        $this->assertStringStartsWith('OF,KODE_OBJEK', $lines[2]);
        $this->assertSame('FK,01,0,0100022600000123,11,2026,05/11/2026,098765432109000,"Acme Trading Ltd","Jl. Raya 1, Jakarta, 12345",1375000,165000,0,,0,0,0,0,INV-2611-0001', $lines[3]);
        $this->assertStringStartsWith('LT,098765432109000,"Acme Trading Ltd"', $lines[4]);
        $this->assertSame('OF,270111,Widget,150000,10,1500000,0,1375000,165000,0,0', $lines[5]);
        $this->assertStringEndsWith('.csv', $filing->file_name);
    }

    public function test_down_payments_are_taxed_in_their_own_period_and_the_settling_invoice_reports_the_rest(): void
    {
        $docs = app(DocumentRepository::class);
        $customer = $this->invoice->customer;
        $dp = SalesDownPayment::query()->create(['number' => 'DP-2610-0001', 'trans_date' => '2026-10-20', 'customer_id' => $customer->id, 'amount' => 300_000, 'taxable' => true, 'inclusive_tax' => false, 'tax_code_id' => TaxCode::default()->id, 'created_by' => auth()->id()]);
        $dp->refreshTotal();
        $docs->created($dp);
        $this->assertSame([275_000, 33_000, 333_000], [$dp->dpp_total, $dp->tax_total, $dp->total]);

        $settling = SalesInvoice::query()->create(['number' => 'INV-2611-0002', 'trans_date' => '2026-11-10', 'customer_id' => $customer->id, 'taxable' => true, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $settling->lines()->create(['sort' => 0, 'item_id' => $this->invoice->lines()->value('item_id'), 'quantity' => 10, 'unit_id' => $this->invoice->lines()->value('unit_id'), 'base_quantity' => 10, 'unit_price' => 150_000, 'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => Warehouse::default()->id]);
        $settling->downPayments()->create(['sales_down_payment_id' => $dp->id, 'amount' => 333_000]);
        $settling->refreshTotal();
        $docs->created($settling);
        $use = $settling->downPayments()->sole();
        $this->assertSame([300_000, 275_000, 33_000], [$use->net_amount, $use->dpp_amount, $use->tax_amount], 'the deduction is stored split like the down payment');

        // The down payment's VAT is October's; the invoice reports November what is left after it.
        $october = FilingDocuments::vatDocuments(TaxFiling::OUT, '2026-10-01', '2026-10-31');
        $this->assertSame([['DP-2610-0001', 275_000, 33_000]], $october->map(fn ($r) => [$r['document']->number, $r['dpp'], $r['tax']])->all());
        $november = FilingDocuments::vatDocuments(TaxFiling::OUT, '2026-11-01', '2026-11-30');
        $this->assertSame([['INV-2611-0001', 1_375_000, 165_000], ['INV-2611-0002', 1_100_000, 132_000]], $november->map(fn ($r) => [$r['document']->number, $r['dpp'], $r['tax']])->all());

        // A purchase down payment is VAT in of its own period.
        $bill = PurchaseDownPayment::query()->create(['number' => 'PDP-1', 'trans_date' => '2026-11-12', 'vendor_id' => $this->sampleVendor()->id, 'amount' => 100_000, 'taxable' => true, 'inclusive_tax' => false, 'tax_code_id' => TaxCode::default()->id, 'created_by' => auth()->id()]);
        $bill->refreshTotal();
        $docs->created($bill);

        $return = app(TaxFilingService::class)->saveReturn('2026-11-01', '2026-11-30');
        $this->assertSame(165_000 + 132_000, $return->vat_out);
        $this->assertSame(11_000, $return->vat_in);
        $this->assertSame(297_000 - 11_000, $return->payable);
        $this->assertSame(165_000 + 165_000 - 33_000, $return->vat_out, 'what the ledger holds as VAT out for the month');

        // The e-Faktur CSV flags the invoice as the down payment's settlement.
        $filing = app(TaxFilingService::class)->export(SalesInvoice::query()->whereKey($settling->id)->get(), TaxFiling::LEGACY, 2026, 11);
        $this->assertStringContainsString('1100000,132000,0,,2,275000,33000,0,INV-2611-0002', Storage::disk('local')->get($filing->file_path));
        $this->assertContains('Deducts down payments: in Coretax, mark it as their settlement.', FilingDocuments::info($settling->fresh()));
    }

    public function test_an_empty_selection_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        app(TaxFilingService::class)->export(SalesInvoice::query()->whereRaw('1 = 0')->get(), TaxFiling::CORETAX, 2026, 11);
    }
}
