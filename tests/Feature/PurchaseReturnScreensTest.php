<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Purchasing\PurchaseReturnIssuer;
use App\Domain\Purchasing\PurchaseReturnPoster;
use App\Domain\Purchasing\SupplierBillPoster;
use App\Filament\Resources\PurchaseReturns\Pages\EditPurchaseReturn;
use App\Filament\Resources\PurchaseReturns\Pages\ListPurchaseReturns;
use App\Filament\Resources\PurchaseReturns\PurchaseReturnResource;
use App\Filament\Resources\PurchaseReturns\Schemas\PurchaseReturnForm;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The purchase return screen, and who is shown it.
 *
 * Every line of this document carries what we paid, so it is a Finance and
 * Owner screen and the route has to say so rather than only the menu. The
 * nota retur that prints from it is the same: it travels to a supplier, but
 * only after somebody entitled to see purchase cost has decided to send it.
 */
class PurchaseReturnScreensTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-RP-S1';

    private Warehouse $gudang;

    private User $finance;

    private Supplier $pemasok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::factory()->create(['nama' => 'Gudang Pusat']);
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->pemasok = Supplier::factory()->create(['nama' => 'PT Pemasok Barang']);

        Product::factory()->create([
            'kode' => self::SKU, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS',
            'description' => 'Master rem depan',
        ]);
    }

    #[DataProvider('roles')]
    public function test_who_may_open_the_screen(Role $role, bool $allowed): void
    {
        $this->actingAs(User::factory()->role($role)->create());

        $this->assertSame($allowed, PurchaseReturnResource::canViewAny());
    }

    #[DataProvider('roles')]
    public function test_the_route_refuses_not_just_the_menu(Role $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(PurchaseReturnResource::getUrl('index'));

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    #[DataProvider('roles')]
    public function test_who_may_print_the_nota_retur(Role $role, bool $allowed): void
    {
        $return = $this->postedReturn(30);

        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(route('dokumen.retur-pembelian', $return));

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

    public function test_a_return_is_drawn_from_a_receipt_with_everything_on_it(): void
    {
        /*
         * There is no create form on purpose: every line has to point at
         * something that actually arrived and carry the cost it arrived at.
         * The draw-up starts from everything, so forgetting to delete a line
         * fails in the direction the supplier will tell you about.
         */
        $receipt = $this->postedReceipt([[self::SKU, 100, 60_000]]);

        $this->actingAs($this->finance);
        $this->assertFalse(PurchaseReturnResource::canCreate());

        Livewire::actingAs($this->finance)
            ->test(ListPurchaseReturns::class)
            ->callAction('buat', [
                'goods_receipt_id' => $receipt->id,
                'tanggal' => now()->toDateString(),
                'alasan' => 'Salah tipe, dikonfirmasi ke pemasok',
            ])
            ->assertHasNoActionErrors();

        $return = PurchaseReturn::query()->sole();

        $this->assertFalse($return->isPosted());
        $this->assertSame(1, $return->lines()->count());
        $this->assertSame(100, $return->qtyReturned());
        $this->assertSame('Salah tipe, dikonfirmasi ke pemasok', $return->alasan);
        // Both come off the receipt rather than being asked for.
        $this->assertSame($this->pemasok->id, $return->supplier_id);
        $this->assertSame($this->gudang->id, $return->warehouse_id);
    }

    public function test_a_fully_returned_receipt_is_no_longer_offered(): void
    {
        // The dropdown is the worklist. A delivery with nothing left on it
        // appearing in it invites somebody to return it twice and be refused.
        $receipt = $this->postedReceipt([[self::SKU, 100, 60_000]]);

        $this->assertArrayHasKey(
            $receipt->id,
            PurchaseReturnForm::receiptOptions(),
        );

        $this->postReturn($receipt, 100);

        $this->assertArrayNotHasKey(
            $receipt->id,
            PurchaseReturnForm::receiptOptions(),
        );
    }

    public function test_a_draft_receipt_is_never_offered(): void
    {
        GoodsReceipt::factory()->create([
            'supplier_id' => $this->pemasok->id,
            'warehouse_id' => $this->gudang->id,
            'created_by' => $this->finance->id,
        ]);

        $this->assertSame(
            [],
            PurchaseReturnForm::receiptOptions(),
        );
    }

    public function test_a_return_the_supplier_has_not_acknowledged_shows_on_the_sidebar(): void
    {
        /*
         * The gap between our books saying a supplier owes us and the supplier
         * agreeing is money, and nothing else in the system watches it.
         */
        $this->actingAs($this->finance);

        $this->assertNull(PurchaseReturnResource::getNavigationBadge());

        $return = $this->postedReturn(30);

        $this->assertSame('1', PurchaseReturnResource::getNavigationBadge());

        $return->forceFill(['nomor_nota_kredit_supplier' => 'CN/2026/0091'])->save();

        $this->assertNull(PurchaseReturnResource::getNavigationBadge());
    }

    public function test_a_draft_does_not_count_as_waiting_for_acknowledgement(): void
    {
        // Nothing has gone back yet, so there is nothing for anybody to
        // acknowledge. Counting drafts would make the badge a to-do list of
        // documents rather than of money.
        $this->actingAs($this->finance);

        $receipt = $this->postedReceipt([[self::SKU, 100, 60_000]]);
        app(PurchaseReturnIssuer::class)->draftEverything($receipt, $this->finance, 'Salah kirim');

        $this->assertNull(PurchaseReturnResource::getNavigationBadge());
    }

    public function test_a_posted_return_can_no_longer_be_edited_or_deleted(): void
    {
        $this->actingAs($this->finance);

        $receipt = $this->postedReceipt([[self::SKU, 100, 60_000]]);
        $return = app(PurchaseReturnIssuer::class)
            ->draftEverything($receipt, $this->finance, 'Salah kirim');

        $this->assertTrue(PurchaseReturnResource::canEdit($return));
        $this->assertTrue(PurchaseReturnResource::canDelete($return));

        app(PurchaseReturnPoster::class)->post($return, $this->finance);
        $return->refresh();

        $this->assertFalse(PurchaseReturnResource::canEdit($return));
        $this->assertFalse(PurchaseReturnResource::canDelete($return));
    }

    public function test_the_edit_screen_opens_and_a_quantity_can_be_cut_down(): void
    {
        /*
         * This test exists because of a bug the others could not see. The edit
         * screen is the only place a return is changed, and rendering it threw
         * a TypeError: inside a `->relationship()` repeater Filament injects
         * the *line* record into a field's closures, not the parent document,
         * and a closure typed for the parent takes the page down with a 500.
         *
         * The list and detail screens rendered perfectly throughout.
         */
        $receipt = $this->postedReceipt([[self::SKU, 100, 60_000]]);
        $return = app(PurchaseReturnIssuer::class)
            ->draftEverything($receipt, $this->finance, 'Salah tipe');

        $this->actingAs($this->finance, 'web')
            ->get(PurchaseReturnResource::getUrl('edit', ['record' => $return]))
            ->assertOk()
            // The helper beside the quantity, which is what made the closure
            // need the record in the first place. Nothing has gone back yet,
            // so it states what arrived and what it cost and stops there.
            ->assertSee('Diterima 100 @ Rp 60.000.', false)
            ->assertSee('Belum ditagih pemasok', false);

        Livewire::actingAs($this->finance)
            ->test(EditPurchaseReturn::class, ['record' => $return->getKey()])
            ->fillForm(fn (array $state) => [
                'lines' => collect($state['lines'])
                    ->map(fn (array $line) => [...$line, 'qty_base' => 6])
                    ->all(),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(6, (int) $return->refresh()->lines()->first()->qty_base);
    }

    public function test_the_helper_stops_promising_a_remainder_there_is_none_of(): void
    {
        /*
         * Wording, but the kind that matters: beside a fully-billed line the
         * helper used to end "…sebanyak itu mengurangi utang, sisanya
         * membatalkan akrual", describing a second half of the posting that
         * will not happen. It also printed "Diterima 120, sisa yang bisa
         * diretur 120" before anything had gone back, which reads as a system
         * that cannot subtract.
         */
        $receipt = $this->postedReceipt([[self::SKU, 100, 60_000]]);
        $this->postedBillFor($receipt);

        $return = app(PurchaseReturnIssuer::class)
            ->draftEverything($receipt->refresh(), $this->finance, 'Salah tipe');

        $this->actingAs($this->finance, 'web')
            ->get(PurchaseReturnResource::getUrl('edit', ['record' => $return]))
            ->assertOk()
            ->assertSee('Sudah ditagih pemasok: mengurangi utang berikut PPN masukannya.', false)
            ->assertDontSee('sisanya membatalkan akrual', false)
            ->assertDontSee('sisa yang bisa diretur', false);
    }

    public function test_the_edit_screen_refuses_more_than_is_left(): void
    {
        // The form and the poster read the same ReturnableLine, so what is
        // offered and what is accepted cannot drift apart.
        $receipt = $this->postedReceipt([[self::SKU, 100, 60_000]]);
        $return = app(PurchaseReturnIssuer::class)
            ->draftEverything($receipt, $this->finance, 'Salah tipe');

        Livewire::actingAs($this->finance)
            ->test(EditPurchaseReturn::class, ['record' => $return->getKey()])
            ->fillForm(fn (array $state) => [
                'lines' => collect($state['lines'])
                    ->map(fn (array $line) => [...$line, 'qty_base' => 101])
                    ->all(),
            ])
            ->call('save')
            ->assertHasFormErrors();
    }

    public function test_a_draft_cannot_be_printed(): void
    {
        /*
         * Every figure is decided at posting and the goods are still on our
         * shelf. Handing a supplier a nota retur of nils, for cartons they
         * have not received, starts an argument rather than a record.
         */
        $receipt = $this->postedReceipt([[self::SKU, 100, 60_000]]);
        $return = app(PurchaseReturnIssuer::class)
            ->draftEverything($receipt, $this->finance, 'Salah kirim');

        $this->actingAs($this->finance, 'web')
            ->get(route('dokumen.retur-pembelian', $return))
            ->assertNotFound();
    }

    public function test_the_nota_retur_carries_what_the_supplier_needs_to_credit(): void
    {
        $return = $this->postedReturn(30);

        $this->actingAs($this->finance, 'web')
            ->get(route('dokumen.retur-pembelian', $return))
            ->assertOk()
            ->assertSee($return->nomor)
            ->assertSee('PT Pemasok Barang')
            ->assertSee($return->goodsReceipt->nomor)
            ->assertSee('Master rem depan')
            // The reason travels with the goods: a return is an argument, and
            // the supplier's warehouse has to know which one.
            ->assertSee('Salah tipe')
            ->assertSee('Rp 1.800.000');
    }

    public function test_the_nota_retur_never_shows_our_inventory_figure(): void
    {
        /*
         * Under moving-average costing the goods left the shelf at whatever
         * the average had drifted to, which is our bookkeeping and none of the
         * supplier's business — and quoting it would invite an argument about
         * a number they cannot check. What they are asked to credit is what
         * they charged.
         */
        $receipt = $this->postedReceipt([[self::SKU, 100, 60_000]]);
        // A second delivery at a higher price moves the average to 70,000.
        $this->postedReceipt([[self::SKU, 100, 80_000]]);

        $return = $this->postReturn($receipt, 50);

        $this->assertSame(3_500_000, (int) $return->nilai_persediaan_rupiah);

        $this->actingAs($this->finance, 'web')
            ->get(route('dokumen.retur-pembelian', $return))
            ->assertOk()
            ->assertSee('Rp 3.000.000')
            ->assertDontSee('Rp 3.500.000');
    }

    // --- helpers ------------------------------------------------------------

    /** @param  list<array{0: string, 1: int, 2: int}>  $lines */
    private function postedReceipt(array $lines): GoodsReceipt
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->pemasok->id,
            'warehouse_id' => $this->gudang->id,
            'created_by' => $this->finance->id,
        ]);

        foreach ($lines as $i => [$sku, $qty, $unitCost]) {
            GoodsReceiptLine::factory()->pieces($qty, $unitCost)->create([
                'goods_receipt_id' => $receipt->id, 'sku' => $sku, 'urutan' => $i + 1,
            ]);
        }

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);

        return $receipt->refresh();
    }

    /** A posted bill covering the whole receipt at what it was received at. */
    private function postedBillFor(GoodsReceipt $receipt): SupplierBill
    {
        $bill = SupplierBill::factory()->create([
            'supplier_id' => $this->pemasok->id,
            'nomor_faktur_pajak' => '010.000-26.'.fake()->unique()->numerify('########'),
        ]);

        foreach ($receipt->lines as $i => $line) {
            SupplierBillLine::factory()->forReceiptLine($line)
                ->create(['supplier_bill_id' => $bill->id, 'urutan' => $i + 1]);
        }

        app(SupplierBillPoster::class)->post($bill->refresh(), $this->finance);

        return $bill->refresh();
    }

    private function postedReturn(int $qty): PurchaseReturn
    {
        return $this->postReturn($this->postedReceipt([[self::SKU, 100, 60_000]]), $qty);
    }

    private function postReturn(GoodsReceipt $receipt, int $qty): PurchaseReturn
    {
        $return = app(PurchaseReturnIssuer::class)
            ->draftEverything($receipt, $this->finance, 'Salah tipe, dikonfirmasi ke pemasok');

        $return->lines()->first()->forceFill(['qty_base' => $qty])->save();

        return app(PurchaseReturnPoster::class)->post($return->refresh(), $this->finance);
    }
}
