<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Tax\EFakturCsvWriter;
use App\Domain\Tax\FakturBlocker;
use App\Domain\Tax\FakturExporter;
use App\Domain\Tax\FakturRecord;
use App\Domain\Tax\NsfpRecorder;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Reporting output VAT.
 *
 * There is no API: a person exports a file, uploads it to the tax office, and
 * the serial numbers come back to be written onto our invoices. Most of what
 * can go wrong is at the two ends of that round trip rather than in the
 * middle — a faktur silently left out, or a serial landing on the wrong
 * invoice — so that is what most of these tests are about.
 *
 * **The file format is not settled.** CLAUDE.md specifies the e-Faktur CSV;
 * Coretax may want XML. These tests pin the *mapping* — which figure goes in
 * which field, and where it came from — which is the half that does not change
 * with the answer. The layout assertions are deliberately shallow for the same
 * reason.
 */
class FakturExportTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-FK-1';

    private User $finance;

    private User $sales;

    private Warehouse $gudang;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('xendit.secret_key', '');
        Storage::fake('local');

        $this->gudang = Warehouse::factory()->create();
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->sales = User::factory()->sales()->create();

        Product::factory()->create([
            'kode' => self::SKU, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS',
            'merk' => 'YUHOLI', 'description' => 'Shock Absorber Depan',
        ]);

        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subYear()->toDateString(),
        ]);
        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => self::SKU, 'harga' => 100_000,
        ]);

        $this->stockUp(1_000);
    }

    // ------------------------------------------------------------ the mapping

    public function test_a_faktur_carries_the_figures_the_customer_was_billed(): void
    {
        /*
         * Every number on the faktur comes from the invoice snapshots, not
         * from recomputing tax today. The rate could change; the customer is
         * holding a printed faktur that says what it says.
         */
        $invoice = $this->invoiceFor(10);

        $record = FakturRecord::fromInvoice($invoice);

        $this->assertSame($invoice->nomor, $record->referensi);
        $this->assertSame((int) $invoice->dpp_rupiah, $record->dppRupiah);
        $this->assertSame((int) $invoice->ppn_rupiah, $record->ppnRupiah);
        $this->assertSame('04', $record->kodeTransaksi);
        $this->assertTrue($record->isInternallyConsistent());
    }

    public function test_the_dpp_is_eleven_twelfths_and_the_ppn_is_twelve_percent_of_that(): void
    {
        // 10 × 100,000 = 1,000,000 harga jual. DPP nilai lain = 916,667.
        // PPN at 12% of that = 110,000 — the 11% effective burden.
        $invoice = $this->invoiceFor(10);

        $record = FakturRecord::fromInvoice($invoice);

        $this->assertSame(1_000_000, (int) $invoice->subtotal_rupiah);
        $this->assertSame(916_667, $record->dppRupiah);
        $this->assertSame(110_000, $record->ppnRupiah);
    }

    public function test_the_period_follows_the_invoice_date_not_today(): void
    {
        // A month is nearly always filed during the following one, so reading
        // the export date would put every faktur in the wrong masa pajak.
        $this->travelTo('2026-06-15 09:00:00');
        $invoice = $this->invoiceFor(10);

        $this->travelTo('2026-07-08 09:00:00');
        $record = FakturRecord::fromInvoice($invoice->refresh());

        $this->assertSame(6, $record->masaPajak);
        $this->assertSame(2026, $record->tahunPajak);
        $this->assertSame('2026-06', $record->periode());
    }

    public function test_the_tax_identity_is_the_one_snapshotted_when_the_invoice_was_issued(): void
    {
        /*
         * A customer corrects their NPWP in March. The faktur we filed in
         * January has to keep reporting what it reported — it is a document
         * the tax office already holds, and quietly restating it from today's
         * customer record would make our copy disagree with theirs.
         *
         * The invoice snapshots the identity at issue for exactly this, and
         * the export has to read the snapshot rather than walking the relation
         * back to the company.
         */
        $invoice = $this->invoiceFor(10);
        $company = $invoice->company;

        $company->forceFill([
            'npwp' => '9988776655443322',
            'nama_wajib_pajak' => 'PT Nama Yang Sudah Diganti',
        ])->save();

        $record = FakturRecord::fromInvoice($invoice->refresh());

        $this->assertSame('01.234.567.8-901.000', $record->npwp);
        $this->assertSame('PT Pembeli Sejahtera', $record->namaWajibPajak);
    }

    public function test_a_faktur_with_no_ppn_is_refused_rather_than_filed_as_zero(): void
    {
        /*
         * Almost certainly an invoice raised before the tax snapshot existed,
         * or a data problem. Either way, filing a zero and calling it done
         * reports a sale as carrying no VAT — which is a return the tax office
         * will eventually ask about.
         */
        $invoice = $this->invoiceFor(10);
        $invoice->forceFill(['ppn_rupiah' => 0])->save();

        $blockers = FakturBlocker::forInvoice($invoice->refresh());

        $this->assertNotSame([], $blockers);
        $this->assertContains(
            'Faktur ini tidak punya nilai PPN.',
            array_map(fn (FakturBlocker $b) => $b->alasan, $blockers),
        );
    }

    public function test_a_line_reports_gross_and_discount_that_add_back_to_what_was_billed(): void
    {
        /*
         * Our line stores the total after discount; the faktur wants price
         * times quantity and the discount separately. Rebuilding the gross
         * from a rounded unit price can move it a rupiah or two, so the
         * discount is derived rather than read — a faktur whose own arithmetic
         * does not close is rejected on upload.
         */
        $invoice = $this->invoiceFor(10);
        $line = FakturRecord::fromInvoice($invoice)->lines[0];

        $this->assertSame(
            $line->hargaTotalRupiah - $line->diskonRupiah,
            (int) $invoice->order->lines()->first()->line_total_rupiah,
        );
        $this->assertSame('YUHOLI Shock Absorber Depan', $line->nama);
        $this->assertSame(self::SKU, $line->kode);
    }

    public function test_a_carton_line_still_multiplies_out_correctly(): void
    {
        /*
         * An order line records both units — 6 cartons and 72 pieces — and the
         * stored unit price is per *base* unit. Pairing that price with the
         * ordered quantity gave a gross a twelfth of the real one, so the
         * faktur's own columns contradicted each other and its DPP looked ten
         * times too large beside them.
         *
         * Every piece-quantity test passed through this, because ordering in
         * pieces makes the two quantities the same number.
         */
        $invoice = $this->invoiceFor(6, cartons: true);
        $line = FakturRecord::fromInvoice($invoice)->lines[0];

        $orderLine = $invoice->order->lines()->first();

        $this->assertSame(6, (int) $orderLine->ordered_qty);
        $this->assertSame(60, (int) $orderLine->qty_base);

        $this->assertSame(60, $line->jumlahBarang);
        $this->assertSame(
            $line->hargaSatuanRupiah * $line->jumlahBarang,
            $line->hargaTotalRupiah,
        );
        $this->assertSame(
            (int) $orderLine->line_total_rupiah,
            $line->hargaTotalRupiah - $line->diskonRupiah,
        );
    }

    // ------------------------------------------------------------- the file

    public function test_the_file_opens_with_the_three_row_definitions(): void
    {
        // Part of the format, not decoration — the importer reads them.
        $this->invoiceFor(10);

        $csv = $this->exportedContents();
        $lines = explode("\r\n", $csv);

        $this->assertSame(implode(',', EFakturCsvWriter::COLUMNS_FK), $lines[0]);
        $this->assertSame(implode(',', EFakturCsvWriter::COLUMNS_LT), $lines[1]);
        $this->assertSame(implode(',', EFakturCsvWriter::COLUMNS_OF), $lines[2]);
    }

    public function test_one_faktur_becomes_a_header_a_buyer_and_an_item_row(): void
    {
        $this->invoiceFor(10);

        $rows = $this->dataRows($this->exportedContents());

        $this->assertCount(3, $rows);
        $this->assertSame('FK', $rows[0][0]);
        $this->assertSame('LT', $rows[1][0]);
        $this->assertSame('OF', $rows[2][0]);
    }

    public function test_the_row_type_marker_goes_out_unquoted(): void
    {
        /*
         * The importer matches the row type literally at the start of the
         * line, against the definition rows at the top of the file — which are
         * bare. A quoted `"FK"` is not the same token as the `FK` it was told
         * to look for.
         *
         * Asserted on the raw text rather than through a CSV parser, because a
         * parser strips exactly the quotes in question.
         */
        $this->invoiceFor(10);

        $lines = array_slice(explode("\r\n", trim($this->exportedContents())), 3);

        $this->assertStringStartsWith('FK,', $lines[0]);
        $this->assertStringStartsWith('LT,', $lines[1]);
        $this->assertStringStartsWith('OF,', $lines[2]);
    }

    public function test_the_serial_column_is_left_empty_for_the_tax_office_to_fill(): void
    {
        /*
         * The serial is not ours to choose. Writing our own invoice number
         * here is a common and expensive mistake — it is what the REFERENSI
         * column is for, and that is what comes back beside the assigned
         * number.
         */
        $invoice = $this->invoiceFor(10);

        $header = $this->dataRows($this->exportedContents())[0];

        $nomorFaktur = array_search('NOMOR_FAKTUR', EFakturCsvWriter::COLUMNS_FK, true);
        $referensi = array_search('REFERENSI', EFakturCsvWriter::COLUMNS_FK, true);

        $this->assertSame('', $header[$nomorFaktur]);
        $this->assertSame($invoice->nomor, $header[$referensi]);
    }

    public function test_the_npwp_goes_out_as_digits_whichever_way_it_was_typed(): void
    {
        // People type the dots and dashes from the card, and both the 15 and
        // 16 digit forms are in circulation since the 2024 change.
        $invoice = $this->invoiceFor(10, npwp: '01.234.567.8-901.000');

        $header = $this->dataRows($this->exportedContents())[0];
        $npwp = array_search('NPWP', EFakturCsvWriter::COLUMNS_FK, true);

        $this->assertSame('012345678901000', $header[$npwp]);
        $this->assertSame(15, strlen($header[$npwp]));
    }

    public function test_a_newline_in_an_address_cannot_break_the_row(): void
    {
        /*
         * The tax address is a textarea. A newline mid-field ends the record
         * early and every column after it lands in the wrong place — the
         * importer either rejects the file or accepts a shifted one, and the
         * second is much worse.
         */
        $invoice = $this->invoiceFor(10, alamat: "Jl. Industri No. 5\nKawasan Berikat\nBekasi");

        $rows = $this->dataRows($this->exportedContents());

        $this->assertCount(3, $rows);
        $this->assertStringContainsString(
            'Jl. Industri No. 5 Kawasan Berikat Bekasi',
            implode(',', $rows[1]),
        );
    }

    public function test_the_line_dpp_is_the_nilai_lain_and_not_price_less_discount(): void
    {
        /*
         * Under code 01 those two are the same number and the distinction is
         * invisible. Under 04 they differ by a twelfth, which on this line is
         * Rp 83,333 of tax base. Getting it the wrong way round overstates
         * every faktur we file.
         */
        $this->invoiceFor(10);

        $item = $this->dataRows($this->exportedContents())[2];

        $hargaTotal = array_search('HARGA_TOTAL', EFakturCsvWriter::COLUMNS_OF, true);
        $diskon = array_search('DISKON', EFakturCsvWriter::COLUMNS_OF, true);
        $dpp = array_search('DPP', EFakturCsvWriter::COLUMNS_OF, true);

        $this->assertSame('1000000', $item[$hargaTotal]);
        $this->assertSame('0', $item[$diskon]);
        $this->assertSame('916667', $item[$dpp]);
    }

    // -------------------------------------------------------------- blockers

    public function test_an_invoice_with_no_npwp_is_refused_rather_than_filed_blank(): void
    {
        $invoice = $this->invoiceFor(10, npwp: null);

        $blockers = FakturBlocker::forInvoice($invoice);

        $this->assertCount(1, $blockers);
        $this->assertStringContainsString('NPWP pembeli kosong', $blockers[0]->alasan);
        $this->assertStringContainsString('data pelanggan', $blockers[0]->tindakan);
    }

    public function test_a_malformed_npwp_is_refused(): void
    {
        // A typo here reports our sale against somebody else's tax number.
        $invoice = $this->invoiceFor(10, npwp: '0123456789');

        $blockers = FakturBlocker::forInvoice($invoice);

        $this->assertStringContainsString('15 atau 16 digit', $blockers[0]->alasan);
    }

    public function test_sixteen_digit_npwp_is_accepted(): void
    {
        // The current form. Refusing it would block every customer who has
        // updated since 2024.
        $invoice = $this->invoiceFor(10, npwp: '0012345678901000');

        $this->assertSame([], FakturBlocker::forInvoice($invoice));
    }

    public function test_a_blocked_invoice_is_left_out_and_counted_not_silently_dropped(): void
    {
        /*
         * The whole shape of this feature. An invoice quietly missing from a
         * filing is a sale we collected PPN on and did not report, and nobody
         * notices until the tax office compares our figures with the
         * customer's.
         */
        $ok = $this->invoiceFor(10);
        $bad = $this->invoiceFor(10, npwp: null);

        $preview = app(FakturExporter::class)->preview((int) now()->year, (int) now()->month);

        $this->assertCount(1, $preview->siap);
        $this->assertSame($ok->nomor, $preview->siap[0]->referensi);
        $this->assertSame(1, $preview->jumlahTerhalang());
        $this->assertSame($bad->id, $preview->terhalang[0]->invoice->id);
    }

    public function test_a_voided_invoice_is_not_reported(): void
    {
        // Nothing to report on a sale that was cancelled.
        $invoice = $this->invoiceFor(10);
        $invoice->forceFill(['status' => Invoice::STATUS_VOID])->save();

        $preview = app(FakturExporter::class)->preview((int) now()->year, (int) now()->month);

        $this->assertSame([], $preview->siap);
        $this->assertTrue($preview->isEmpty());
    }

    public function test_an_unpaid_invoice_is_still_reported(): void
    {
        // PPN is due on the sale, not on the money. Waiting for payment would
        // file late.
        $invoice = $this->invoiceFor(10);

        $this->assertSame(Invoice::STATUS_OPEN, $invoice->status);

        $preview = app(FakturExporter::class)->preview((int) now()->year, (int) now()->month);

        $this->assertCount(1, $preview->siap);
    }

    // -------------------------------------------------------------- the filing

    public function test_exporting_records_what_was_filed(): void
    {
        /*
         * Somebody will ask which invoices were reported for this month, and a
         * button that streams a file and forgets cannot answer.
         */
        $invoice = $this->invoiceFor(10);

        $export = app(FakturExporter::class)
            ->export((int) now()->year, (int) now()->month, $this->finance);

        $this->assertSame(1, $export->jumlah_faktur);
        $this->assertSame(110_000, (int) $export->total_ppn_rupiah);
        $this->assertSame(916_667, (int) $export->total_dpp_rupiah);
        $this->assertSame('efaktur_csv', $export->format);
        $this->assertSame($invoice->id, $export->lines()->first()->invoice_id);

        Storage::disk('local')->assertExists($export->file_path);
    }

    public function test_an_exported_invoice_is_not_offered_again(): void
    {
        // Filing the same faktur twice is a correction, not a routine, and it
        // has to be asked for.
        $this->invoiceFor(10);
        $exporter = app(FakturExporter::class);

        $exporter->export((int) now()->year, (int) now()->month, $this->finance);

        $preview = $exporter->preview((int) now()->year, (int) now()->month);

        $this->assertSame([], $preview->siap);
        $this->assertSame(1, $preview->sudahDiekspor);

        $again = $exporter->preview((int) now()->year, (int) now()->month, termasukSudahDiekspor: true);

        $this->assertCount(1, $again->siap);
    }

    public function test_exporting_a_month_with_nothing_ready_is_refused(): void
    {
        $this->invoiceFor(10, npwp: null);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('terhalang');

        app(FakturExporter::class)->export((int) now()->year, (int) now()->month, $this->finance);
    }

    public function test_exporting_an_empty_month_is_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Tidak ada faktur');

        app(FakturExporter::class)->export(2020, 1, $this->finance);
    }

    public function test_the_filing_is_written_to_the_audit_log(): void
    {
        $this->invoiceFor(10);

        $export = app(FakturExporter::class)
            ->export((int) now()->year, (int) now()->month, $this->finance);

        $log = AuditLog::query()->where('action', 'faktur_exported')->sole();

        $this->assertSame($this->finance->id, $log->actor_id);
        $this->assertSame($export->nomor, $log->new_value['nomor']);
        $this->assertSame(1, $log->new_value['jumlah_faktur']);
    }

    #[DataProvider('roles')]
    public function test_who_may_file(Role $role, bool $allowed): void
    {
        $this->invoiceFor(10);
        $actor = User::factory()->role($role)->create();

        if (! $allowed) {
            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('tidak berhak');
        }

        $export = app(FakturExporter::class)
            ->export((int) now()->year, (int) now()->month, $actor);

        $this->assertSame(1, $export->jumlah_faktur);
    }

    public static function roles(): array
    {
        return [
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
            // They issue the invoices behind it, but filing is a statement to
            // the tax office about what was sold.
            'sales' => [Role::Sales, false],
            'gudang' => [Role::Warehouse, false],
        ];
    }

    // ----------------------------------------------------------- coming back

    public function test_a_returned_serial_lands_on_the_invoice(): void
    {
        $invoice = $this->invoiceFor(10);
        $export = app(FakturExporter::class)
            ->export((int) now()->year, (int) now()->month, $this->finance);

        $written = app(NsfpRecorder::class)->record(
            $export, [$invoice->nomor => '0400002512345678'], $this->finance
        );

        $this->assertSame(1, $written);
        $this->assertSame('0400002512345678', $invoice->refresh()->nsfp);
        $this->assertSame('0400002512345678', $export->lines()->first()->nsfp);
        $this->assertSame(0, $export->refresh()->menungguNsfp());
    }

    public function test_the_same_serial_cannot_land_on_two_invoices(): void
    {
        /*
         * Two fakturs under one serial is a discrepancy the tax office finds
         * and we do not, because nothing in our own books looks wrong.
         */
        $first = $this->invoiceFor(10);
        $second = $this->invoiceFor(10);

        $export = app(FakturExporter::class)
            ->export((int) now()->year, (int) now()->month, $this->finance);

        $recorder = app(NsfpRecorder::class);
        $recorder->record($export, [$first->nomor => '0400002512345678'], $this->finance);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sudah tercatat pada faktur');

        $recorder->record($export, [$second->nomor => '0400002512345678'], $this->finance);
    }

    public function test_a_reference_from_another_filing_is_refused(): void
    {
        // Pasting last month's return into this month's export would write
        // numbers onto invoices that were never in it.
        $this->invoiceFor(10);
        $export = app(FakturExporter::class)
            ->export((int) now()->year, (int) now()->month, $this->finance);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak ada di ekspor');

        app(NsfpRecorder::class)->record(
            $export, ['INV-000000-9999' => '0400002512345678'], $this->finance
        );
    }

    public function test_changing_a_serial_already_recorded_is_refused(): void
    {
        // An NSFP is issued once. A different second one means either the
        // paste is wrong or something happened that a person should look at.
        $invoice = $this->invoiceFor(10);
        $export = app(FakturExporter::class)
            ->export((int) now()->year, (int) now()->month, $this->finance);

        $recorder = app(NsfpRecorder::class);
        $recorder->record($export, [$invoice->nomor => '0400002512345678'], $this->finance);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sudah punya nomor seri');

        $recorder->record($export, [$invoice->nomor => '0400002599999999'], $this->finance);
    }

    public function test_pasting_the_same_return_twice_is_harmless(): void
    {
        // It happens, and refusing would send somebody hunting for a problem
        // that is not there.
        $invoice = $this->invoiceFor(10);
        $export = app(FakturExporter::class)
            ->export((int) now()->year, (int) now()->month, $this->finance);

        $recorder = app(NsfpRecorder::class);
        $pairs = [$invoice->nomor => '0400002512345678'];

        $this->assertSame(1, $recorder->record($export, $pairs, $this->finance));
        $this->assertSame(0, $recorder->record($export, $pairs, $this->finance));
    }

    public function test_one_bad_line_records_nothing_at_all(): void
    {
        /*
         * A partial write leaves half a month recorded with no way to tell
         * which half, which is worse than a refusal somebody has to read.
         */
        $first = $this->invoiceFor(10);
        $second = $this->invoiceFor(10);

        $export = app(FakturExporter::class)
            ->export((int) now()->year, (int) now()->month, $this->finance);

        try {
            app(NsfpRecorder::class)->record($export, [
                $first->nomor => '0400002512345678',
                'INV-000000-9999' => '0400002599999999',
            ], $this->finance);
            $this->fail('Expected the bad reference to be refused.');
        } catch (DomainException) {
            // Expected.
        }

        $this->assertNull($first->refresh()->nsfp);
        $this->assertNull($second->refresh()->nsfp);
        $this->assertSame(2, $export->refresh()->menungguNsfp());
    }

    public function test_pasted_text_is_read_whatever_separates_the_columns(): void
    {
        // Tab, comma or semicolon, depending on what it was copied out of.
        $recorder = app(NsfpRecorder::class);

        $expected = ['INV-202608-0001' => '0400002512345678', 'INV-202608-0002' => '0400002512345679'];

        $this->assertSame($expected, $recorder->parse(
            "INV-202608-0001\t0400002512345678\nINV-202608-0002\t0400002512345679"
        ));
        $this->assertSame($expected, $recorder->parse(
            "INV-202608-0001,0400002512345678\r\nINV-202608-0002,0400002512345679"
        ));
        $this->assertSame($expected, $recorder->parse(
            " INV-202608-0001 ; 0400002512345678 \n\n INV-202608-0002;0400002512345679 \n"
        ));
    }

    public function test_pasted_rubbish_is_skipped_rather_than_guessed_at(): void
    {
        $parsed = app(NsfpRecorder::class)->parse("judul saja\nINV-202608-0001,0400002512345678\n\n");

        $this->assertSame(['INV-202608-0001' => '0400002512345678'], $parsed);
    }

    // --- helpers ------------------------------------------------------------

    private function invoiceFor(
        int $qty,
        ?string $npwp = '01.234.567.8-901.000',
        ?string $alamat = 'Jl. Industri No. 5, Bekasi',
        bool $cartons = false,
    ): Invoice {
        $company = Company::factory()->creditLimit(500_000_000)->create([
            'payment_terms_days' => 30,
            'npwp' => $npwp,
            'nama_wajib_pajak' => $npwp === null ? null : 'PT Pembeli Sejahtera',
            'alamat_pajak' => $alamat,
        ]);

        $order = Order::factory()->create([
            'company_id' => $company->id,
            'warehouse_id' => $this->gudang->id,
            'created_by' => $this->sales->id,
        ]);
        $lineFactory = $cartons
            ? OrderLine::factory()->cartons($qty, 10)
            : OrderLine::factory()->qty($qty);

        $lineFactory->create(['order_id' => $order->id, 'sku' => self::SKU, 'urutan' => 1]);

        $machine = app(OrderStateMachine::class);
        $machine->submit($order->refresh(), $this->sales);
        $machine->confirm($order->refresh(), $this->approver());
        $machine->awaitPayment($order->refresh(), $this->sales);

        $this->assertSame(OrderStatus::AwaitingPayment, $order->refresh()->status);

        return $order->refresh()->invoice;
    }

    private function exportedContents(): string
    {
        $exporter = app(FakturExporter::class);

        $export = $exporter->export((int) now()->year, (int) now()->month, $this->finance);

        return $exporter->contents($export) ?? '';
    }

    /**
     * Data rows only, split into fields, with the three definition rows and
     * the trailing blank dropped.
     *
     * @return list<list<string>>
     */
    private function dataRows(string $csv): array
    {
        $rows = [];

        foreach (array_slice(explode("\r\n", trim($csv)), 3) as $line) {
            $rows[] = str_getcsv($line, ',', '"', '\\');
        }

        return $rows;
    }

    private function stockUp(int $qty): void
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'warehouse_id' => $this->gudang->id,
            'created_by' => $this->finance->id,
        ]);
        GoodsReceiptLine::factory()->pieces($qty, 60_000)->create([
            'goods_receipt_id' => $receipt->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);
    }
}
