<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Purchasing\PurchaseOrderStatus;
use App\Domain\Purchasing\SuggestedPurchaseOrder;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\ReorderAdvisor;
use App\Domain\Uom\Unit;
use App\Filament\Pages\Gudang\TitikPesanUlang;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReorderScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private Warehouse $gudang;

    private Supplier $pemasok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-15 09:00:00');

        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->gudang = Warehouse::query()->firstOrCreate(
            ['kode' => 'GD-PUSAT'],
            ['nama' => 'Gudang Pusat', 'aktif' => true],
        );
        $this->pemasok = Supplier::factory()->create(['nama' => 'PT Anugerah Sparepart']);
    }

    #[DataProvider('roles')]
    public function test_who_may_open_the_screen(Role $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(TitikPesanUlang::getUrl());

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public static function roles(): array
    {
        return [
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
            /*
             * Everything on this screen is a buying decision. Warehouse count
             * stock; they do not commit the business to spending, and the
             * quantities here lead straight into a purchase order.
             */
            'gudang' => [Role::Warehouse, false],
            'sales' => [Role::Sales, false],
        ];
    }

    public function test_it_groups_by_the_supplier_we_last_bought_from(): void
    {
        $lain = Supplier::factory()->create(['nama' => 'PT Sumber Lain']);

        $this->short('A-01', $this->pemasok);
        $this->short('A-02', $this->pemasok);
        $this->short('B-01', $lain);

        $this->actingAs($this->finance);
        $groups = app(TitikPesanUlang::class)->bySupplier();

        $this->assertCount(2, $groups);
        $this->assertCount(2, $groups["pemasok-{$this->pemasok->id}"]['rows']);
        $this->assertCount(1, $groups["pemasok-{$lain->id}"]['rows']);
    }

    public function test_parts_nobody_has_ever_supplied_go_last(): void
    {
        // They still run out, but nothing on this screen can decide who to
        // ring, and they must not sit above orders that can be placed now.
        $this->short('NOBODY-01', null);
        $this->short('A-01', $this->pemasok);

        $this->actingAs($this->finance);

        $this->assertSame(
            ["pemasok-{$this->pemasok->id}", 'tanpa-pemasok'],
            array_keys(app(TitikPesanUlang::class)->bySupplier()),
        );
    }

    public function test_the_screen_counts_what_is_actually_out_of_stock(): void
    {
        $this->short('HABIS-01', $this->pemasok, onHand: 0);
        $this->short('KURANG-01', $this->pemasok, onHand: 20);

        $this->actingAs($this->finance);

        $this->assertSame(1, app(TitikPesanUlang::class)->habisCount());
    }

    public function test_a_draft_po_carries_the_suggested_quantities_in_cartons(): void
    {
        $this->short('A-01', $this->pemasok, onHand: 30, perCarton: 12);

        $po = app(SuggestedPurchaseOrder::class)->draftFor(
            supplier: $this->pemasok,
            warehouse: $this->gudang,
            actor: $this->finance,
        );

        $line = $po->lines()->sole();

        $this->assertSame(PurchaseOrderStatus::Draft, $po->status);
        $this->assertSame(Unit::Ctn, $line->ordered_unit);
        $this->assertSame(12, (int) $line->qty_per_ctn_snapshot);
        $this->assertSame((int) $line->ordered_qty * 12, (int) $line->qty_base);
    }

    public function test_making_the_draft_takes_those_parts_off_the_list(): void
    {
        /*
         * The whole point of a draft counting as stock on order. Without it,
         * two people on the same morning raise two orders for one shortage.
         */
        $this->short('A-01', $this->pemasok);

        $this->assertCount(1, app(ReorderAdvisor::class)->suggestions());

        app(SuggestedPurchaseOrder::class)->draftFor(
            $this->pemasok, $this->gudang, $this->finance,
        );

        $this->assertSame([], app(ReorderAdvisor::class)->suggestions());
    }

    public function test_it_prices_lines_from_the_last_delivery(): void
    {
        $this->short('A-01', $this->pemasok, perCarton: 10);
        // Rp 1.000.000 for 10 base units, so Rp 100.000 each, Rp 1.000.000 a carton.
        $this->receivedAt('A-01', qtyBase: 10, lineValue: 1_000_000);

        $po = app(SuggestedPurchaseOrder::class)->draftFor(
            $this->pemasok, $this->gudang, $this->finance,
        );

        $this->assertSame(1_000_000, (int) $po->lines()->sole()->unit_cost_rupiah);
    }

    public function test_a_part_with_no_price_on_record_says_so_on_the_line(): void
    {
        /*
         * Reachable through stock received before the costing layer existed,
         * or a free replacement delivery. A silent zero on a purchase order is
         * a line somebody sends without noticing, and then argues about when
         * the invoice arrives.
         */
        $this->short('A-01', $this->pemasok, priced: false);

        $po = app(SuggestedPurchaseOrder::class)->draftFor(
            $this->pemasok, $this->gudang, $this->finance,
        );

        $line = $po->lines()->sole();

        $this->assertSame(0, (int) $line->unit_cost_rupiah);
        $this->assertStringContainsString('Harga belum pernah tercatat', (string) $line->catatan);
    }

    public function test_a_supplier_with_nothing_short_is_refused_rather_than_given_an_empty_po(): void
    {
        // An empty purchase order is a document that means nothing and still
        // takes a number out of the sequence.
        $this->expectException(DomainException::class);

        app(SuggestedPurchaseOrder::class)->draftFor(
            $this->pemasok, $this->gudang, $this->finance,
        );
    }

    #[DataProvider('rolesWhoMayNotBuy')]
    public function test_only_purchasing_roles_may_raise_the_draft(Role $role): void
    {
        $this->short('A-01', $this->pemasok);

        $this->expectException(DomainException::class);

        app(SuggestedPurchaseOrder::class)->draftFor(
            $this->pemasok, $this->gudang, User::factory()->role($role)->create(),
        );
    }

    public static function rolesWhoMayNotBuy(): array
    {
        return ['sales' => [Role::Sales], 'gudang' => [Role::Warehouse]];
    }

    public function test_raising_the_draft_from_the_screen_says_it_succeeded(): void
    {
        /*
         * Asserting the *success* notification, not just the row. The first
         * version of this screen created the purchase order perfectly and then
         * announced "tidak bisa dibuat", because the notification it built
         * referenced a class that does not exist and the try/catch around the
         * whole block reported that as a domain refusal. Everything the
         * database was asked about looked right.
         */
        $this->short('A-01', $this->pemasok);

        Livewire::actingAs($this->finance)
            ->test(TitikPesanUlang::class)
            ->callAction('buatDraft', ['warehouse_id' => $this->gudang->id], [
                'supplier' => $this->pemasok->id,
            ])
            ->assertHasNoActionErrors();

        $po = PurchaseOrder::query()->sole();

        // By title, not just "a notification was sent" — the broken version
        // sent one too, and it said the opposite.
        Notification::assertNotified("Draf {$po->nomor} dibuat");

        $this->assertSame(1, $po->lines()->count());
        $this->assertSame(PurchaseOrderStatus::Draft, $po->status);
    }

    public function test_raising_the_draft_from_the_screen_reports_a_refusal(): void
    {
        // Nothing short for this supplier: the action must survive the refusal
        // as a notification rather than a 500.
        Livewire::actingAs($this->finance)
            ->test(TitikPesanUlang::class)
            ->callAction('buatDraft', ['warehouse_id' => $this->gudang->id], [
                'supplier' => $this->pemasok->id,
            ]);

        $this->assertSame(0, PurchaseOrder::query()->count());
    }

    public function test_setting_a_manual_point_from_the_screen_sticks(): void
    {
        Product::factory()->create(['kode' => 'A-01', 'description' => 'Barang A']);

        Livewire::actingAs($this->finance)
            ->test(TitikPesanUlang::class)
            ->callAction('atur', [
                'sku' => 'A-01',
                'titik_pesan_ulang_manual' => 250,
                'jangan_pesan_ulang' => false,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(250, (int) Product::query()->find('A-01')->titik_pesan_ulang_manual);
    }

    public function test_clearing_the_manual_point_returns_to_the_arithmetic(): void
    {
        Product::factory()->create([
            'kode' => 'A-01',
            'description' => 'Barang A',
            'titik_pesan_ulang_manual' => 250,
        ]);

        Livewire::actingAs($this->finance)
            ->test(TitikPesanUlang::class)
            ->callAction('atur', [
                'sku' => 'A-01',
                'titik_pesan_ulang_manual' => null,
                'jangan_pesan_ulang' => false,
            ]);

        $this->assertNull(Product::query()->find('A-01')->titik_pesan_ulang_manual);
    }

    // --- helpers -------------------------------------------------------------

    /** A part selling one a day with only thirty left. */
    private function short(
        string $sku,
        ?Supplier $supplier,
        int $onHand = 30,
        int $perCarton = 10,
        bool $priced = true,
    ): void {
        Product::factory()->create([
            'kode' => $sku,
            'description' => "Barang {$sku}",
            'qty_per_ctn' => $perCarton,
        ]);

        DB::table('stock_movements')->insert([
            'region_id' => $this->currentRegion()->id,
            'sku' => $sku,
            'warehouse_id' => $this->gudang->id,
            'qty_signed' => -365,
            'reason' => MovementReason::Pengiriman->value,
            'reference_type' => 'order',
            'reference_id' => 1,
            'created_at' => Carbon::now()->subDays(180),
        ]);

        DB::table('stock_levels')->updateOrInsert(
            ['sku' => $sku, 'warehouse_id' => $this->gudang->id],
            ['qty_on_hand' => $onHand, 'qty_reserved' => 0, 'region_id' => $this->currentRegion()->id],
        );

        if ($supplier !== null) {
            $this->receipt($sku, $supplier, 1, $priced ? 1000 : 0);
        }
    }

    private function receivedAt(string $sku, int $qtyBase, int $lineValue): void
    {
        $this->receipt($sku, $this->pemasok, $qtyBase, $lineValue);
    }

    private function receipt(string $sku, Supplier $supplier, int $qtyBase, int $lineValue): void
    {
        $at = Carbon::now()->subDays(30);

        $id = DB::table('goods_receipts')->insertGetId([
            'region_id' => $this->currentRegion()->id,
            'nomor' => 'TB-'.fake()->unique()->numerify('######'),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->gudang->id,
            'status' => 'posted',
            'tanggal_terima' => $at->toDateString(),
            'total_value_rupiah' => $lineValue,
            'created_by' => $this->finance->id,
            'posted_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        DB::table('goods_receipt_lines')->insert([
            'goods_receipt_id' => $id,
            'sku' => $sku,
            'urutan' => 1,
            'ordered_unit' => 'PCS',
            'ordered_qty' => max(1, $qtyBase),
            'qty_per_ctn_snapshot' => 10,
            'satuan_dasar_snapshot' => 'PCS',
            'qty_base' => $qtyBase,
            'unit_cost_rupiah' => $qtyBase > 0 ? (int) ($lineValue / $qtyBase) : 0,
            'line_value_rupiah' => $lineValue,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
