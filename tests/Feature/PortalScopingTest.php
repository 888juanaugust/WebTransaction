<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Portal\Concerns\ReadOnlyInPortal;
use App\Filament\Portal\Concerns\ScopedToBuyer;
use App\Filament\Portal\Resources\Katalog\KatalogResource;
use App\Filament\Portal\Resources\Orders\OrderResource;
use App\Filament\Portal\Resources\Orders\Pages\ListOrders;
use App\Filament\Portal\Resources\Tagihan\Pages\ListTagihan;
use App\Filament\Portal\Resources\Tagihan\TagihanResource;
use App\Models\Company;
use App\Models\CustomerUser;
use App\Models\Invoice;
use App\Models\Order;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The portal's one security-critical rule: a buyer sees their own company's
 * rows and nothing else.
 *
 * The failure here is not a broken page — it is a customer reading a
 * competitor's order history, prices and debts. So this file tests the rule
 * three ways: that the scope works, that it cannot be forgotten on a new
 * resource, and that guessing a URL does not get around it.
 */
class PortalScopingTest extends TestCase
{
    use RefreshDatabase;

    private Company $mine;

    private Company $theirs;

    private CustomerUser $me;

    protected function setUp(): void
    {
        parent::setUp();

        // Outside an HTTP request there is no "current" panel, and resource
        // URLs would be generated against the admin panel instead.
        Filament::setCurrentPanel('portal');

        $this->mine = Company::factory()->create(['nama' => 'Bengkel Saya']);
        $this->theirs = Company::factory()->create(['nama' => 'Bengkel Sebelah']);

        $this->me = CustomerUser::factory()->create(['company_id' => $this->mine->id]);
    }

    // --- the rule holds -----------------------------------------------------

    public function test_a_buyer_sees_only_their_own_orders(): void
    {
        $mine = Order::factory()->create(['company_id' => $this->mine->id, 'nomor' => 'SO-MINE-1']);
        $theirs = Order::factory()->create(['company_id' => $this->theirs->id, 'nomor' => 'SO-THEIRS-1']);

        $this->actingAs($this->me, 'customer');

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_a_buyer_sees_only_their_own_invoices(): void
    {
        $mine = Invoice::factory()->create(['company_id' => $this->mine->id, 'nomor' => 'INV-MINE-1']);
        $theirs = Invoice::factory()->create(['company_id' => $this->theirs->id, 'nomor' => 'INV-THEIRS-1']);

        $this->actingAs($this->me, 'customer');

        Livewire::test(ListTagihan::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    /**
     * The list is scoped, but so is the record route — otherwise the isolation
     * lasts exactly as long as it takes someone to edit the id in the URL.
     */
    public function test_guessing_another_companys_order_url_does_not_work(): void
    {
        $theirs = Order::factory()->create(['company_id' => $this->theirs->id]);

        $this->actingAs($this->me, 'customer')
            ->get(OrderResource::getUrl('view', ['record' => $theirs]))
            ->assertNotFound();
    }

    public function test_guessing_another_companys_invoice_url_does_not_work(): void
    {
        $theirs = Invoice::factory()->create(['company_id' => $this->theirs->id]);

        $this->actingAs($this->me, 'customer')
            ->get(TagihanResource::getUrl('view', ['record' => $theirs]))
            ->assertNotFound();
    }

    // --- the rule cannot be forgotten ---------------------------------------

    /**
     * Every portal resource whose model has a company_id column must use
     * ScopedToBuyer.
     *
     * Derived from the schema rather than from a list somebody has to remember
     * to update: add a portal resource over a company-owned table without the
     * trait and this fails, which is the only version of this test worth having.
     */
    public function test_every_company_owned_portal_resource_is_scoped(): void
    {
        $unscoped = [];

        foreach (self::portalResources() as $resource) {
            $model = $resource::getModel();

            $ownedByCompany = Schema::hasColumn((new $model)->getTable(), 'company_id');
            $isScoped = in_array(ScopedToBuyer::class, class_uses_recursive($resource), true);

            if ($ownedByCompany && ! $isScoped) {
                $unscoped[] = $resource;
            }
        }

        $this->assertSame(
            [],
            $unscoped,
            'These portal resources sit on a company-owned table without ScopedToBuyer, so they '
            .'will show every customer every other customer\'s rows: '.implode(', ', $unscoped)
        );
    }

    /** Nothing in the portal is created or edited through generic CRUD. */
    public function test_no_portal_resource_allows_writes(): void
    {
        $this->actingAs($this->me, 'customer');

        foreach (self::portalResources() as $resource) {
            $this->assertTrue(
                in_array(ReadOnlyInPortal::class, class_uses_recursive($resource), true),
                "{$resource} does not use ReadOnlyInPortal."
            );

            $this->assertFalse($resource::canCreate(), "{$resource} allows create.");
        }
    }

    /**
     * The catalogue is deliberately unscoped — products are not customer data —
     * so this pins that as a decision rather than leaving it looking like the
     * one resource somebody forgot.
     */
    public function test_the_catalogue_is_shared_and_that_is_deliberate(): void
    {
        $this->assertFalse(
            in_array(ScopedToBuyer::class, class_uses_recursive(KatalogResource::class), true),
            'The catalogue is the same for everyone; only the prices differ.'
        );

        $this->assertFalse(
            Schema::hasColumn('products', 'company_id'),
            'If products ever become company-owned, the catalogue needs scoping too.'
        );
    }

    /**
     * A null company would quietly become `where company_id is null` — no rows
     * today, and every row the day someone rewrites it as a nullable filter.
     */
    public function test_a_portal_query_with_no_buyer_in_session_throws(): void
    {
        $this->expectException(RuntimeException::class);

        OrderResource::getEloquentQuery();
    }

    /** @return list<class-string> */
    private static function portalResources(): array
    {
        $classes = [];

        foreach (Finder::create()->files()->in(app_path('Filament/Portal/Resources'))->name('*Resource.php') as $file) {
            $relative = str_replace(
                [app_path().DIRECTORY_SEPARATOR, '.php', DIRECTORY_SEPARATOR],
                ['', '', '\\'],
                $file->getRealPath(),
            );

            $class = 'App\\'.$relative;

            if (class_exists($class) && ! (new ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /** The discovery above must actually be finding things. */
    public function test_the_resource_sweep_finds_the_portal_resources(): void
    {
        $found = self::portalResources();

        foreach ([KatalogResource::class, OrderResource::class, TagihanResource::class] as $expected) {
            $this->assertContains($expected, $found);
        }
    }
}
