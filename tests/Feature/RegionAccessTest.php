<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Access\StaffRegistrar;
use App\Domain\Audit\AuditLogger;
use App\Domain\Regions\RegionContext;
use App\Filament\Resources\Regions\RegionResource;
use App\Http\Middleware\BindRegionContext;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Region;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who sees which region, decided by the account rather than the request.
 *
 * The property that matters: a member of staff pinned to one region cannot
 * reach another's rows by any input they control — not a URL, not a crafted
 * POST to the switcher, not an invoice number pasted into search. Only the
 * Owner moves between regions, and "all regions" is read-only.
 */
class RegionAccessTest extends TestCase
{
    use RefreshDatabase;

    private Region $surabaya;

    private Region $jakarta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->surabaya = $this->currentRegion();
        $this->jakarta = Region::factory()->create(['kode' => 'JKT', 'nama' => 'Jakarta']);
    }

    public function test_a_pinned_clerk_sees_only_their_regions_rows(): void
    {
        Company::factory()->create(['nama' => 'Bengkel Surabaya']);
        app(RegionContext::class)->within($this->jakarta, fn () => Company::factory()->create(['nama' => 'Bengkel Jakarta']));

        $klerk = User::factory()->finance()->create(['region_id' => $this->jakarta->id]);

        // The middleware runs on a real request; what it binds is what counts.
        $this->actingAs($klerk)->get('/admin')->assertOk();

        $this->assertSame($this->jakarta->id, app(RegionContext::class)->regionId());
        $this->assertSame(['Bengkel Jakarta'], Company::query()->pluck('nama')->all());
    }

    public function test_a_clerk_nobody_assigned_gets_one_region_not_all_of_them(): void
    {
        /*
         * Null on an ordinary account means "not assigned yet", and the unsafe
         * reading of that is "all of them". They are pinned to the first
         * active region instead — visible, deterministic, and correctable on
         * the staff screen.
         */
        $klerk = User::factory()->sales()->create(['region_id' => null]);

        $this->actingAs($klerk)->get('/admin')->assertOk();

        $this->assertTrue(app(RegionContext::class)->isPinned());
    }

    public function test_the_owner_can_switch_region_and_the_choice_sticks(): void
    {
        $owner = User::factory()->owner()->create(['region_id' => null]);

        $this->actingAs($owner)
            ->post('/admin/wilayah-aktif', ['wilayah' => (string) $this->jakarta->id])
            ->assertRedirect();

        $this->actingAs($owner)->get('/admin')->assertOk();

        $this->assertSame($this->jakarta->id, app(RegionContext::class)->regionId());
    }

    public function test_the_owner_can_look_across_all_regions(): void
    {
        Company::factory()->create();
        app(RegionContext::class)->within($this->jakarta, fn () => Company::factory()->create());

        $owner = User::factory()->owner()->create(['region_id' => null]);

        $this->actingAs($owner)
            ->post('/admin/wilayah-aktif', ['wilayah' => 'semua'])
            ->assertRedirect();

        $this->actingAs($owner)->get('/admin')->assertOk();

        $this->assertTrue(app(RegionContext::class)->isOpenToAll());
        $this->assertSame(2, Company::query()->count());
    }

    public function test_the_region_binding_survives_livewire_update_requests(): void
    {
        /*
         * Filament runs non-persistent panel middleware only on full page
         * loads. Table searches, widget refreshes, and every action button
         * arrive as POST /livewire/update — and with the region unbound
         * there, the read scope falls open to every region and creates
         * throw. Found in the browser: a marketing approving an order from
         * the dashboard queue got a 500 out of the stock reservation.
         *
         * Filament registers the panel's persistent middleware with Livewire
         * when the panel boots, which a real request does; being listed
         * there is what makes it run on /livewire/update.
         */
        $klerk = User::factory()->finance()->create(['region_id' => $this->jakarta->id]);

        $this->actingAs($klerk)->get('/admin')->assertOk();

        $this->assertContains(
            BindRegionContext::class,
            Livewire::getPersistentMiddleware(),
            'BindRegionContext must be persistent, or Livewire updates run unscoped.',
        );
    }

    public function test_marketing_reads_every_region_with_no_switcher(): void
    {
        /*
         * Marketing is global — one marketing answers for customers in every
         * region, so their screens read across all books, always. A leftover
         * region_id on the account changes nothing: the role decides.
         */
        Company::factory()->create(['nama' => 'Bengkel Surabaya']);
        app(RegionContext::class)->within($this->jakarta, fn () => Company::factory()->create(['nama' => 'Bengkel Jakarta']));

        $marketing = User::factory()->marketing()->create(['region_id' => $this->surabaya->id]);

        $this->actingAs($marketing)->get('/admin')->assertOk();

        $this->assertTrue(app(RegionContext::class)->isOpenToAll());
        $this->assertSame(2, Company::query()->count());
    }

    public function test_a_pinned_clerk_cannot_switch_by_crafted_post(): void
    {
        /*
         * The escalation the pinning exists to prevent: a Jakarta clerk
         * POSTing the switcher endpoint with Surabaya's id. Refused at the
         * controller, and even if it wrote the session, the middleware reads
         * the account first — two independent layers, same answer.
         */
        $klerk = User::factory()->finance()->create(['region_id' => $this->jakarta->id]);

        $this->actingAs($klerk)
            ->post('/admin/wilayah-aktif', ['wilayah' => (string) $this->surabaya->id])
            ->assertForbidden();

        $this->actingAs($klerk)->get('/admin')->assertOk();
        $this->assertSame($this->jakarta->id, app(RegionContext::class)->regionId());
    }

    public function test_an_inactive_region_cannot_be_switched_to(): void
    {
        $tutup = Region::factory()->create(['aktif' => false]);
        $owner = User::factory()->owner()->create(['region_id' => null]);

        $this->actingAs($owner)
            ->post('/admin/wilayah-aktif', ['wilayah' => (string) $tutup->id])
            ->assertStatus(422);
    }

    public function test_a_stale_session_choice_falls_back_rather_than_showing_nothing(): void
    {
        /*
         * The Owner had a region selected; it has since been deactivated. A
         * trusted stale id would filter every screen to a region that no
         * longer offers itself — an empty system with no explanation on it.
         */
        $owner = User::factory()->owner()->create(['region_id' => null]);

        $this->actingAs($owner)
            ->post('/admin/wilayah-aktif', ['wilayah' => (string) $this->jakarta->id])
            ->assertRedirect();

        $this->jakarta->forceFill(['aktif' => false])->save();

        $this->actingAs($owner)->get('/admin')->assertOk();

        $this->assertTrue(app(RegionContext::class)->isPinned());
        $this->assertNotSame($this->jakarta->id, app(RegionContext::class)->regionId());
    }

    public function test_assigning_a_region_is_audited_with_both_sides(): void
    {
        $owner = User::factory()->owner()->create();
        $klerk = User::factory()->sales()->create(['region_id' => $this->surabaya->id]);

        app(StaffRegistrar::class)->assignRegion($klerk, $this->jakarta->id, $owner);

        $row = AuditLog::where('action', 'staff_region_changed')->sole();

        $this->assertSame($this->surabaya->id, $row->old_value['region_id']);
        $this->assertSame($this->jakarta->id, $row->new_value['region_id']);
        $this->assertSame($this->jakarta->id, (int) $klerk->fresh()->region_id);
    }

    public function test_nobody_may_move_their_own_region(): void
    {
        $owner = User::factory()->owner()->create(['region_id' => null]);

        $this->expectException(\RuntimeException::class);

        app(StaffRegistrar::class)->assignRegion($owner, $this->jakarta->id, $owner);
    }

    public function test_creating_an_owner_never_pins_them(): void
    {
        /*
         * Whatever the form sent: an Owner with a region would be an admin who
         * cannot administer half the company, and the blank is the grant.
         */
        $staff = app(StaffRegistrar::class)->create(
            'Pemilik Kedua', 'p2@example.test', Role::Owner, 'sandi-panjang-sekali',
            User::factory()->owner()->create(),
            regionId: $this->jakarta->id,
        );

        $this->assertNull($staff->region_id);
    }

    #[DataProvider('roles')]
    public function test_only_the_owner_may_manage_regions(Role $role, bool $allowed): void
    {
        $this->actingAs(User::factory()->role($role)->create());

        $this->assertSame($allowed, RegionResource::canViewAny());
    }

    public static function roles(): array
    {
        return [
            'sales' => [Role::Sales, false],
            'gudang' => [Role::Warehouse, false],
            'keuangan' => [Role::Finance, false],
            'pemilik' => [Role::Owner, true],
        ];
    }

    public function test_audit_rows_carry_the_region_they_were_written_in(): void
    {
        app(AuditLogger::class)->log(action: 'invoice_issued');

        $this->assertSame(
            $this->surabaya->id,
            (int) AuditLog::where('action', 'invoice_issued')->sole()->region_id,
        );
    }
}
