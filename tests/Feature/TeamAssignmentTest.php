<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\TeamAssigner;
use App\Domain\Regions\RegionContext;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Region;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * One sales and one marketing per customer, and no fictional assignments.
 *
 * The pair is the organisation's unit of responsibility — the marketing seat
 * in particular is who every pending order waits on, so an assignment that
 * cannot actually do the job (wrong role, wrong region, deactivated) is a
 * queue nobody will ever empty. The assigner refuses those instead of storing
 * them.
 */
class TeamAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private TeamAssigner $assigner;

    private User $owner;

    private Company $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assigner = app(TeamAssigner::class);
        $this->owner = User::factory()->owner()->create();
        $this->pelanggan = Company::factory()->create();
    }

    private function salesDiSini(): User
    {
        return User::factory()->sales()->create(['region_id' => $this->currentRegion()->id]);
    }

    private function marketingDiSini(): User
    {
        return User::factory()->marketing()->create(['region_id' => $this->currentRegion()->id]);
    }

    public function test_a_team_is_two_seats_on_the_customer(): void
    {
        $sales = $this->salesDiSini();
        $marketing = $this->marketingDiSini();

        $this->assigner->assignSales($this->pelanggan, $sales, $this->owner);
        $this->assigner->assignMarketing($this->pelanggan, $marketing, $this->owner);

        $segar = $this->pelanggan->fresh();

        $this->assertSame($sales->id, (int) $segar->sales_user_id);
        $this->assertSame($marketing->id, (int) $segar->marketing_user_id);
    }

    public function test_both_assignments_are_audited_with_the_name(): void
    {
        /*
         * "Who approved this customer's credit" traces back through "who was
         * their marketing at the time" — the name is in the entry so the
         * answer survives the person's row changing later.
         */
        $marketing = $this->marketingDiSini();

        $this->assigner->assignMarketing($this->pelanggan, $marketing, $this->owner);

        $row = AuditLog::where('action', 'company_marketing_assigned')->sole();

        $this->assertNull($row->old_value['marketing_user_id']);
        $this->assertSame($marketing->id, $row->new_value['marketing_user_id']);
        $this->assertSame($marketing->name, $row->new_value['nama']);
        $this->assertSame($this->owner->id, $row->actor_id);
    }

    public function test_the_wrong_role_cannot_fill_a_seat(): void
    {
        /*
         * A Finance clerk named as marketing-of-record would hold approval
         * rights their role denies — the seat would say yes to credit while
         * the role matrix says they cannot.
         */
        $keuangan = User::factory()->finance()->create(['region_id' => $this->currentRegion()->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/butuh peran Marketing/');

        $this->assigner->assignMarketing($this->pelanggan, $keuangan, $this->owner);
    }

    public function test_somebody_from_another_region_cannot_be_assigned(): void
    {
        /*
         * The scope hides this customer from them entirely, so every pending
         * order would wait on a person to whom it is invisible.
         */
        $sby = Region::factory()->create(['kode' => 'SBY']);
        $salesSby = User::factory()->sales()->create(['region_id' => $sby->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/wilayah lain/');

        $this->assigner->assignSales($this->pelanggan, $salesSby, $this->owner);

        $this->assertNull($this->pelanggan->fresh()->sales_user_id);
    }

    public function test_a_marketing_may_hold_any_regions_customer(): void
    {
        /*
         * The region rule above is about visibility, and marketing sees
         * everything — global since 2026-08. So the seat crosses regions
         * freely: one marketing, customers everywhere.
         */
        $sby = Region::factory()->create(['kode' => 'SB2']);
        $pelangganSby = app(RegionContext::class)->within(
            $sby,
            fn () => Company::factory()->create(),
        );

        $marketing = User::factory()->marketing()->create(['region_id' => null]);

        $this->assigner->assignMarketing($pelangganSby, $marketing, $this->owner);

        $this->assertSame($marketing->id, (int) $pelangganSby->fresh()->marketing_user_id);
    }

    public function test_a_deactivated_account_cannot_be_assigned(): void
    {
        $mati = User::factory()->marketing()->create([
            'region_id' => $this->currentRegion()->id,
            'is_active' => false,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nonaktif/');

        $this->assigner->assignMarketing($this->pelanggan, $mati, $this->owner);
    }

    public function test_a_seat_can_be_emptied_and_that_is_audited_too(): void
    {
        $sales = $this->salesDiSini();
        $this->assigner->assignSales($this->pelanggan, $sales, $this->owner);

        $this->assigner->assignSales($this->pelanggan, null, $this->owner);

        $this->assertNull($this->pelanggan->fresh()->sales_user_id);
        $this->assertSame(2, AuditLog::where('action', 'company_sales_assigned')->count());
    }

    public function test_reassigning_the_same_person_writes_nothing(): void
    {
        $sales = $this->salesDiSini();
        $this->assigner->assignSales($this->pelanggan, $sales, $this->owner);

        $this->assigner->assignSales($this->pelanggan, $sales, $this->owner);

        $this->assertSame(1, AuditLog::where('action', 'company_sales_assigned')->count());
    }

    public function test_my_customers_answers_from_the_assignment(): void
    {
        /*
         * The query phase 2 hangs everything on: a marketing's approval
         * queue and a sales' visit list are both "customers where I hold the
         * seat".
         */
        $marketing = $this->marketingDiSini();
        $lain = Company::factory()->create();

        $this->assigner->assignMarketing($this->pelanggan, $marketing, $this->owner);

        $milik = Company::query()->managedByMarketing($marketing)->pluck('id')->all();

        $this->assertSame([$this->pelanggan->id], $milik);
        $this->assertNotContains($lain->id, $milik);
    }

    public function test_the_seats_cannot_be_mass_assigned(): void
    {
        /*
         * Two layers, same rule: the pages strip the keys, and the model
         * refuses them. Assignment exists only through TeamAssigner, because
         * that is where the audit entry and the checks live.
         *
         * Asserted against fill() on the model itself — factories unguard by
         * design, so they are not the door this lock is on.
         */
        $sales = $this->salesDiSini();

        $this->expectException(MassAssignmentException::class);

        (new Company)->fill(['sales_user_id' => $sales->id]);
    }
}
