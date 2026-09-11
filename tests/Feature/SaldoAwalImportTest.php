<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Ledger;
use App\Domain\Import\CsvTemplate;
use App\Domain\Import\SaldoAwalHutangImporter;
use App\Domain\Import\SaldoAwalPiutangImporter;
use App\Domain\Import\TemplateKind;
use App\Domain\Komisi\KomisiSetter;
use App\Domain\Payments\PaymentLedger;
use App\Domain\Purchasing\SupplierLedger;
use App\Domain\Reporting\KomisiReport;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\RekapPpn;
use App\Domain\Tax\FakturExporter;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Balances brought in from the old books.
 *
 * The property everything here comes back to: an opening receivable is a
 * **real debt** and **not a sale**. Real, so it ages, ties to Piutang Usaha
 * and is settled through the normal ledger. Not a sale, so it earns nobody
 * commission, appears in no tax export, and books against Saldo Awal
 * Konversi rather than Penjualan. The payable side mirrors it.
 */
class SaldoAwalImportTest extends TestCase
{
    use RefreshDatabase;

    private const PIUTANG = 'NOMOR,PELANGGAN,TANGGAL,JATUH_TEMPO,SISA,CATATAN';

    private const HUTANG = 'NOMOR,PEMASOK,TANGGAL,JATUH_TEMPO,SISA,CATATAN';

    private User $finance;

    private Company $maju;

