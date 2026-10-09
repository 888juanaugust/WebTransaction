<?php

namespace Tests\Feature\Client;

use App\Client\Access\CentralGroups;
use App\Client\Domain\Teams\TeamAssigner;
use App\Models\Company\AuditLog;
use App\Models\Company\Branch;
use App\Models\Sales\Customer;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use RuntimeException;
use Tests\TestCase;

/** A customer's team: one sales who works in its branch, one marketing who sees every branch, assigned by the owner and audited. */
class TeamAssignerTest extends TestCase
{
    private User $owner;

    private Customer $customer;

    private Branch $jakarta;

    private Branch $surabaya;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = $this->actingAsAdmin();
        $this->jakarta = Branch::default();
        $this->jakarta->forceFill(['code' => 'JKT', 'used_all_user' => false])->save();
        $this->surabaya = Branch::query()->create(['name' => 'Surabaya', 'code' => 'SBY', 'used_all_user' => false, 'is_active' => true]);
        $this->customer = $this->sampleCustomer(['branch_id' => $this->jakarta->id]);
    }

    private function member(string $group, array $branches = []): User
    {
        $user = User::factory()->create(['is_active' => true]);
        AccessGroup::query()->where('name', $group)->firstOrFail()->users()->attach($user);
        $user->branches()->sync(collect($branches)->map(fn (Branch $b) => $b->id)->all());

        return $user;
    }

    public function test_the_seeded_groups_shape_the_roles(): void
    {
        $this->assertNotNull(CentralGroups::find(CentralGroups::MARKETING));
        $this->assertNotNull(CentralGroups::find(CentralGroups::INVENTORY));
        $this->assertFalse(CentralGroups::find(CentralGroups::SALES)->specialRights()->where('right', 'approve_transactions')->exists(), 'sales never approves');
        $this->assertTrue(CentralGroups::find(CentralGroups::MARKETING)->specialRights()->where('right', 'approve_transactions')->exists());
    }

    public function test_the_owner_assigns_both_seats_and_the_change_is_audited(): void
    {
        $sales = $this->member(CentralGroups::SALES, [$this->jakarta]);
        $marketing = $this->member(CentralGroups::MARKETING, [$this->jakarta, $this->surabaya]);

        app(TeamAssigner::class)->assign($this->customer, $sales, $marketing, $this->owner);

        $this->customer->refresh();
        $this->assertSame($sales->id, $this->customer->sales_user_id);
        $this->assertSame($marketing->id, $this->customer->marketing_user_id);
        $log = AuditLog::query()->where('action', 'team_assigned')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(['sales_user_id' => null, 'marketing_user_id' => null], $log->meta['before']);
        $this->assertSame(['sales_user_id' => $sales->id, 'marketing_user_id' => $marketing->id], $log->meta['after']);
        $this->assertTrue(TeamAssigner::holdsApprovalSeat($marketing, $this->customer));
        $this->assertTrue(TeamAssigner::holdsApprovalSeat($this->owner, $this->customer));
        $this->assertFalse(TeamAssigner::holdsApprovalSeat($sales, $this->customer));
    }

    public function test_only_an_administrator_assigns(): void
    {
        $sales = $this->member(CentralGroups::SALES, [$this->jakarta]);

        $this->expectException(RuntimeException::class);
        app(TeamAssigner::class)->assign($this->customer, $sales, null, $sales);
    }

    public function test_the_sales_must_work_in_the_customers_branch(): void
    {
        $sales = $this->member(CentralGroups::SALES, [$this->surabaya]);

        $this->expectExceptionMessage('cannot work in');
        app(TeamAssigner::class)->assign($this->customer, $sales, null, $this->owner);
    }

    public function test_the_seats_need_the_matching_group(): void
    {
        $finance = $this->member(CentralGroups::FINANCE, [$this->jakarta, $this->surabaya]);

        $this->expectExceptionMessage('Marketing group');
        app(TeamAssigner::class)->assign($this->customer, null, $finance, $this->owner);
    }

    public function test_the_marketing_must_see_every_branch(): void
    {
        $marketing = $this->member(CentralGroups::MARKETING, [$this->jakarta]);

        $this->expectExceptionMessage('every branch');
        app(TeamAssigner::class)->assign($this->customer, null, $marketing, $this->owner);
    }

    public function test_an_inactive_user_holds_no_seat(): void
    {
        $sales = $this->member(CentralGroups::SALES, [$this->jakarta]);
        $sales->forceFill(['is_active' => false])->save();

        $this->expectExceptionMessage('not an active user');
        app(TeamAssigner::class)->assign($this->customer, $sales, null, $this->owner);
    }

    public function test_the_teams_screen_lists_customers_with_their_seats(): void
    {
        $sales = $this->member(CentralGroups::SALES, [$this->jakarta]);
        app(TeamAssigner::class)->assign($this->customer, $sales, null, $this->owner);

        $this->get('/admin/client/teams')->assertOk()->assertSee($this->customer->name)->assertSee($sales->name);
    }
}
