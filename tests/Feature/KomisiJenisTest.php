<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Catalogue\Golongan;
use App\Domain\Komisi\JenisKomisi;
use App\Domain\Komisi\KomisiSetter;
use App\Domain\Payments\PaymentLedger;
use App\Domain\Purchasing\SupplierLedger;
use App\Domain\Regions\RegionContext;
use App\Domain\Reporting\KomisiReport;
use App\Domain\Reporting\Period;
use App\Filament\Pages\KomisiTarget;
use App\Models\CommissionRate;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Region;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The three kinds of commission that are not a seat's own customers.
 *
 * Same rule as the seat kind — paid on money that moved, at the rate in
 * force when it moved — on three different bases: one cabang's collected
 * sales, every cabang's, and settled import purchases. The tests are mostly
 * about the *edges of the basis*: a customer with no seat still counts for
 * the supervisor; another cabang's money does not; a local line on an
 * import bill does not; an unpaid bill does not; an opening balance never.
 */
class KomisiJenisTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $finance;

    private Region $sby;

    private Region $jkt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-15 10:00:00');

        $this->owner = User::factory()->owner()->create();
        $this->finance = User::factory()->finance()->create();
        $this->sby = $this->currentRegion();
        $this->jkt = Region::factory()->create(['kode' => 'JKT', 'nama' => 'Cabang Jakarta']);
    }

    /** An invoice of 11.100.000 with 1.100.000 PPN — base 10.000.000 — settled on a date. */
    private function settledInvoice(Company $company, string $paidOn, int $base = 10_000_000): Invoice
    {
        $invoice = Invoice::factory()->create([
            'company_id' => $company->id,
            'status' => Invoice::STATUS_OPEN,
            'total_rupiah' => (int) ($base * 1.11),
            'ppn_rupiah' => (int) ($base * 0.11),
        ]);

        app(PaymentLedger::class)->recordManualPayment(
            company: $company,
            amountRupiah: (int) $invoice->total_rupiah,
            actor: $this->finance,
            invoice: $invoice,
            paidAt: Carbon::parse($paidOn),
        );

        return $invoice;
    }

    /** Run a closure with another cabang bound, then come back. */
    private function diCabang(Region $region, callable $fn): mixed
    {
        $context = app(RegionContext::class);
        $context->pinTo($region);

        try {
            return $fn();
        } finally {
            $context->pinTo($this->sby);
        }
    }

    private function rowFor(string $peranLabel, ?string $nama = null): ?array
    {
        return collect(app(KomisiReport::class)->build(Period::month('2026-09'))->rows)
            ->first(fn (array $r) => $r['peran'] === $peranLabel && ($nama === null || $r['nama'] === $nama));
    }

    // --- supervisor ------------------------------------------------------------

    public function test_a_supervisor_earns_on_the_whole_cabang_including_customers_with_no_seat(): void
    {
        $sales = User::factory()->sales()->create();
        $spv = User::factory()->sales()->create(['name' => 'Supervisor Surabaya']);

        $setter = app(KomisiSetter::class);
        $setter->setRate($sales, 150, Carbon::parse('2026-01-01'), $this->owner);
        // 0,25% on everything the cabang collects.
        $setter->setRate($spv, 25, Carbon::parse('2026-01-01'), $this->owner, JenisKomisi::Supervisor, $this->sby->id);

        $held = Company::factory()->create(['sales_user_id' => $sales->id]);
        $unheld = Company::factory()->create(['sales_user_id' => null, 'marketing_user_id' => null]);

        $this->settledInvoice($held, '2026-09-05');
        $this->settledInvoice($unheld, '2026-09-08');

        $row = $this->rowFor('Supervisor — '.$this->sby->kode);

        $this->assertNotNull($row, 'the supervisor has a row');
        $this->assertSame(2, $row['faktur']);
        $this->assertSame(20_000_000, $row['basis'], 'both customers, seat or no seat');
        $this->assertSame(50_000, $row['komisi']);

        // The sales seat still earns only on its own customer.
        $seat = $this->rowFor(Role::Sales->label(), $sales->name);
        $this->assertSame(10_000_000, $seat['basis']);
        $this->assertSame(150_000, $seat['komisi']);
    }

    public function test_a_supervisor_does_not_earn_on_another_cabangs_money(): void
    {
        $spv = User::factory()->sales()->create();
        app(KomisiSetter::class)->setRate($spv, 25, Carbon::parse('2026-01-01'), $this->owner, JenisKomisi::Supervisor, $this->sby->id);

        $this->settledInvoice(Company::factory()->create(), '2026-09-05');

        $this->diCabang($this->jkt, fn () => $this->settledInvoice(Company::factory()->create(), '2026-09-06'));

        $row = $this->rowFor('Supervisor — '.$this->sby->kode);

        $this->assertSame(1, $row['faktur']);
        $this->assertSame(10_000_000, $row['basis']);
    }

    // --- manajer -----------------------------------------------------------------

    public function test_a_manajer_earns_on_every_cabang(): void
    {
        $manajer = User::factory()->marketing()->create(['name' => 'Manajer Penjualan']);
        app(KomisiSetter::class)->setRate($manajer, 10, Carbon::parse('2026-01-01'), $this->owner, JenisKomisi::Manajer);

        $this->settledInvoice(Company::factory()->create(), '2026-09-05');
        $this->diCabang($this->jkt, fn () => $this->settledInvoice(Company::factory()->create(), '2026-09-06', 4_000_000));

        $row = $this->rowFor('Manajer');

        $this->assertSame(2, $row['faktur']);
        $this->assertSame(14_000_000, $row['basis']);
        $this->assertSame(14_000, $row['komisi']);
    }

    public function test_the_rate_in_force_on_the_settlement_date_is_the_one_applied(): void
    {
        $manajer = User::factory()->marketing()->create();
        $setter = app(KomisiSetter::class);
        $setter->setRate($manajer, 10, Carbon::parse('2026-01-01'), $this->owner, JenisKomisi::Manajer);
        $setter->setRate($manajer, 20, Carbon::parse('2026-09-10'), $this->owner, JenisKomisi::Manajer);

        $this->settledInvoice(Company::factory()->create(), '2026-09-05'); // 0,10%
        $this->settledInvoice(Company::factory()->create(), '2026-09-12'); // 0,20%

        $this->assertSame(10_000 + 20_000, $this->rowFor('Manajer')['komisi']);
    }

    public function test_a_target_on_a_kind_is_measured_against_that_kinds_basis(): void
    {
        $manajer = User::factory()->marketing()->create(['name' => 'Manajer']);
        $setter = app(KomisiSetter::class);
        $setter->setRate($manajer, 10, Carbon::parse('2026-01-01'), $this->owner, JenisKomisi::Manajer);
        $setter->setTarget($manajer, 2026, 9, 40_000_000, $this->owner, JenisKomisi::Manajer);

        $this->settledInvoice(Company::factory()->create(), '2026-09-05');

        $row = $this->rowFor('Manajer');

        $this->assertSame(40_000_000, $row['target']);
        $this->assertSame(25.0, $row['pencapaian']);

        // A target with nothing settled is a zero row, not an absent one.
        $lain = User::factory()->finance()->create(['name' => 'Manajer Lain']);
        $setter->setTarget($lain, 2026, 9, 5_000_000, $this->owner, JenisKomisi::Manajer);

        $kosong = $this->rowFor('Manajer', 'Manajer Lain');
        $this->assertSame(0, $kosong['basis']);
        $this->assertSame(0.0, $kosong['pencapaian']);
    }

    // --- pembelian impor -----------------------------------------------------------

    public function test_the_import_purchaser_earns_on_settled_import_lines_only(): void
    {
        $pembeli = User::factory()->role(Role::Warehouse)->create(['name' => 'Pembeli Impor']);
        app(KomisiSetter::class)->setRate($pembeli, 50, Carbon::parse('2026-01-01'), $this->owner, JenisKomisi::PembelianImpor);

        Product::factory()->create(['kode' => 'IMP-1', 'golongan' => Golongan::Impor->value]);
        Product::factory()->create(['kode' => 'LOK-1', 'golongan' => Golongan::Lokal->value]);
        Product::factory()->create(['kode' => 'TANPA-1', 'golongan' => null]);

        $supplier = Supplier::factory()->create();

        // One bill: an import line, a local line, an unclassified line.
        $bill = SupplierBill::factory()->totalling(6_000_000)->create(['supplier_id' => $supplier->id]);
        SupplierBillLine::factory()->create(['supplier_bill_id' => $bill->id, 'sku' => 'IMP-1', 'line_total_rupiah' => 3_000_000]);
        SupplierBillLine::factory()->create(['supplier_bill_id' => $bill->id, 'sku' => 'LOK-1', 'line_total_rupiah' => 2_000_000]);
        SupplierBillLine::factory()->create(['supplier_bill_id' => $bill->id, 'sku' => 'TANPA-1', 'line_total_rupiah' => 1_000_000]);

        // A second, all-import bill that is NOT paid: nothing.
        $unpaid = SupplierBill::factory()->totalling(9_000_000)->create(['supplier_id' => $supplier->id]);
        SupplierBillLine::factory()->create(['supplier_bill_id' => $unpaid->id, 'sku' => 'IMP-1', 'line_total_rupiah' => 9_000_000]);

        app(SupplierLedger::class)->recordPayment($supplier, 6_000_000, $this->finance, $bill, paidAt: Carbon::parse('2026-09-09'));

        $row = $this->rowFor('Pembelian impor');

        $this->assertNotNull($row);
        $this->assertSame(1, $row['faktur']);
        $this->assertSame(3_000_000, $row['basis'], 'the import line alone — not lokal, not unclassified, not the unpaid bill');
        $this->assertSame(15_000, $row['komisi']);
    }

    // --- the levers ---------------------------------------------------------------

    public function test_a_supervisor_must_name_a_cabang_and_the_others_must_not(): void
    {
        $setter = app(KomisiSetter::class);
        $someone = User::factory()->sales()->create();

        try {
            $setter->setRate($someone, 25, Carbon::parse('2026-01-01'), $this->owner, JenisKomisi::Supervisor);
            $this->fail('a supervisor of nowhere');
        } catch (DomainException $e) {
            $this->assertStringContainsString('cabang', $e->getMessage());
        }

        try {
            $setter->setRate($someone, 10, Carbon::parse('2026-01-01'), $this->owner, JenisKomisi::Manajer, $this->sby->id);
            $this->fail('a manajer of one cabang');
        } catch (DomainException $e) {
            $this->assertStringContainsString('tidak memakai cabang', $e->getMessage());
        }
    }

    public function test_the_owner_receives_no_commission_and_only_finance_or_the_owner_sets_it(): void
    {
        $setter = app(KomisiSetter::class);

        try {
            $setter->setRate($this->owner, 10, Carbon::parse('2026-01-01'), $this->owner, JenisKomisi::Manajer);
            $this->fail('the owner paid a commission');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Pemilik', $e->getMessage());
        }

        $manajer = User::factory()->marketing()->create();

        // Finance holds the key too (2026-09): the desk that pays the
        // commission out is the desk that knows the percentage.
        $setter->setRate($manajer, 10, Carbon::parse('2026-01-01'), $this->finance, JenisKomisi::Manajer);
        $setter->setTarget(User::factory()->sales()->create(), 2026, 9, 1_000_000, $this->finance);
        $this->assertSame(10, $setter->currentRate($manajer, JenisKomisi::Manajer));

        // The seats that are paid on it never set it.
        foreach ([User::factory()->sales()->create(), User::factory()->marketing()->create()] as $seat) {
            try {
                $setter->setRate($manajer, 20, Carbon::parse('2026-02-01'), $seat, JenisKomisi::Manajer);
                $this->fail($seat->role()->label().' set a rate');
            } catch (DomainException $e) {
                $this->assertStringContainsString('Hanya Keuangan dan Pemilik', $e->getMessage());
            }
        }
    }

    public function test_the_seat_kind_is_still_only_for_sales_and_marketing(): void
    {
        $finance = User::factory()->finance()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('kursi Sales dan Marketing');

        app(KomisiSetter::class)->setRate($finance, 100, Carbon::parse('2026-01-01'), $this->owner);
    }

    public function test_one_person_can_hold_a_seat_rate_and_a_supervisor_rate_at_once(): void
    {
        $spv = User::factory()->sales()->create(['name' => 'Sales merangkap SPV']);
        $setter = app(KomisiSetter::class);
        $setter->setRate($spv, 150, Carbon::parse('2026-01-01'), $this->owner);
        $setter->setRate($spv, 25, Carbon::parse('2026-01-01'), $this->owner, JenisKomisi::Supervisor, $this->sby->id);

        $this->settledInvoice(Company::factory()->create(['sales_user_id' => $spv->id]), '2026-09-05');

        // Two rows, two bases, one person: 1,50% as the seat, 0,25% as the supervisor.
        $this->assertSame(150_000, $this->rowFor(Role::Sales->label(), $spv->name)['komisi']);
        $this->assertSame(25_000, $this->rowFor('Supervisor — '.$this->sby->kode, $spv->name)['komisi']);
        $this->assertSame(150, $setter->currentRate($spv));
        $this->assertSame(25, $setter->currentRate($spv, JenisKomisi::Supervisor, $this->sby->id));
    }

    // --- the Owner's screen ----------------------------------------------------

    public function test_the_owner_gives_a_supervisor_rate_through_the_screen_and_sees_it_listed(): void
    {
        $spv = User::factory()->sales()->create(['name' => 'Andi Wijaya']);

        Livewire::actingAs($this->owner)
            ->test(KomisiTarget::class)
            ->callAction('tambahKomisi', [
                'user_id' => $spv->id,
                'jenis' => JenisKomisi::Supervisor->value,
                'cabang_id' => $this->sby->id,
                'persen' => '0.25',
                'berlaku_mulai' => '2026-09-01',
            ])
            ->assertHasNoActionErrors()
            ->assertSee('Andi Wijaya')
            ->assertSee('Supervisor')
            ->assertSee($this->sby->kode)
            ->assertSee('0,25%');

        $rate = CommissionRate::query()->where('jenis', JenisKomisi::Supervisor->value)->sole();
        $this->assertSame($this->sby->id, (int) $rate->cabang_id);
        $this->assertSame(25, (int) $rate->basis_poin);

        // The rate is the Owner's, not one cabang's books: it must not be a region-scoped row.
        $this->assertFalse(Schema::hasColumn('commission_rates', 'region_id'));
    }

    public function test_the_screen_refuses_a_supervisor_rate_without_a_cabang(): void
    {
        $spv = User::factory()->sales()->create();

        Livewire::actingAs($this->owner)
            ->test(KomisiTarget::class)
            ->callAction('tambahKomisi', [
                'user_id' => $spv->id,
                'jenis' => JenisKomisi::Supervisor->value,
                'persen' => '0.25',
                'berlaku_mulai' => '2026-09-01',
            ])
            ->assertHasActionErrors(['cabang_id']);

        $this->assertSame(0, CommissionRate::query()->count());
    }
}
