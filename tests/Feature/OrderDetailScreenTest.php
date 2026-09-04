<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\TeamAssigner;
use App\Domain\Orders\OrderFamily;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Regions\RegionContext;
use App\Filament\Portal\Resources\Orders\Pages\ViewOrder as PortalViewOrder;
use App\Filament\Resources\Orders\Pages\ViewOrder as AdminViewOrder;
use App\Models\Company;
use App\Models\CustomerUser;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\Region;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What the order detail screens say, on both sides of the login.
 *
 * Two things are being defended here. The first is invariant 3: a historical
 * order shows what it was charged, and a later price list does not reach back
 * and change the answer. The admin detail page used to render the *entry
 * form* disabled, whose helper text resolves prices live, so a six-month-old
 * order printed today's figures beside the snapshot columns — the screen was
 * wrong while the data was right, which is the harder kind to notice.
 *
 * The second is the multi-warehouse split. One request that became two
 * transactions must say so on both pieces and on both panels, or a customer
 * with two numbers in their history concludes they were charged twice.
 */
class OrderDetailScreenTest extends TestCase
{
    use RefreshDatabase;

    private Region $jkt;

    private Warehouse $gudangHome;

    private Warehouse $gudangJkt;

    private User $owner;

    private User $sales;

    private User $marketing;

