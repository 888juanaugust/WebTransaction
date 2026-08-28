<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Access\TeamAssigner;
use App\Domain\Insight\CustomerInsight;
use App\Domain\Orders\OrderStatus;
use App\Filament\Pages\WawasanPelanggan;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The visit-preparation numbers: what this store buys, what it never has,
 * what it quietly stopped buying.
 */
class CustomerInsightTest extends TestCase
{
    use RefreshDatabase;

    private Company $toko;

    protected function setUp(): void
    {
        parent::setUp();

        $this->toko = Company::factory()->create();
    }

    private function orderFor(Company $company, string $sku, int $qty, OrderStatus $status, ?\DateTimeInterface $at = null): void
    {
        $order = Order::factory()->status($status)->create([
            'company_id' => $company->id,
            'created_at' => $at ?? now(),
        ]);

        OrderLine::factory()->create([
            'order_id' => $order->id,
            'sku' => $sku,
            'qty_base' => $qty,
        ]);
    }

    public function test_never_bought_ranks_by_what_the_market_buys(): void
    {
        $a = Product::factory()->create(['kode' => 'NB-A', 'aktif' => true]);
        $b = Product::factory()->create(['kode' => 'NB-B', 'aktif' => true]);
        $mati = Product::factory()->create(['kode' => 'NB-C', 'aktif' => false]);
        Product::factory()->create(['kode' => 'NB-D', 'aktif' => true]);

        // The market buys B heavily and A a little; our store bought only D.
        $lain = Company::factory()->create();
        $this->orderFor($lain, 'NB-B', 100, OrderStatus::Completed);
        $this->orderFor($lain, 'NB-A', 5, OrderStatus::Completed);
        $this->orderFor($this->toko, 'NB-D', 10, OrderStatus::Completed);

        $rekomendasi = app(CustomerInsight::class)->neverBought($this->toko)->pluck('kode')->all();

        // B first (proven demand), A after; D excluded (already bought),
        // C excluded (inactive).
        $this->assertSame('NB-B', $rekomendasi[0]);
        $this->assertContains('NB-A', $rekomendasi);
        $this->assertNotContains('NB-D', $rekomendasi);
        $this->assertNotContains('NB-C', $rekomendasi);
    }

    public function test_a_draft_does_not_count_as_having_bought(): void
    {
        Product::factory()->create(['kode' => 'NB-X', 'aktif' => true]);
        $this->orderFor($this->toko, 'NB-X', 5, OrderStatus::Draft);

        $this->assertContains(
            'NB-X',
            app(CustomerInsight::class)->neverBought($this->toko)->pluck('kode')->all(),
        );
    }

    public function test_stopped_buying_lists_the_lapsed_habit_with_its_last_date(): void
    {
        // A habit that lapsed, a habit still alive, and one purchase since.
        $this->orderFor($this->toko, 'SB-LAMA', 40, OrderStatus::Completed, now()->subDays(120));
        $this->orderFor($this->toko, 'SB-AKTIF', 10, OrderStatus::Completed, now()->subDays(120));
        $this->orderFor($this->toko, 'SB-AKTIF', 10, OrderStatus::Completed, now()->subDays(10));

        $berhenti = app(CustomerInsight::class)->stoppedBuying($this->toko, days: 90);

        $this->assertSame(['SB-LAMA'], $berhenti->pluck('sku')->all());
        $this->assertSame(40, (int) $berhenti->first()->total_qty);
    }

    public function test_history_is_newest_first(): void
    {
        $this->orderFor($this->toko, 'H-1', 1, OrderStatus::Completed, now()->subDays(5));
        $this->orderFor($this->toko, 'H-2', 1, OrderStatus::Draft, now()->subDay());

        $riwayat = app(CustomerInsight::class)->history($this->toko);

        $this->assertCount(2, $riwayat);
        $this->assertTrue($riwayat->first()->created_at->gt($riwayat->last()->created_at));
    }

    #[DataProvider('roles')]
    public function test_who_may_open_the_page(Role $role, bool $allowed): void
    {
        $this->actingAs(User::factory()->role($role)->create(), 'web');

        $this->assertSame($allowed, WawasanPelanggan::canAccess());
    }

    public static function roles(): array
    {
        return [
            'sales' => [Role::Sales, true],
            'marketing' => [Role::Marketing, true],
            'pemilik' => [Role::Owner, true],
            'inventori' => [Role::Warehouse, false],
            'keuangan' => [Role::Finance, false],
        ];
    }

    public function test_a_sales_is_offered_only_their_own_customers(): void
    {
        $owner = User::factory()->owner()->create();
        $sales = User::factory()->sales()->create(['region_id' => $this->currentRegion()->id]);

        $this->toko->forceFill(['status' => Company::STATUS_ACTIVE])->save();
        app(TeamAssigner::class)->assignSales($this->toko, $sales, $owner);

        Company::factory()->create(['status' => Company::STATUS_ACTIVE]);

        $this->actingAs($sales, 'web');
        $page = new WawasanPelanggan;

        $this->assertSame([$this->toko->id], array_keys($page->companyOptions()));
    }
}
