<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Banking\BankReconciler;
use App\Domain\Banking\StatementImporter;
use App\Domain\Banking\StatementMatcher;
use App\Domain\Banking\StatementParser;
use App\Domain\Payments\PaymentLedger;
use App\Domain\Purchasing\SupplierLedger;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\PaymentEntry;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The statement file read into the reconciliation, and the matcher that
 * proposes — never decides — what each of its lines is.
 *
 * The dangerous failure here is a confident wrong match: tick the wrong
 * journal line and the reconciliation still balances, while the real
 * discrepancy hides behind it. So the tests that matter are the refusals —
 * two candidates match nothing automatically, a mismatched amount cannot be
 * confirmed, and money recorded off a statement line goes through the same
 * payment ledger door as money typed by hand.
 */
class BankStatementImportTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private Company $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-05 09:00:00');

        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->pelanggan = Company::factory()->creditLimit(500_000_000)->create([
            'nama' => 'CV Sinar Distribusi',
            'status' => Company::STATUS_ACTIVE,
        ]);
    }

    // ------------------------------------------------------------ the parser

    public function test_the_parser_reads_the_shapes_indonesian_banks_export(): void
    {
        // Semicolon-delimited, Indonesian numbers, a preamble above the
        // header, a repeated header mid-file, and a totals footer.
        $csv = implode("\n", [
            'Rekening Giro;;;;',
            'Periode: 01/08/2026 - 31/08/2026;;;;',
            'Tanggal;Uraian;Debit;Kredit;Saldo',
            '05/08/2026;TRF DR CV SINAR;;10.000.000,00;110.000.000,00',
            '12/08/2026;BI-FAST KE PT PEMASOK;2.500.000,00;;107.500.000,00',
            'Tanggal;Uraian;Debit;Kredit;Saldo',
            '31/08/2026;BIAYA ADM;25.000;;107.475.000,00',
            'TOTAL;;2.525.000;10.000.000;',
            '32/08/2026;TANGGAL RUSAK;;5.000;',
        ]);

        $parsed = app(StatementParser::class)->parse($csv);

        $this->assertCount(3, $parsed->rows);
        $this->assertCount(1, $parsed->errors);

        [$masuk, $keluar, $biaya] = $parsed->rows;

        $this->assertSame('2026-08-05', $masuk['tanggal']);
        $this->assertSame('masuk', $masuk['arah']);
        $this->assertSame(10_000_000, $masuk['amount_rupiah']);
        $this->assertSame(110_000_000, $masuk['saldo_rupiah']);

        $this->assertSame('keluar', $keluar['arah']);
        $this->assertSame(2_500_000, $keluar['amount_rupiah']);

        $this->assertSame(25_000, $biaya['amount_rupiah']);

        $this->assertStringContainsString('32/08/2026', $parsed->errors[0]['sebab']);
    }

    public function test_the_parser_reads_a_single_amount_column_with_db_cr_flags(): void
    {
        $csv = implode("\n", [
            'Tanggal,Keterangan,Mutasi,DB/CR',
            '05/08/2026,SETORAN TUNAI,"1,500,000.00",CR',
            '06/08/2026,TARIKAN ATM,"200,000.00",DB',
        ]);

        $parsed = app(StatementParser::class)->parse($csv);

        $this->assertCount(2, $parsed->rows);
        $this->assertSame(['masuk', 1_500_000], [$parsed->rows[0]['arah'], $parsed->rows[0]['amount_rupiah']]);
        $this->assertSame(['keluar', 200_000], [$parsed->rows[1]['arah'], $parsed->rows[1]['amount_rupiah']]);
    }

    // ---------------------------------------------------------- the importer

    public function test_an_import_records_its_lines_and_flags_rows_after_the_closing_date(): void
    {
        $rec = $this->open('2026-08-31', 0);

        $import = $this->import($rec, implode("\n", [
            'Tanggal;Uraian;Debit;Kredit',
            '05/08/2026;TRF DR CV SINAR;;10.000.000',
            '02/09/2026;BULAN DEPAN;;1.000.000',
        ]));

        $this->assertSame(BankStatementImport::STATUS_SELESAI, $import->status);
        $this->assertSame(2, $import->jumlah_baris);
        $this->assertSame(1, $import->jumlah_error);

        $lines = $import->lines;
        $this->assertSame(BankStatementLine::STATUS_BELUM, $lines[0]->status);
        // September on an August statement means the wrong file was exported.
        $this->assertSame(BankStatementLine::STATUS_ERROR, $lines[1]->status);
    }

    public function test_a_headerless_file_leaves_a_failed_import_not_nothing(): void
    {
        $rec = $this->open('2026-08-31', 0);

        $import = $this->import($rec, "ini bukan csv mutasi\nsama sekali");

        $this->assertSame(BankStatementImport::STATUS_GAGAL, $import->status);
        $this->assertStringContainsString('baris judul', (string) $import->catatan);
        $this->assertSame(0, $import->lines()->count());
    }

    // ----------------------------------------------------------- the matcher

    public function test_auto_match_ticks_the_unambiguous_and_leaves_twins_alone(): void
    {
        // Three book entries: one unique amount, and two identical transfers.
        $this->receive(10_000_000, '2026-08-05');
        $this->receive(3_000_000, '2026-08-12');
        $this->receive(3_000_000, '2026-08-12');

        $rec = $this->open('2026-08-31', 16_000_000);

        $import = $this->import($rec, implode("\n", [
            'Tanggal;Uraian;Debit;Kredit',
            '05/08/2026;TRF DR CV SINAR;;10.000.000',
            '12/08/2026;TRF MASUK A;;3.000.000',
            '13/08/2026;TRF MASUK B;;3.000.000',
        ]));

        $matched = app(StatementMatcher::class)->autoMatch($import, $this->finance);

        /*
         * Only the 10jt line is unambiguous on the first pass — but once it
         * is claimed, each twin still sees *two* candidate journal lines, so
         * both stay unmatched. Guessing between identical twins is exactly
         * what the matcher must never do.
         */
        $this->assertSame(1, $matched);

        $statuses = $import->lines()->pluck('status', 'urutan');
        $this->assertSame(BankStatementLine::STATUS_TERCOCOK, $statuses[1]);
        $this->assertSame(BankStatementLine::STATUS_BELUM, $statuses[2]);
        $this->assertSame(BankStatementLine::STATUS_BELUM, $statuses[3]);

        // The tick is real: the reconciler now counts one line as claimed.
        $this->assertCount(1, app(BankReconciler::class)->tickedLineIds($rec));
    }

    public function test_a_confirm_refuses_a_journal_line_that_disagrees_on_amount(): void
    {
        $this->receive(10_000_000, '2026-08-05');

        $rec = $this->open('2026-08-31', 10_000_000);

        $import = $this->import($rec, implode("\n", [
            'Tanggal;Uraian;Debit;Kredit',
            '05/08/2026;TRF SALAH KETIK;;9.999.999',
        ]));

        $line = $import->lines->first();
        $journal = $this->bankLineOfLatestEntry();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/tidak sepakat/');

        app(StatementMatcher::class)->confirm($line, $journal, $this->finance);
    }

    public function test_a_masuk_line_becomes_a_payment_through_the_ledger_and_ticks_itself(): void
    {
        $invoice = Invoice::factory()->create([
            'company_id' => $this->pelanggan->id,
            'status' => Invoice::STATUS_OPEN,
            'total_rupiah' => 7_000_000,
        ]);

        $rec = $this->open('2026-08-31', 7_000_000);

        $import = $this->import($rec, implode("\n", [
            'Tanggal;Uraian;Debit;Kredit',
            '20/08/2026;TRF DR CV SINAR INV;;7.000.000',
        ]));

        $line = $import->lines->first();

        $entry = app(StatementMatcher::class)->recordPayment(
            $line, $this->finance, invoice: $invoice,
        );

        // Through the one door: a real payment entry, on the statement's date.
        $this->assertSame(PaymentEntry::KIND_PAYMENT, $entry->kind);
        $this->assertSame(7_000_000, (int) $entry->amount_rupiah);
        $this->assertSame('2026-08-20', $entry->paid_at->toDateString());
        $this->assertSame(Invoice::STATUS_PAID, $invoice->refresh()->status);

        // And the Bank leg it posted is already ticked and linked back.
        $line->refresh();
        $this->assertSame(BankStatementLine::STATUS_TERCOCOK, $line->status);
        $this->assertSame($entry->id, (int) $line->payment_entry_id);
        $this->assertCount(1, app(BankReconciler::class)->tickedLineIds($rec));

        // Which is enough to finalise: statement and books now agree.
        $summary = app(BankReconciler::class)->summarise($rec);
        $this->assertSame(0, $summary->selisih);
    }

    public function test_a_keluar_line_cannot_be_recorded_as_a_customer_payment(): void
    {
        $this->paySupplier(2_000_000, '2026-08-10');

        $rec = $this->open('2026-08-31', -2_000_000);

        $import = $this->import($rec, implode("\n", [
            'Tanggal;Uraian;Debit;Kredit',
            '10/08/2026;BI-FAST KE PT PEMASOK;2.000.000;',
        ]));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/uang masuk/i');

        app(StatementMatcher::class)->recordPayment(
            $import->lines->first(), $this->finance, company: $this->pelanggan,
        );
    }

    public function test_reset_hands_the_tick_back_but_never_unwinds_a_recorded_payment(): void
    {
        $this->receive(5_000_000, '2026-08-05');

        $rec = $this->open('2026-08-31', 5_000_000);

        $import = $this->import($rec, implode("\n", [
            'Tanggal;Uraian;Debit;Kredit',
            '05/08/2026;TRF DR CV SINAR;;5.000.000',
        ]));

        $matcher = app(StatementMatcher::class);
        $this->assertSame(1, $matcher->autoMatch($import, $this->finance));

        $line = $import->lines()->first();
        $matcher->reset($line, $this->finance);

        $this->assertSame(BankStatementLine::STATUS_BELUM, $line->refresh()->status);
        $this->assertCount(0, app(BankReconciler::class)->tickedLineIds($rec));

        // A line that *recorded* money is different: the payment is real and
        // in the ledger — undoing it is the ledger's reversal flow, not ours.
        $entry = $matcher->recordPayment($line, $this->finance, company: $this->pelanggan);
        $this->assertNotNull($entry);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/pembalikan/');

        $matcher->reset($line->refresh(), $this->finance);
    }

    public function test_only_reconciling_roles_may_import_or_match(): void
    {
        $rec = $this->open('2026-08-31', 0);
        $sales = User::factory()->sales()->create();

        Storage::disk('local')->put('mutasi-bank/uji.csv', "Tanggal;Uraian;Debit;Kredit\n05/08/2026;X;;1.000");

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/tidak berhak/');

        app(StatementImporter::class)->import($rec, 'mutasi-bank/uji.csv', 'uji.csv', $sales);
    }

    // -------------------------------------------------------------- fixtures

    private function open(string $date, int $balance): BankReconciliation
    {
        return app(BankReconciler::class)
            ->open(Carbon::parse($date), $balance, $this->finance);
    }

    private function import(BankReconciliation $rec, string $csv): BankStatementImport
    {
        $path = 'mutasi-bank/'.uniqid().'.csv';
        Storage::disk('local')->put($path, $csv);

        return app(StatementImporter::class)
            ->import($rec, $path, basename($path), $this->finance);
    }

    private function receive(int $amount, string $date): void
    {
        app(PaymentLedger::class)->recordManualPayment(
            company: $this->pelanggan,
            amountRupiah: $amount,
            actor: $this->finance,
            catatan: 'Transfer masuk',
            paidAt: Carbon::parse($date),
        );
    }

    private function paySupplier(int $amount, string $date): void
    {
        $pemasok = Supplier::factory()->create();
        $bill = SupplierBill::factory()->create(['supplier_id' => $pemasok->id]);

        app(SupplierLedger::class)->recordPayment(
            supplier: $pemasok,
            amountRupiah: $amount,
            actor: $this->finance,
            bill: $bill,
            paidAt: Carbon::parse($date),
        );
    }

    private function bankLineOfLatestEntry(): JournalLine
    {
        $bank = Account::byCode(AccountCode::BANK);

        return JournalLine::query()
            ->where('account_id', $bank->id)
            ->orderByDesc('id')
            ->firstOrFail();
    }
}