    private Company $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);

        $region = $this->currentRegion();
        $this->jkt = Region::factory()->create(['kode' => 'JKT']);

        $this->gudangHome = Warehouse::factory()->create(['kode' => 'GD-HOME']);
        $this->gudangJkt = app(RegionContext::class)->within(
            $this->jkt,
            fn () => Warehouse::factory()->create(['kode' => 'GD-JKT']),
        );

        $this->owner = User::factory()->owner()->create();
        $this->sales = User::factory()->sales()->create(['region_id' => $region->id]);
        $this->marketing = User::factory()->marketing()->create(['region_id' => null]);

        $this->pelanggan = Company::factory()->creditLimit(500_000_000)->create();
        $assigner = app(TeamAssigner::class);
        $assigner->assignSales($this->pelanggan, $this->sales, $this->owner);
        $assigner->assignMarketing($this->pelanggan, $this->marketing, $this->owner);
    }

    // --- fixtures ----------------------------------------------------------

    private function productPriced(string $kode, int $harga = 100_000): Product
    {
        $product = Product::factory()->create(['kode' => $kode, 'qty_per_ctn' => 10]);

        $version = PriceListVersion::query()->where('status', 'published')->first()
            ?? PriceListVersion::factory()->published()->create();

        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => $kode, 'harga' => $harga,
        ]);

        return $product;
    }

    private function stockAt(Warehouse $gudang, string $sku, int $qty): void
    {
        app(RegionContext::class)->within((int) $gudang->region_id, fn () => StockLevel::query()->create([
            'sku' => $sku, 'warehouse_id' => $gudang->id,
            'qty_on_hand' => $qty, 'qty_reserved' => 0,
        ]));
    }

    /** @param  list<array{0: string, 1: int}>  $lines */
    private function submittedOrder(array $lines): Order
    {
        $order = Order::factory()->status(OrderStatus::Draft)->create([
            'company_id' => $this->pelanggan->id,
            'warehouse_id' => $this->gudangHome->id,
            'created_by' => $this->sales->id,
        ]);

        foreach ($lines as $i => [$sku, $qty]) {
            OrderLine::factory()->qty($qty)->create([
                'order_id' => $order->id, 'sku' => $sku, 'urutan' => $i + 1,
            ]);
        }

        app(OrderStateMachine::class)->submit($order, $this->sales);

        return $order->refresh();
    }

    /** A confirmed order that did not need splitting. */
    private function confirmedOrder(string $sku = 'DTL-A', int $harga = 100_000): Order
    {
        $this->productPriced($sku, $harga);
        $this->stockAt($this->gudangHome, $sku, 100);

        $order = $this->submittedOrder([[$sku, 10]]);
        app(OrderStateMachine::class)->confirm($order, $this->marketing);

        return $order->refresh();
    }

    private function buyerFor(Company $company): CustomerUser
    {
        return CustomerUser::factory()->create(['company_id' => $company->id]);
    }

    // --- invariant 3 on the screen ----------------------------------------

    /**
     * The whole point of the page.
     *
     * The order is confirmed at one price, and then the price list moves.
     * What the customer was charged does not.
     */
    public function test_the_admin_detail_shows_the_snapshot_not_todays_price(): void
    {
        $order = $this->confirmedOrder('DTL-SNAP', 100_000);

        $baris = $order->lines()->sole();
        $this->assertTrue($baris->isPriced());
        $this->assertSame(1_000_000, (int) $baris->line_total_rupiah);

        // The world moves on: a new published version doubles the price.
        $versiBaru = PriceListVersion::factory()->published()->create();
        PriceListItem::factory()->create([
            'version_id' => $versiBaru->id, 'kode' => 'DTL-SNAP', 'harga' => 200_000,
        ]);

        $this->actingAs($this->owner);

        Livewire::test(AdminViewOrder::class, ['record' => $order->getKey()])
            ->assertOk()
            // What was charged.
            ->assertSee('Rp 1.000.000')
            // What it would cost today, which this screen must never print.
            ->assertDontSee('Rp 2.000.000');
    }

    /** A draft has no snapshot yet, and must not borrow one from the list. */
    public function test_an_unpriced_line_says_so_rather_than_showing_a_live_price(): void
    {
        $this->productPriced('DTL-DRAFT', 100_000);

        $order = Order::factory()->status(OrderStatus::Draft)->create([
            'company_id' => $this->pelanggan->id,
            'warehouse_id' => $this->gudangHome->id,
            'created_by' => $this->sales->id,
        ]);
        OrderLine::factory()->qty(10)->create([
            'order_id' => $order->id, 'sku' => 'DTL-DRAFT', 'urutan' => 1,
        ]);

        $this->actingAs($this->owner);

        Livewire::test(AdminViewOrder::class, ['record' => $order->getKey()])
            ->assertOk()
            ->assertSee('belum dihargai')
            ->assertDontSee('Rp 1.000.000');
    }

    // --- the event log -----------------------------------------------------

    /**
     * CLAUDE.md requires every transition to be a logged event with an actor.
     * They were being written from the first day and read back nowhere.
     */
    public function test_the_admin_detail_reads_back_the_event_log(): void
    {
        $order = $this->confirmedOrder('DTL-LOG');

        $this->actingAs($this->owner);

        Livewire::test(AdminViewOrder::class, ['record' => $order->getKey()])
            ->assertOk()
            ->assertSee('Riwayat')
            ->assertSee($this->sales->name)      // submitted it
            ->assertSee($this->marketing->name); // approved it
    }

    // --- the split, on both panels ----------------------------------------

    /** @return array{0: Order, 1: Order} parent piece, sibling piece */
    private function splitOrder(): array
    {
        $this->productPriced('DTL-SPLIT');
        $this->stockAt($this->gudangHome, 'DTL-SPLIT', 60);
        $this->stockAt($this->gudangJkt, 'DTL-SPLIT', 100);

        $order = $this->submittedOrder([['DTL-SPLIT', 100]]);
        app(OrderStateMachine::class)->confirm($order, $this->marketing);

        return [$order->refresh(), $order->splitChildren()->sole()];
    }

    public function test_the_family_reads_both_pieces_from_either_end(): void
    {
        [$induk, $pecahan] = $this->splitOrder();
        $family = app(OrderFamily::class);

        $this->assertTrue($family->isSplit($induk));
        $this->assertTrue($family->isSplit($pecahan));

        foreach ([$induk, $pecahan] as $dari) {
            $nomor = $family->pieces($dari)->pluck('nomor')->all();
            $this->assertEqualsCanonicalizing([$induk->nomor, $pecahan->nomor], $nomor);
        }

        $this->assertSame([$pecahan->nomor], $family->siblings($induk)->pluck('nomor')->all());
        $this->assertSame([$induk->nomor], $family->siblings($pecahan)->pluck('nomor')->all());
    }

    /**
     * The pieces live in different regions' books by design, so the reader
     * lifts the region scope — including on the warehouses, or the foreign
     * piece comes back with no gudang and the screen cannot say where it
     * ships from.
     */
    public function test_the_family_crosses_regions_including_the_warehouses(): void
    {
        [$induk, $pecahan] = $this->splitOrder();

        $this->assertNotSame((int) $induk->region_id, (int) $pecahan->region_id);

        $pieces = app(OrderFamily::class)->pieces($induk)->keyBy('nomor');

        $this->assertSame('GD-HOME', $pieces[$induk->nomor]->warehouse?->kode);
        $this->assertSame('GD-JKT', $pieces[$pecahan->nomor]->warehouse?->kode);
    }

    /** An ordinary order is a family of one, and says nothing about splits. */
    public function test_an_unsplit_order_is_not_reported_as_split(): void
    {
        $order = $this->confirmedOrder('DTL-SOLO');
        $family = app(OrderFamily::class);

        $this->assertFalse($family->isSplit($order));
        $this->assertSame([$order->nomor], $family->pieces($order)->pluck('nomor')->all());
        $this->assertTrue($family->siblings($order)->isEmpty());

        $this->actingAs($this->owner);

        Livewire::test(AdminViewOrder::class, ['record' => $order->getKey()])
            ->assertOk()
            ->assertDontSee('Pecahan pengiriman');
    }

    public function test_the_admin_detail_names_the_other_piece(): void
    {
        [$induk, $pecahan] = $this->splitOrder();

        $this->actingAs($this->owner);

        Livewire::test(AdminViewOrder::class, ['record' => $induk->getKey()])
            ->assertOk()
            ->assertSee('Pecahan pengiriman')
            ->assertSee($pecahan->nomor)
            ->assertSee($this->gudangJkt->nama);
    }

    public function test_the_buyer_is_told_their_order_shipped_in_pieces(): void
    {
        [$induk, $pecahan] = $this->splitOrder();

        Filament::setCurrentPanel('portal');
        $this->actingAs($this->buyerFor($this->pelanggan), 'customer');

        Livewire::test(PortalViewOrder::class, ['record' => $induk->getKey()])
            ->assertOk()
            ->assertSee('Pesanan ini dikirim terpisah')
            ->assertSee($pecahan->nomor);
    }

    // --- the buyer's surat jalan ------------------------------------------

    private function shipped(Order $order): Order
    {
        $mesin = app(OrderStateMachine::class);
        $finance = User::factory()->finance()->create();
        $gudang = User::factory()->warehouse()->create();

        $mesin->awaitPayment($order, $finance);
        $mesin->markPaid($order->refresh(), ['source' => 'test']);
        $mesin->ship($order->refresh(), $gudang);

        return $order->refresh();
    }

    public function test_a_buyer_prints_their_own_shipped_delivery_note(): void
    {
        $order = $this->shipped($this->confirmedOrder('DTL-SJ'));
        $this->assertSame(OrderStatus::Shipped, $order->status);

        $this->actingAs($this->buyerFor($this->pelanggan), 'customer')
            ->get(route('portal.dokumen.surat-jalan', ['order' => $order->id]))
            ->assertOk()
            ->assertSee('Surat Jalan')
            ->assertSee($order->nomor);
    }

    /**
     * For the warehouse the document is a picking list, printable from
     * confirmed. For the buyer it is proof of a delivery, so it does not
     * exist until there has been one.
     */
    public function test_a_buyer_cannot_print_a_delivery_note_before_the_goods_leave(): void
    {
        $order = $this->confirmedOrder('DTL-EARLY');

        $this->actingAs($this->buyerFor($this->pelanggan), 'customer')
            ->get(route('portal.dokumen.surat-jalan', ['order' => $order->id]))
            ->assertForbidden();

        // The warehouse's copy of the same order is available now, which is
        // the whole reason the two gates are written separately.
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->warehouse()->create(), 'web')
            ->get(route('dokumen.surat-jalan', ['order' => $order->id]))
            ->assertOk();
    }

    /**
     * Another company's delivery note is a 404, not a 403: a refusal that
     * confirms the order exists tells a customer how much business somebody
     * else is doing.
     */
    public function test_another_companys_delivery_note_does_not_exist(): void
    {
        $order = $this->shipped($this->confirmedOrder('DTL-OTHER'));

        $lain = Company::factory()->creditLimit(10_000_000)->create();

        $this->actingAs($this->buyerFor($lain), 'customer')
            ->get(route('portal.dokumen.surat-jalan', ['order' => $order->id]))
            ->assertNotFound();
    }

    public function test_the_delivery_note_is_closed_to_anonymous_visitors(): void
    {
        $order = $this->shipped($this->confirmedOrder('DTL-ANON'));

        $this->get(route('portal.dokumen.surat-jalan', ['order' => $order->id]))
            ->assertRedirect(route('filament.portal.auth.login'));
    }

    /** The button appears on the buyer's order only once there is a delivery. */
    public function test_the_portal_offers_the_delivery_note_only_after_shipping(): void
    {
        $order = $this->confirmedOrder('DTL-BTN');

        Filament::setCurrentPanel('portal');
        $buyer = $this->buyerFor($this->pelanggan);
        $this->actingAs($buyer, 'customer');

        Livewire::test(PortalViewOrder::class, ['record' => $order->getKey()])
            ->assertOk()
            ->assertDontSee('Buka surat jalan');

        // Shipping is staff work: drop the buyer session first, or the
        // transitions run with a customer sitting in the default guard.
        $this->app['auth']->forgetGuards();
        $this->shipped($order);

        Filament::setCurrentPanel('portal');
        $this->actingAs($buyer, 'customer');

        Livewire::test(PortalViewOrder::class, ['record' => $order->getKey()])
            ->assertOk()
            ->assertSee('Buka surat jalan');
    }
}
