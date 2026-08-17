<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Stock\StockOpnamePoster;
use App\Domain\Stock\StockOpnameSheet;
use App\Domain\Stock\StockTransferPoster;
use App\Filament\Resources\StockOpnames\Pages\EditStockOpname;
use App\Filament\Resources\StockOpnames\Pages\ListStockOpnames;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Filament\Resources\StockTransfers\Pages\ListStockTransfers;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Product;
use App\Models\StockOpname;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The two warehouse screens, and what each role is shown.
 *
 * Both live in the warehouse's world, and warehouse staff are the one role
 * that never sees money. So as much of this is about what is *absent* from a
 * page as about what is on it — a value column that leaks onto a stock screen
 * undoes a separation the rest of the system is careful about.
 */
class StockScreensTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-SC-1';

    private Warehouse $pusat;

    private Warehouse $cabang;

    private User $gudang;

    private User $finance;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pusat = Warehouse::factory()->create(['nama' => 'Gudang Pusat']);
        $this->cabang = Warehouse::factory()->create(['nama' => 'Gudang Cabang']);
        $this->gudang = User::factory()->role(Role::Warehouse)->create();
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->owner = User::factory()->role(Role::Owner)->create();

        Product::factory()->create(['kode' => self::SKU, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS']);
    }

    // ------------------------------------------------------------ transfers

    #[DataProvider('transferRoles')]
    public function test_who_may_open_the_transfer_screen(Role $role, bool $allowed): void
    {
        $this->actingAs(User::factory()->role($role)->create());

        $this->assertSame($allowed, StockTransferResource::canViewAny());
    }

    #[DataProvider('transferRoles')]
    public function test_the_transfer_route_refuses_not_just_the_menu(Role $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(StockTransferResource::getUrl('index'));

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public static function transferRoles(): array
    {
        return [
            'gudang' => [Role::Warehouse, true],
            'pemilik' => [Role::Owner, true],
            'sales' => [Role::Sales, false],
            'keuangan' => [Role::Finance, false],
        ];
    }

    public function test_the_transfer_list_shows_no_money_to_the_warehouse(): void
    {
        /*
         * The value column exists for whoever may see cost. Warehouse may not,
         * and a transfer is value-neutral anyway — there is nothing they need
         * it for.
         */
        $this->stockUp($this->pusat, 100, 60_000);
        $transfer = $this->postedTransfer(30);

        $this->assertSame(1_800_000, (int) $transfer->total_value_rupiah);

        Livewire::actingAs($this->gudang)
            ->test(ListStockTransfers::class)
            ->assertOk()
            ->assertSee($transfer->nomor)
            ->assertSee('Gudang Cabang')
            ->assertDontSee('Rp 1.800.000');

        Livewire::actingAs($this->owner)
            ->test(ListStockTransfers::class)
            ->assertSee('Rp 1.800.000');
    }

    public function test_a_posted_transfer_can_no_longer_be_edited(): void
    {
        $this->stockUp($this->pusat, 100, 60_000);
        $transfer = $this->postedTransfer(30);

        $this->actingAs($this->owner);

        $this->assertFalse(StockTransferResource::canEdit($transfer));
        $this->assertFalse(StockTransferResource::canDelete($transfer));
    }

    // -------------------------------------------------------------- opname

    #[DataProvider('opnameRoles')]
    public function test_who_may_open_the_opname_screen(Role $role, bool $allowed): void
    {
        // Wider than either permission alone: warehouse fill the sheet in,
        // finance approve it, and each needs to see the other's work.
        $this->actingAs(User::factory()->role($role)->create());

        $this->assertSame($allowed, StockOpnameResource::canViewAny());
    }

    public static function opnameRoles(): array
    {
        return [
            'gudang' => [Role::Warehouse, true],
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
            'sales' => [Role::Sales, false],
        ];
    }

    public function test_a_sheet_is_drawn_from_the_shelf_rather_than_typed(): void
    {
        /*
         * There is no create form on purpose. A sheet has to cover every SKU
         * the warehouse holds, including the ones the system says are at zero,
         * or the count can only ever find what somebody already suspected.
         */
        $this->stockUp($this->pusat, 100, 60_000);

        $this->actingAs($this->gudang);
        $this->assertFalse(StockOpnameResource::canCreate());

        Livewire::actingAs($this->gudang)
            ->test(ListStockOpnames::class)
            ->callAction('buat', ['warehouse_id' => $this->pusat->id, 'catatan' => 'Hitungan rutin'])
            ->assertHasNoActionErrors();

        $opname = StockOpname::query()->sole();

        $this->assertSame($this->pusat->id, $opname->warehouse_id);
        $this->assertSame('Hitungan rutin', $opname->catatan);
        $this->assertSame(1, $opname->lines()->count());
        $this->assertNull($opname->lines()->first()->qty_counted);
    }

    public function test_finance_is_not_offered_the_draw_action(): void
    {
        // They approve counts; they do not stand at the shelf.
        $this->stockUp($this->pusat, 100, 60_000);

        Livewire::actingAs($this->finance)
            ->test(ListStockOpnames::class)
            ->assertOk()
            ->assertDontSee('Buat lembar opname');

        Livewire::actingAs($this->gudang)
            ->test(ListStockOpnames::class)
            ->assertSee('Buat lembar opname');
    }

    public function test_the_opname_list_shows_no_money_to_the_warehouse(): void
    {
        $this->stockUp($this->pusat, 100, 60_000);
        $opname = $this->postedOpname(94);

        $this->assertSame(-360_000, (int) $opname->selisih_rupiah);

        Livewire::actingAs($this->gudang)
            ->test(StockOpnameResource::getPages()['index']->getPage())
            ->assertOk()
            // The quantity variance is theirs — it is what they counted.
            ->assertSee('-6')
            ->assertDontSee('360.000');

        Livewire::actingAs($this->finance)
            ->test(StockOpnameResource::getPages()['index']->getPage())
            ->assertSee('360.000');
    }

    public function test_the_list_names_who_counted_and_who_approved(): void
    {
        $this->stockUp($this->pusat, 100, 60_000);
        $this->postedOpname(94);

        Livewire::actingAs($this->finance)
            ->test(StockOpnameResource::getPages()['index']->getPage())
            ->assertOk()
            ->assertSee($this->gudang->name)
            ->assertSee($this->finance->name);
    }

    public function test_an_unapproved_count_shows_on_the_sidebar(): void
    {
        // A warehouse with an unapproved count is a warehouse whose figures
        // are in question.
        $this->actingAs($this->finance);
        $this->stockUp($this->pusat, 100, 60_000);

        $this->assertNull(StockOpnameResource::getNavigationBadge());

        app(StockOpnameSheet::class)->draw($this->pusat, $this->gudang);

        $this->assertSame('1', StockOpnameResource::getNavigationBadge());
    }

    public function test_the_count_sheet_shows_the_same_date_the_list_does(): void
    {
        /*
         * The form used to re-parse the date out of the form state, and
         * Livewire hands that back as a UTC instant — so a sheet drawn at
         * midnight Jakarta time printed as the day before on the form while
         * the list printed it correctly. Two dates on one document is the
         * kind of thing a counter notices and stops trusting.
         */
        $this->travelTo('2026-08-17 01:30:00'); // 08:30 in Jakarta.
        $this->stockUp($this->pusat, 100, 60_000);
        $opname = app(StockOpnameSheet::class)->draw($this->pusat, $this->gudang);

        $this->assertSame('2026-08-17', $opname->tanggal->format('Y-m-d'));

        // Asserted on the form state, not the markup: a disabled input renders
        // without a value and is filled in by Livewire on the client.
        $page = Livewire::actingAs($this->gudang)
            ->test(EditStockOpname::class, ['record' => $opname->getKey()])
            ->assertOk();

        $this->assertSame('17/08/2026', $page->get('data.tanggal'));
    }

    public function test_an_approved_count_can_no_longer_be_edited(): void
    {
        $this->stockUp($this->pusat, 100, 60_000);
        $opname = $this->postedOpname(94);

        $this->actingAs($this->gudang);

        $this->assertFalse(StockOpnameResource::canEdit($opname));
    }

    public function test_finance_cannot_edit_a_count_sheet_even_while_it_is_open(): void
    {
        // Filling in the numbers is the counter's job. Finance approving a
        // sheet they could also have written is the separation collapsing.
        $this->stockUp($this->pusat, 100, 60_000);
        $opname = app(StockOpnameSheet::class)->draw($this->pusat, $this->gudang);

        $this->actingAs($this->finance);
        $this->assertFalse(StockOpnameResource::canEdit($opname));

        $this->actingAs($this->gudang);
        $this->assertTrue(StockOpnameResource::canEdit($opname));
    }

    // --- helpers ------------------------------------------------------------

    private function stockUp(Warehouse $warehouse, int $qty, int $unitCost): void
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'warehouse_id' => $warehouse->id,
            'created_by' => $this->finance->id,
        ]);
        GoodsReceiptLine::factory()->pieces($qty, $unitCost)->create([
            'goods_receipt_id' => $receipt->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);
    }

    private function postedTransfer(int $qty): StockTransfer
    {
        $transfer = StockTransfer::factory()->create([
            'from_warehouse_id' => $this->pusat->id,
            'to_warehouse_id' => $this->cabang->id,
            'created_by' => $this->gudang->id,
        ]);
        StockTransferLine::factory()->create([
            'stock_transfer_id' => $transfer->id,
            'sku' => self::SKU,
            'urutan' => 1,
            'qty_base' => $qty,
        ]);

        return app(StockTransferPoster::class)->post($transfer->refresh(), $this->gudang);
    }

    private function postedOpname(int $counted): StockOpname
    {
        $sheet = app(StockOpnameSheet::class);
        $opname = $sheet->draw($this->pusat, $this->gudang);
        $opname = $sheet->record($opname, [self::SKU => $counted], $this->gudang);

        return app(StockOpnamePoster::class)->post($opname, $this->finance);
    }
}