    private Supplier $yuholi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-11 09:00:00');

        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->maju = Company::factory()->creditLimit(500_000_000)->create([
            'kode' => 'CV-MAJU', 'nama' => 'CV Maju Jaya', 'payment_terms_days' => 30,
        ]);
        $this->yuholi = Supplier::factory()->create(['kode' => 'PT-YUHOLI', 'nama' => 'PT Yuholi Indonesia', 'payment_terms_days' => 45]);
    }

    private function piutang(array $rows): string
    {
        return self::PIUTANG."\n".implode("\n", $rows)."\n";
    }

    private function hutang(array $rows): string
    {
        return self::HUTANG."\n".implode("\n", $rows)."\n";
    }

    private function ar(): SaldoAwalPiutangImporter
    {
        return app(SaldoAwalPiutangImporter::class);
    }

    private function ap(): SaldoAwalHutangImporter
    {
        return app(SaldoAwalHutangImporter::class);
    }

    // --- receivables ---------------------------------------------------------

    public function test_an_opening_receivable_is_a_real_open_invoice_that_ties_to_the_ledger(): void
    {
        $hasil = $this->ar()->import($this->piutang([
            'INV-LAMA-1,CV-MAJU,2026-08-15,2026-09-14,12.500.000,Sisa cicilan',
            'INV-LAMA-2,cv-maju,15/08/2026,,4.750.000,',
        ]), $this->finance, sumber: 'piutang.csv');

        $this->assertSame(['baru' => 2, 'tertahan' => 0, 'total' => 17_250_000], $hasil);

        $satu = Invoice::query()->withoutGlobalScope('region')->where('nomor', 'INV-LAMA-1')->sole();
        $this->assertSame(Invoice::STATUS_OPEN, $satu->status);
        $this->assertTrue($satu->saldo_awal);
        $this->assertNull($satu->order_id);
        $this->assertSame(12_500_000, $satu->total_rupiah);
        $this->assertSame(0, $satu->ppn_rupiah, 'the tax on this sale was reported by the old books');
        $this->assertSame('2026-08-15', $satu->issued_on->toDateString());
        $this->assertSame('2026-09-14', $satu->due_date->toDateString());
        $this->assertSame($this->maju->region_id, $satu->region_id, 'in the customer\'s own books');
        $this->assertSame(12_500_000, $satu->amountOutstanding());

        // Blank due date: the customer's own terms, and a day-first date read right.
        $dua = Invoice::query()->withoutGlobalScope('region')->where('nomor', 'INV-LAMA-2')->sole();
        $this->assertSame('2026-08-15', $dua->issued_on->toDateString());
        $this->assertSame('2026-09-14', $dua->due_date->toDateString());

        // The journal: Dr Piutang Usaha, Cr Saldo Awal Konversi — not Penjualan.
        $ledger = app(Ledger::class);
        $this->assertSame(17_250_000, $ledger->balanceOf(AccountCode::PIUTANG_USAHA));
        $this->assertSame(17_250_000, $ledger->balanceOf(AccountCode::SALDO_AWAL));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::PENJUALAN));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::PPN_KELUARAN));
        $this->assertSame(2, JournalEntry::query()->withoutGlobalScope('region')->where('jenis', JournalEntry::JENIS_SALDO_AWAL)->count());

        $audit = AuditLog::query()->where('action', 'saldo_awal_piutang_diimpor')->sole();
        $this->assertSame(17_250_000, $audit->new_value['total_rupiah']);
        $this->assertSame('piutang.csv', $audit->new_value['berkas']);
    }

    public function test_an_opening_receivable_ages_and_is_settled_through_the_normal_ledger(): void
    {
        $this->ar()->import($this->piutang(['INV-LAMA-1,CV-MAJU,2026-07-01,2026-07-31,10.000.000,']), $this->finance);

        $invoice = Invoice::query()->withoutGlobalScope('region')->where('nomor', 'INV-LAMA-1')->sole();

        // Past due on the conversion date: it is overdue, like any other.
        $this->assertTrue(Invoice::query()->withoutGlobalScope('region')->overdue()->whereKey($invoice->id)->exists());

        // Paid the way every invoice is paid; nothing special-cased.
        app(PaymentLedger::class)->recordManualPayment($this->maju, 10_000_000, $this->finance, $invoice);

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame(0, $invoice->amountOutstanding());
        $this->assertSame(0, app(Ledger::class)->balanceOf(AccountCode::PIUTANG_USAHA));
    }

    public function test_settling_an_opening_receivable_earns_nobody_commission(): void
    {
        /*
         * The sale was made under the old system, by whoever was paid for it
         * then. Commission here is paid on settlement, and without this
         * exclusion the month of the conversion would pay every seat a
         * commission on years of old debt as it was collected.
         */
        $owner = User::factory()->owner()->create();
        $sales = User::factory()->sales()->create();
        $this->maju->forceFill(['sales_user_id' => $sales->id])->save();
        app(KomisiSetter::class)->setRate($sales, 150, Carbon::parse('2026-01-01'), $owner);

        $this->ar()->import($this->piutang(['INV-LAMA-1,CV-MAJU,2026-08-01,,10.000.000,']), $this->finance);
        $invoice = Invoice::query()->withoutGlobalScope('region')->where('nomor', 'INV-LAMA-1')->sole();
        app(PaymentLedger::class)->recordManualPayment($this->maju, 10_000_000, $this->finance, $invoice);

        $report = app(KomisiReport::class)->build(Period::month('2026-09'));

        $this->assertSame(0, $report->totals['komisi']);
        $this->assertSame([], array_filter($report->rows, fn ($r) => $r['basis'] > 0));
    }

    public function test_an_opening_receivable_is_kept_out_of_the_tax_export_and_rekap_ppn(): void
    {
        $this->ar()->import($this->piutang(['INV-LAMA-1,CV-MAJU,2026-09-01,,10.000.000,']), $this->finance);

        // The tax on it was reported by the old books; reporting it again
        // through Coretax would be a second faktur for one sale.
        $preview = app(FakturExporter::class)->preview(2026, 9);
        $this->assertStringNotContainsString('INV-LAMA-1', json_encode($preview) ?: '');

        $rekap = app(RekapPpn::class)->build(Period::month('2026-09'));
        $this->assertSame(0, (int) ($rekap->totals['keluaran'] ?? $rekap->totals['ppn_keluaran'] ?? 0));
    }

    public function test_the_printed_faktur_still_renders_with_no_order_behind_it(): void
    {
        // A customer asking for a copy of what they owe gets a faktur with the
        // number they know, the amount, and no lines — not a 500.
        $this->ar()->import($this->piutang(['INV-LAMA-1,CV-MAJU,2026-08-15,,12.500.000,']), $this->finance);
        $invoice = Invoice::query()->withoutGlobalScope('region')->where('nomor', 'INV-LAMA-1')->sole();

        $this->actingAs($this->finance)
            ->get(route('dokumen.faktur', $invoice))
            ->assertOk()
            ->assertSee('INV-LAMA-1')
            ->assertSee('12.500.000');
    }

    public function test_the_same_file_twice_writes_nothing_the_second_time(): void
    {
        $file = $this->piutang(['INV-LAMA-1,CV-MAJU,2026-08-15,,12.500.000,']);

        $this->ar()->import($file, $this->finance);
        $again = $this->ar()->import($file, $this->finance);

        $this->assertSame(['baru' => 0, 'tertahan' => 1, 'total' => 0], $again);
        $this->assertSame(1, Invoice::query()->withoutGlobalScope('region')->where('nomor', 'INV-LAMA-1')->count());
        $this->assertSame(12_500_000, app(Ledger::class)->balanceOf(AccountCode::PIUTANG_USAHA));
    }

    public function test_the_reasons_a_receivable_row_is_held_are_specific_enough_to_fix(): void
    {
        $rows = $this->ar()->preview($this->piutang([
            ',CV-MAJU,2026-08-15,,1.000.000,',
            'INV-A,TB-TIDAK-ADA,2026-08-15,,1.000.000,',
            'INV-B,CV-MAJU,,,1.000.000,',
            'INV-C,CV-MAJU,2027-01-01,,1.000.000,',
            'INV-D,CV-MAJU,2026-08-15,31/02/2026,1.000.000,',
            'INV-E,CV-MAJU,2026-08-15,,,',
            'INV-F,CV-MAJU,2026-08-15,,sejuta,',
            'INV-G,CV-MAJU,2026-08-15,,0,',
            'INV-H,CV-MAJU,2026-08-15,,1.000.000,',
            'INV-H,CV-MAJU,2026-08-15,,1.000.000,',
        ]), $this->finance);

        $alasan = array_map(fn ($r) => implode(' | ', $r->alasan), $rows);

        $this->assertStringContainsString('NOMOR kosong', $alasan[0]);
        $this->assertStringContainsString("PELANGGAN 'TB-TIDAK-ADA' tidak dikenal", $alasan[1]);
        $this->assertStringContainsString('TANGGAL kosong', $alasan[2]);
        $this->assertStringContainsString('masa depan', $alasan[3]);
        $this->assertStringContainsString("JATUH_TEMPO '31/02/2026' tidak terbaca", $alasan[4]);
        $this->assertStringContainsString('SISA kosong', $alasan[5]);
        $this->assertStringContainsString("SISA 'sejuta' bukan angka", $alasan[6]);
        $this->assertStringContainsString('lebih dari nol', $alasan[7]);
        $this->assertSame('', $alasan[8]);
        $this->assertStringContainsString('muncul dua kali', $alasan[9]);
    }

    public function test_the_preview_writes_nothing_and_only_finance_or_the_owner_may_import(): void
    {
        $this->ar()->preview($this->piutang(['INV-LAMA-1,CV-MAJU,2026-08-15,,12.500.000,']), $this->finance);
        $this->assertSame(0, Invoice::query()->withoutGlobalScope('region')->count());

        foreach ([Role::Sales, Role::Marketing, Role::Warehouse, Role::Storage] as $role) {
            $actor = User::factory()->create(['role' => $role->value, 'is_active' => true]);

            try {
                $this->ar()->preview($this->piutang(['INV-X,CV-MAJU,2026-08-15,,1,']), $actor);
                $this->fail("{$role->value} should not import opening balances");
            } catch (DomainException $e) {
                $this->assertStringContainsString('Keuangan', $e->getMessage());
            }
        }
    }

    // --- payables --------------------------------------------------------------

    public function test_an_opening_payable_is_a_real_open_bill_that_ties_to_the_ledger(): void
    {
        $hasil = $this->ap()->import($this->hutang([
            'SUP/0912/26,PT-YUHOLI,2026-08-20,2026-09-19,38.000.000,',
            'SUP/0930/26,pt-yuholi,20/08/2026,,2.000.000,',
        ]), $this->finance, sumber: 'hutang.csv');

        $this->assertSame(['baru' => 2, 'tertahan' => 0, 'total' => 40_000_000], $hasil);

        $bill = SupplierBill::query()->withoutGlobalScope('region')->where('nomor_faktur_supplier', 'SUP/0912/26')->sole();
        $this->assertSame(SupplierBill::STATUS_OPEN, $bill->status);
        $this->assertTrue($bill->saldo_awal);
        $this->assertNull($bill->purchase_order_id);
        $this->assertSame(38_000_000, $bill->total_rupiah);
        $this->assertSame(0, $bill->ppn_rupiah);
        $this->assertSame($this->yuholi->region_id, $bill->region_id);

        // Blank due date: the supplier's terms, 45 days.
        $dua = SupplierBill::query()->withoutGlobalScope('region')->where('nomor_faktur_supplier', 'SUP/0930/26')->sole();
        $this->assertSame('2026-10-04', $dua->due_date->toDateString());

        // Dr Saldo Awal Konversi, Cr Utang Usaha — not Persediaan, not PPN Masukan.
        $ledger = app(Ledger::class);
        $this->assertSame(40_000_000, $ledger->balanceOf(AccountCode::UTANG_USAHA));
        $this->assertSame(-40_000_000, $ledger->balanceOf(AccountCode::SALDO_AWAL));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::PERSEDIAAN));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::PPN_MASUKAN));

        $this->assertSame('hutang.csv', AuditLog::query()->where('action', 'saldo_awal_hutang_diimpor')->sole()->new_value['berkas']);
    }

    public function test_an_opening_payable_is_paid_through_the_normal_supplier_ledger(): void
    {
        $this->ap()->import($this->hutang(['SUP/0912/26,PT-YUHOLI,2026-08-20,,38.000.000,']), $this->finance);
        $bill = SupplierBill::query()->withoutGlobalScope('region')->where('nomor_faktur_supplier', 'SUP/0912/26')->sole();

        app(SupplierLedger::class)->recordPayment($this->yuholi, 38_000_000, $this->finance, $bill);

        $this->assertSame(SupplierBill::STATUS_PAID, $bill->refresh()->status);
        $this->assertSame(0, app(Ledger::class)->balanceOf(AccountCode::UTANG_USAHA));
    }

    public function test_both_sides_net_in_the_conversion_account(): void
    {
        // What walked in: 17.25M owed to us, 40M owed by us. The conversion
        // account carries the difference for the accountant to clear at the
        // first year end — nothing in it ever touches the laba rugi.
        $this->ar()->import($this->piutang(['INV-LAMA-1,CV-MAJU,2026-08-15,,17.250.000,']), $this->finance);
        $this->ap()->import($this->hutang(['SUP/0912/26,PT-YUHOLI,2026-08-20,,40.000.000,']), $this->finance);

        $this->assertSame(17_250_000 - 40_000_000, app(Ledger::class)->balanceOf(AccountCode::SALDO_AWAL));
    }

    public function test_the_example_files_are_ones_the_importers_accept(): void
    {
        Company::factory()->create(['kode' => 'TB-JAYA', 'nama' => 'Toko Jaya']);

        foreach ([[TemplateKind::Piutang, $this->ar()], [TemplateKind::Hutang, $this->ap()]] as [$kind, $importer]) {
            $rows = $importer->preview(app(CsvTemplate::class)->toCsv($kind), $this->finance);
            $this->assertNotEmpty($rows);

            foreach ($rows as $row) {
                $this->assertFalse($row->tertahan(), $kind->value.': '.implode(' | ', $row->alasan));
            }
        }
    }
}
