<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Access\TeamAssigner;
use App\Filament\Resources\StoreVisits\StoreVisitResource;
use App\Models\Company;
use App\Models\StoreVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The visit log's gates: who opens it, who records into it, and whose
 * rows each seat reads.
 */
class StoreVisitScreenTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roles')]
    public function test_who_may_open_the_visit_log(Role $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(StoreVisitResource::getUrl());

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public static function roles(): array
    {
        return [
            'sales' => [Role::Sales, true],
            'marketing' => [Role::Marketing, true],
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
            'inventori' => [Role::Warehouse, false],
        ];
    }

    public function test_only_sales_gets_the_record_button(): void
    {
        $this->actingAs(User::factory()->sales()->create(), 'web');
        $this->assertTrue(StoreVisitResource::canCreate());

        $this->actingAs(User::factory()->marketing()->create(), 'web');
        $this->assertFalse(StoreVisitResource::canCreate());
    }

    public function test_each_seat_reads_its_own_rows(): void
    {
        $region = $this->currentRegion();
        $owner = User::factory()->owner()->create();
        $salesA = User::factory()->sales()->create(['region_id' => $region->id]);
        $salesB = User::factory()->sales()->create(['region_id' => $region->id]);
        $marketing = User::factory()->marketing()->create(['region_id' => null]);

        $tokoA = Company::factory()->create();
        $tokoB = Company::factory()->create();
        app(TeamAssigner::class)->assignSales($tokoA, $salesA, $owner);
        app(TeamAssigner::class)->assignSales($tokoB, $salesB, $owner);
        app(TeamAssigner::class)->assignMarketing($tokoA, $marketing, $owner);

        $visitA = StoreVisit::factory()->create(['sales_user_id' => $salesA->id, 'company_id' => $tokoA->id]);
        StoreVisit::factory()->create(['sales_user_id' => $salesB->id, 'company_id' => $tokoB->id]);

        // A sales reads only their own visits.
        $this->actingAs($salesA, 'web');
        $this->assertSame([$visitA->id], StoreVisitResource::getEloquentQuery()->pluck('id')->all());

        // A marketing reads their customers' visits, whoever made them.
        $this->actingAs($marketing, 'web');
        $this->assertSame([$visitA->id], StoreVisitResource::getEloquentQuery()->pluck('id')->all());

        // Finance reads everything — theirs is the archive.
        $this->actingAs(User::factory()->finance()->create(), 'web');
        $this->assertSame(2, StoreVisitResource::getEloquentQuery()->count());
    }

    public function test_visits_are_immutable_from_the_screen(): void
    {
        $this->actingAs(User::factory()->owner()->create(), 'web');
        $visit = StoreVisit::factory()->create();

        $this->assertFalse(StoreVisitResource::canEdit($visit));
        $this->assertFalse(StoreVisitResource::canDelete($visit));
    }
}
