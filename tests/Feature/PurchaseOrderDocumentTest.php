<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Purchasing\PurchaseOrderFlow;
use App\Domain\Terbilang;
use App\Domain\Uom\Unit;
use App\Models\Company;
use App\Models\CustomerUser;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The purchase order as the supplier receives it.
 *
 * The third print document, and the first that travels *outward*: the surat
 * jalan goes with our goods and the faktur goes to our customer, but this one
 * lands in somebody else's inbox and asks them to do something. Until it
 * existed, "Kirim ke pemasok" moved a status in our database and sent nothing.
 *
 * Two things on it are load-bearing rather than decorative, and both are
 * asserted below: the instruction to quote our PO number on their paperwork —
 * which is what makes the three-way match possible when goods arrive — and the
 * statement that prices exclude PPN.
 */
class PurchaseOrderDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-PO-1';

    private Supplier $supplier;

    private Warehouse $warehouse;

    private User $finance;

    private PurchaseOrder $po;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::factory()->role(Role::Finance)->create(['name' => 'Rina Keuangan']);

        $this->supplier = Supplier::factory()->create([
            'nama' => 'PT Pemasok Sejahtera',
            'nama_kontak' => 'Pak Andi',
            'telepon' => '+62 21 5550101',
            'alamat' => 'Jl. Industri No. 8, Bekasi',
            'payment_terms_days' => 45,
        ]);

        $this->warehouse = Warehouse::factory()->create([
            'nama' => 'Gudang Cakung',
            'alamat' => 'Jl. Raya Cakung No. 12, Jakarta Timur',
        ]);

        Product::factory()->create([
            'kode' => self::SKU, 'qty_per_ctn' => 12, 'satuan_dasar' => 'PCS',
            'merk' => 'YUHOLI', 'description' => 'Master rem depan', 'part_number' => 'MC-1001',
        ]);

        $this->po = PurchaseOrder::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->finance->id,
            'tanggal_diharapkan' => now()->addDays(7)->toDateString(),
            'referensi_supplier' => 'QUO-2026-441',
        ]);

        PurchaseOrderLine::factory()->create([
            'purchase_order_id' => $this->po->id,
            'sku' => self::SKU,
            'ordered_unit' => Unit::Ctn,
            'ordered_qty' => 10,
            'qty_per_ctn_snapshot' => 12,
            'satuan_dasar_snapshot' => 'PCS',
            'qty_base' => 120,
            'unit_cost_rupiah' => 120_000,
            'line_value_rupiah' => 1_200_000,
        ]);

        $this->po->refresh();
    }

    private function url(?PurchaseOrder $po = null): string
    {
        return route('dokumen.pesanan-pembelian', $po ?? $this->po);
    }

    private function send(): PurchaseOrder
    {
        return app(PurchaseOrderFlow::class)->send($this->po, $this->finance);
    }

    // --- the document -------------------------------------------------------

    public function test_a_sent_order_prints_with_both_parties_and_the_lines(): void
    {
        $this->send();

        $this->actingAs($this->finance)->get($this->url())
            ->assertOk()
            ->assertSee('Pesanan Pembelian')
            ->assertSee($this->po->nomor)
            // Who it is going to, and who they should ask for.
            ->assertSee('PT Pemasok Sejahtera')
            ->assertSee('Pak Andi')
            ->assertSee('Jl. Industri No. 8, Bekasi')
            // Where the goods go — the warehouse's own address, not ours.
            ->assertSee('Gudang Cakung')
            ->assertSee('Jl. Raya Cakung No. 12, Jakarta Timur')
            ->assertSee(self::SKU)
            ->assertSee('Master rem depan')
            ->assertSee('MC-1001')
            // Their own reference, so they can find the quote it came from.
            ->assertSee('QUO-2026-441');
    }

    /**
     * The supplier ships cartons; the ledger counts pieces. Printing only one
     * of them is how a delivery arrives ten times too small.
     */
    public function test_quantities_are_shown_in_both_the_ordered_unit_and_base_units(): void
    {
        $this->send();

        $html = $this->actingAs($this->finance)->get($this->url())->assertOk()->getContent();

        $this->assertStringContainsString('10 DUS', $html, 'what the supplier ships');
        $this->assertStringContainsString('120', $html, 'and what that comes to in pieces');
        $this->assertStringContainsString('PCS', $html);
    }

    public function test_the_total_is_summed_from_the_lines_and_written_in_words(): void
    {
        $this->send();

        $this->actingAs($this->finance)->get($this->url())
            ->assertOk()
            ->assertSee('1.200.000')
            ->assertSee('Terbilang')
            ->assertSee(Terbilang::rupiah(1_200_000));
    }

    /**
     * The line that makes the three-way match possible.
     *
     * Without our number on their surat jalan and faktur, somebody has to guess
     * which order a delivery belongs to — and guessing is how a delivery gets
     * matched against the wrong one.
     */
    public function test_the_supplier_is_asked_to_quote_the_po_number_on_their_paperwork(): void
    {
        $this->send();

        $response = $this->actingAs($this->finance)->get($this->url())->assertOk();

        $response->assertSee('Cantumkan nomor pesanan');
        $response->assertSee('surat jalan dan');

        // And the number itself appears in that instruction, not just in the
        // header — the supplier's data-entry clerk reads the instruction.
        $html = $response->getContent();
        $instruction = substr($html, (int) strpos($html, 'Cantumkan nomor pesanan'), 300);
        $this->assertStringContainsString($this->po->nomor, $instruction);
    }

    /**
     * A supplier who reads the total as VAT-inclusive invoices for 11% less
     * than we agreed, and it is only found at the match.
     */
    public function test_the_document_says_prices_exclude_ppn(): void
    {
        $this->send();

        $this->actingAs($this->finance)->get($this->url())
            ->assertOk()
            ->assertSee('belum termasuk PPN');
    }

    public function test_it_carries_the_payment_terms_and_the_expected_date(): void
    {
        $this->send();

        $this->actingAs($this->finance)->get($this->url())
            ->assertOk()
            ->assertSee('45 hari')
            ->assertSee($this->po->tanggal_diharapkan->format('d/m/Y'));
    }

    /** The name that signs it is whoever actually sent it. */
    public function test_the_sender_signs_the_order(): void
    {
        $this->send();

        $this->actingAs($this->finance)->get($this->url())
            ->assertOk()
            ->assertSee('Dipesan oleh')
            ->assertSee('Rina Keuangan');
    }

    // --- status ------------------------------------------------------------

    /**
     * A draft is not an order: nothing has been agreed, the lines are still
     * being edited, and the total has not been fixed.
     */
    public function test_a_draft_cannot_be_printed(): void
    {
        $this->actingAs($this->finance)->get($this->url())
            ->assertForbidden();
    }

    /**
     * A closed or cancelled order can still be printed — for the file, or to
     * confirm a cancellation — but it must never read as a live order.
     */
    public function test_a_cancelled_order_prints_with_its_status_stamped_on_it(): void
    {
        $this->send();
        app(PurchaseOrderFlow::class)->cancel($this->po->refresh(), $this->finance, 'stok habis');

        $this->actingAs($this->finance)->get($this->url())
            ->assertOk()
            ->assertSee('Dibatalkan');
    }

    public function test_a_live_order_carries_no_status_stamp(): void
    {
        $this->send();

        $this->actingAs($this->finance)->get($this->url())
            ->assertOk()
            ->assertDontSee('Dibatalkan')
            ->assertDontSee('Selesai');
    }

    // --- who may print it ---------------------------------------------------

    /** @return list<array{0: Role, 1: bool}> */
    public static function printers(): array
    {
        return [
            'finance' => [Role::Finance, true],
            'owner' => [Role::Owner, true],
            // The document carries what we pay, and cost plus selling price is
            // margin. Same rule as every other purchasing screen.
            'sales' => [Role::Sales, false],
            'warehouse' => [Role::Warehouse, false],
        ];
    }

    #[DataProvider('printers')]
    public function test_only_purchasing_roles_may_print_a_purchase_order(Role $role, bool $allowed): void
    {
        $this->send();

        $response = $this->actingAs(User::factory()->role($role)->create())->get($this->url());

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_staff_login(): void
    {
        $this->send();

        $this->get($this->url())->assertRedirect(route('filament.admin.auth.login'));
    }

    /** Named guard: a buyer session must not satisfy a staff document route. */
    public function test_a_buyer_session_does_not_satisfy_the_staff_route(): void
    {
        $this->send();

        $buyer = CustomerUser::factory()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        $this->actingAs($buyer, 'customer')
            ->get($this->url())
            ->assertRedirect(route('filament.admin.auth.login'));
    }
}
