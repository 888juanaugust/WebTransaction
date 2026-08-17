<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Purchasing\AllocationBasis;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Purchasing\LandedCostAllocator;
use App\Domain\Purchasing\LandedCostPoster;
use App\Domain\Purchasing\SupplierBillPoster;
use App\Filament\Resources\LandedCosts\LandedCostResource;
use App\Filament\Resources\LandedCosts\Pages\ListLandedCosts;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\LandedCost;
use App\Models\Product;
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
 * The landed cost screen, and who is shown it.
 *
 * The whole document is money — what a shipment cost, and how much margin the
 * goods on the shelf still carry — so this is a Finance and Owner screen and
 * the route has to say so, not just the menu.
 */
class LandedCostScreensTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-BP-1';

    private Warehouse $gudang;

    private User $finance;

    private Supplier $pemasok;

    private Supplier $forwarder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::factory()->create(['nama' => 'Gudang Pusat']);
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->pemasok = Supplier::factory()->create(['nama' => 'PT Pemasok Barang']);
        $this->forwarder = Supplier::factory()->create(['nama' => 'PT Angkutan Laut']);

        Product::factory()->create(['kode' => self::SKU, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS']);
    }

    #[DataProvider('roles')]
    public function test_who_may_open_the_screen(Role $role, bool $allowed): void
    {
        $this->actingAs(User::factory()->role($role)->create());

        $this->assertSame($allowed, LandedCostResource::canViewAny());
    }

    #[DataProvider('roles')]
    public function test_the_route_refuses_not_just_the_menu(Role $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(LandedCostResource::getUrl('index'));

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

    public function test_an_allocation_is_drawn_from_a_charge_and_its_receipts(): void
    {
        /*
         * There is no create form on purpose. The shares come from what the
         * receipts contain; a form that accepted typed amounts would let
         * somebody put the freight wherever it flattered the margin.
         */
        $receipt = $this->postedReceipt(100, 60_000);
        $charge = $this->postedCharge(600_000);

        $this->actingAs($this->finance);
        $this->assertFalse(LandedCostResource::canCreate());

        Livewire::actingAs($this->finance)
            ->test(ListLandedCosts::class)
            ->callAction('alokasikan', [
                'supplier_bill_line_id' => $charge->id,
                'goods_receipt_ids' => [$receipt->id],
                'dasar' => AllocationBasis::Nilai->value,
                'catatan' => 'Kontainer pertama',
            ])
            ->assertHasNoActionErrors();

        $landedCost = LandedCost::query()->sole();

        $this->assertTrue($landedCost->isDraft());
        $this->assertSame(600_000, (int) $landedCost->amount_rupiah);
        $this->assertSame('Kontainer pertama', $landedCost->catatan);
        $this->assertSame(1, $landedCost->lines()->count());
    }

    public function test_a_charge_that_has_been_spread_is_no_longer_offered(): void
    {
        // The dropdown is the worklist. A charge that has been allocated
        // appearing in it invites somebody to allocate it twice.
        $receipt = $this->postedReceipt(100, 60_000);
        $charge = $this->postedCharge(600_000);

        $allocator = app(LandedCostAllocator::class);

        $this->assertTrue($allocator->unallocated()->contains('id', $charge->id));

        $allocator->draw($charge, [$receipt], AllocationBasis::Nilai, $this->finance);

        $this->assertFalse($allocator->unallocated()->contains('id', $charge->id));
    }

    public function test_a_goods_line_is_never_offered_as_a_charge(): void
    {
        $receipt = $this->postedReceipt(100, 60_000);

        $bill = SupplierBill::factory()->create(['supplier_id' => $this->pemasok->id]);
        SupplierBillLine::factory()
            ->forReceiptLine($receipt->lines()->first())
            ->create(['supplier_bill_id' => $bill->id, 'urutan' => 1]);

        app(SupplierBillPoster::class)->post($bill->refresh(), $this->finance);

        $this->assertTrue(app(LandedCostAllocator::class)->unallocated()->isEmpty());
    }

    public function test_a_charge_waiting_to_be_spread_shows_on_the_sidebar(): void
    {
        // A balance in the clearing account is a work item, and the badge is
        // the same population the control check measures.
        $this->actingAs($this->finance);
        $receipt = $this->postedReceipt(100, 60_000);

        $this->assertNull(LandedCostResource::getNavigationBadge());

        $charge = $this->postedCharge(600_000);

        $this->assertSame('1', LandedCostResource::getNavigationBadge());

        $landedCost = app(LandedCostAllocator::class)
            ->draw($charge, [$receipt], AllocationBasis::Nilai, $this->finance);
        app(LandedCostPoster::class)->post($landedCost, $this->finance);

        $this->assertNull(LandedCostResource::getNavigationBadge());
    }

    public function test_the_list_shows_the_split_only_once_it_is_decided(): void
    {
        /*
         * A draft has no split. It depends on how much is on the shelf at the
         * moment of posting, and printing a figure the posting might not
         * produce is worse than printing none.
         */
        $receipt = $this->postedReceipt(100, 60_000);
        $charge = $this->postedCharge(600_000);

        $landedCost = app(LandedCostAllocator::class)
            ->draw($charge, [$receipt], AllocationBasis::Nilai, $this->finance);

        Livewire::actingAs($this->finance)
            ->test(ListLandedCosts::class)
            ->assertOk()
            ->assertSee($landedCost->nomor)
            ->assertSee('Rp 600.000')
            ->assertSee('Draf');

        app(LandedCostPoster::class)->post($landedCost, $this->finance);

        Livewire::actingAs($this->finance)
            ->test(ListLandedCosts::class)
            ->assertSee('Diposting');
    }

    public function test_a_posted_allocation_can_no_longer_be_edited_or_deleted(): void
    {
        $receipt = $this->postedReceipt(100, 60_000);
        $charge = $this->postedCharge(600_000);

        $landedCost = app(LandedCostAllocator::class)
            ->draw($charge, [$receipt], AllocationBasis::Nilai, $this->finance);

        $this->actingAs($this->finance);

        // A draft is throwaway; once posted it is evidence behind a balance
        // sheet figure, and a mistake is corrected with another document.
        $this->assertTrue(LandedCostResource::canDelete($landedCost));
        $this->assertFalse(LandedCostResource::canEdit($landedCost));

        app(LandedCostPoster::class)->post($landedCost, $this->finance);

        $this->assertFalse(LandedCostResource::canDelete($landedCost->refresh()));
        $this->assertFalse(LandedCostResource::canEdit($landedCost));
    }

    public function test_the_detail_screen_shows_the_arithmetic_behind_the_split(): void
    {
        // Somebody has to be able to check the numbers rather than trust them.
        $receipt = $this->postedReceipt(100, 60_000);
        $charge = $this->postedCharge(600_000);

        $landedCost = app(LandedCostAllocator::class)
            ->draw($charge, [$receipt], AllocationBasis::Nilai, $this->finance);
        app(LandedCostPoster::class)->post($landedCost, $this->finance);

        $this->actingAs($this->finance, 'web')
            ->get(LandedCostResource::getUrl('view', ['record' => $landedCost]))
            ->assertOk()
            ->assertSee('Nilai barang')
            ->assertSee('100 dari 100 unit '.self::SKU.' masih ada')
            ->assertSee(self::SKU);
    }

    public function test_the_quantities_on_a_line_are_both_about_the_same_thing(): void
    {
        /*
         * One part arriving on two receipts. The split is decided per SKU —
         * one moving average for the company cannot tell one carton from
         * another — so the on-hand figure stored on a line is the whole SKU's.
         *
         * Printing that beside the *line's* own received quantity gave rows
         * reading "150 dari 50 masih ada": the whole shelf against one
         * delivery's worth. Nothing was miscalculated; the document just
         * looked like a system that cannot count, which is enough for somebody
         * to stop signing it.
         */
        $first = $this->postedReceipt(50, 60_000);
        $second = $this->postedReceipt(100, 60_000);
        $charge = $this->postedCharge(600_000);

        $landedCost = app(LandedCostAllocator::class)
            ->draw($charge, [$first, $second], AllocationBasis::Nilai, $this->finance);
        app(LandedCostPoster::class)->post($landedCost, $this->finance);

        $response = $this->actingAs($this->finance, 'web')
            ->get(LandedCostResource::getUrl('view', ['record' => $landedCost]))
            ->assertOk();

        $response->assertSee('150 dari 150 unit '.self::SKU.' masih ada');
        $response->assertDontSee('150 dari 50 masih ada');
        $response->assertDontSee('150 dari 100 masih ada');
    }

    // --- helpers ------------------------------------------------------------

    private function postedReceipt(int $qty, int $unitCost): GoodsReceipt
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->pemasok->id,
            'warehouse_id' => $this->gudang->id,
            'created_by' => $this->finance->id,
        ]);
        GoodsReceiptLine::factory()->pieces($qty, $unitCost)->create([
            'goods_receipt_id' => $receipt->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);

        return $receipt->refresh();
    }

    private function postedCharge(int $amount): SupplierBillLine
    {
        $bill = SupplierBill::factory()->create(['supplier_id' => $this->forwarder->id]);
        SupplierBillLine::factory()->biaya($amount, 'Ongkos angkut laut')
            ->create(['supplier_bill_id' => $bill->id, 'urutan' => 1]);

        app(SupplierBillPoster::class)->post($bill->refresh(), $this->finance);

        return $bill->refresh()->lines()->first();
    }
}
