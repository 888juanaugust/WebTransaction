<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Tax\FakturExporter;
use App\Filament\Pages\Akuntansi\FakturPajak;
use App\Models\Company;
use App\Models\FakturExport;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The filing screen.
 *
 * Its one job beyond the buttons is to show what would be **left out** before
 * anybody makes a file. Most of these tests are about that, and about the fact
 * that the format is still an open question the person filing has to be told
 * about — neither of which the domain tests can check.
 */
class FakturPajakScreenTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-FP-1';

    private User $finance;

    private User $sales;

    private Warehouse $gudang;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        // The Coretax XML names the seller; a real install has this set in
        // Pengaturan perusahaan before the first filing.
        config(['pajak.penjual.npwp' => '98.765.432.1-012.345']);

        $this->gudang = Warehouse::factory()->create();
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->sales = User::factory()->sales()->create();

        Product::factory()->create(['kode' => self::SKU, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS']);

        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subYear()->toDateString(),
        ]);
        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => self::SKU, 'harga' => 100_000,
        ]);

        $this->stockUp(1_000);
    }

    #[DataProvider('roles')]
    public function test_who_may_open_the_screen(Role $role, bool $allowed): void
    {
        $this->actingAs(User::factory()->role($role)->create());

        $this->assertSame($allowed, FakturPajak::canAccess());
    }

    #[DataProvider('roles')]
    public function test_the_route_refuses_not_just_the_menu(Role $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(FakturPajak::getUrl());

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public static function roles(): array
    {
        return [
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
            'sales' => [Role::Sales, false],
            'gudang' => [Role::Warehouse, false],
        ];
    }

    public function test_the_screen_opens_on_last_month_not_this_one(): void
    {
        // What somebody sits down to file. Offering the current month would
        // invite filing a period still being invoiced into.
        $this->travelTo('2026-08-17 09:00:00');

        Livewire::actingAs($this->finance)
            ->test(FakturPajak::class)
            ->assertOk()
            ->assertSet('periode', '2026-07');
    }

    public function test_the_format_and_the_reference_codes_are_stated_on_the_screen(): void
    {
        /*
         * The person filing is the one who can ask the accountant, and they
         * will not read CoretaxXmlWriter or config/pajak.php. The reference
         * codes — country, goods, units — are the part nobody here can
         * verify, so they are printed where the accountant will see them.
         */
        config(['pajak.coretax.satuan.SET' => 'UM.0099']);

        Livewire::actingAs($this->finance)
            ->test(FakturPajak::class)
            ->assertOk()
            ->assertSee('File XML impor Coretax')
            ->assertSee('coretax_xml')
            ->assertSee('IDN')
            ->assertSee('UM.0099')
            ->assertDontSee('Pastikan dulu formatnya');
    }

    public function test_the_old_csv_layout_is_flagged_when_it_is_still_selected(): void
    {
        config(['pajak.format_ekspor' => 'efaktur_csv']);

        Livewire::actingAs($this->finance)
            ->test(FakturPajak::class)
            ->assertOk()
            ->assertSee('Pastikan dulu formatnya')
            ->assertSee('efaktur_csv');
    }

    public function test_blocked_invoices_are_named_with_the_reason_and_the_fix(): void
    {
        /*
         * The heart of the screen. A count alone would not be enough — the
         * person has to go and fix a customer record, and needs to know which
         * one and what is wrong with it.
         */
        $this->travelTo('2026-07-15 09:00:00');
        $bad = $this->invoiceFor(10, npwp: null);

        $this->travelTo('2026-08-17 09:00:00');

        Livewire::actingAs($this->finance)
            ->test(FakturPajak::class)
            ->assertOk()
            ->assertSee('1 faktur tidak bisa dilaporkan')
            ->assertSee($bad->nomor)
            ->assertSee('NPWP pembeli kosong')
            ->assertSee('Isi NPWP di data pelanggan');
    }

    public function test_one_invoice_failing_several_checks_is_listed_once(): void
    {
        /*
         * An invoice can trip more than one check at once — here a missing
         * NPWP and a faktur carrying no PPN, which is what a broken record
         * from before the tax snapshots existed looks like.
         *
         * Listing each reason as its own row put the same invoice number on
         * screen twice under a heading that said "1 faktur". A system that
         * appears unable to count its own problems is not one anybody trusts
         * about the problems themselves.
         */
        $this->travelTo('2026-07-15 09:00:00');
        $bad = $this->invoiceFor(10, npwp: null);
        $bad->forceFill(['ppn_rupiah' => 0])->save();

        $this->travelTo('2026-08-17 09:00:00');

        $html = Livewire::actingAs($this->finance)
            ->test(FakturPajak::class)
            ->assertOk()
            ->assertSee('1 faktur tidak bisa dilaporkan')
            // Both reasons still shown; they are different problems to fix.
            ->assertSee('NPWP pembeli kosong')
            ->assertSee('tidak punya nilai PPN')
            ->html();

        $this->assertSame(
            1,
            substr_count($html, $bad->nomor),
            'The blocked invoice should be named once, with its reasons under it.',
        );
    }

    public function test_what_is_ready_shows_its_totals(): void
    {
        $this->travelTo('2026-07-15 09:00:00');
        $invoice = $this->invoiceFor(10);

        $this->travelTo('2026-08-17 09:00:00');

        Livewire::actingAs($this->finance)
            ->test(FakturPajak::class)
            ->assertOk()
            ->assertSee($invoice->nomor)
            ->assertSee('Rp 916.667')   // DPP nilai lain
            ->assertSee('Rp 110.000');  // PPN at 12% of it
    }

    public function test_making_a_file_records_the_filing_and_stamps_the_invoices(): void
    {
        $this->travelTo('2026-07-15 09:00:00');
        $invoice = $this->invoiceFor(10);

        $this->travelTo('2026-08-17 09:00:00');

        Livewire::actingAs($this->finance)
            ->test(FakturPajak::class)
            ->callAction('ekspor', ['ulang' => false, 'catatan' => 'Pelaporan rutin'])
            ->assertHasNoActionErrors();

        $export = FakturExport::query()->sole();

        $this->assertSame(7, $export->masa_pajak);
        $this->assertSame(2026, $export->tahun_pajak);
        $this->assertSame('Pelaporan rutin', $export->catatan);
        $this->assertNotNull($invoice->refresh()->faktur_exported_at);
    }

    public function test_a_filing_waiting_for_numbers_says_so(): void
    {
        $this->travelTo('2026-07-15 09:00:00');
        $invoice = $this->invoiceFor(10);

        $this->travelTo('2026-08-17 09:00:00');
        app(FakturExporter::class)->export(2026, 7, $this->finance);

        Livewire::actingAs($this->finance)
            ->test(FakturPajak::class)
            ->assertOk()
            ->assertSee('1 menunggu');
    }

    public function test_the_download_hands_back_the_file_that_was_written(): void
    {
        /*
         * Read from disk rather than regenerated. Somebody will need the file
         * that was actually uploaded months later, and regenerating it then
         * would use whatever the code does by then.
         */
        $this->travelTo('2026-07-15 09:00:00');
        $this->invoiceFor(10);

        $this->travelTo('2026-08-17 09:00:00');
        $exporter = app(FakturExporter::class);
        $export = $exporter->export(2026, 7, $this->finance);

        $response = $this->actingAs($this->finance, 'web')
            ->get(route('faktur-pajak.unduh', $export))
            ->assertOk();

        $this->assertSame($exporter->contents($export), $response->streamedContent());
    }

    #[DataProvider('roles')]
    public function test_the_download_refuses_the_roles_that_may_not_file(Role $role, bool $allowed): void
    {
        $this->travelTo('2026-07-15 09:00:00');
        $this->invoiceFor(10);

        $this->travelTo('2026-08-17 09:00:00');
        $export = app(FakturExporter::class)->export(2026, 7, $this->finance);

        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(route('faktur-pajak.unduh', $export));

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public function test_a_filing_whose_file_is_gone_says_so_rather_than_serving_nothing(): void
    {
        $this->travelTo('2026-07-15 09:00:00');
        $this->invoiceFor(10);

        $this->travelTo('2026-08-17 09:00:00');
        $export = app(FakturExporter::class)->export(2026, 7, $this->finance);

        Storage::disk('local')->delete($export->file_path);

        $this->actingAs($this->finance, 'web')
            ->get(route('faktur-pajak.unduh', $export))
            ->assertNotFound();
    }

    // --- helpers ------------------------------------------------------------

    private function invoiceFor(int $qty, ?string $npwp = '01.234.567.8-901.000'): Invoice
    {
        $company = Company::factory()->creditLimit(500_000_000)->create([
            'payment_terms_days' => 30,
            'npwp' => $npwp,
            'nama_wajib_pajak' => $npwp === null ? null : 'PT Pembeli Sejahtera',
            'alamat_pajak' => 'Jl. Industri No. 5, Bekasi',
        ]);

        $order = Order::factory()->create([
            'company_id' => $company->id,
            'warehouse_id' => $this->gudang->id,
            'created_by' => $this->sales->id,
        ]);
        OrderLine::factory()->qty($qty)->create([
            'order_id' => $order->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);

        $machine = app(OrderStateMachine::class);
        $machine->submit($order->refresh(), $this->sales);
        $machine->confirm($order->refresh(), $this->approver());
        $machine->awaitPayment($order->refresh(), $this->sales);

        return $order->refresh()->invoice;
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
