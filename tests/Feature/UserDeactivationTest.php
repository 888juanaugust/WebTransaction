<?php

namespace Tests\Feature;

use App\Domain\Access\UserDeactivation;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Posting\DocumentRepository;
use App\Filament\Resources\Settings\Users\Pages\EditUser;
use App\Filament\Resources\Settings\Users\Pages\ListUsers;
use App\Models\Company\TransactionApprover;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** Users are deactivated, never deleted; deactivating is refused while an approval still needs them. */
class UserDeactivationTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $this->seed();
        $this->enableAllModules();
        $this->admin = $this->actingAsAdmin();
    }

    private function operator(string $name): User
    {
        return User::factory()->create(['name' => $name, 'email' => strtolower($name).'@example.test']);
    }

    private function order(string $number): PurchaseOrder
    {
        $vendor = $this->sampleVendor(['number' => 'V-'.$number]);
        $item = $this->sampleItem(['number' => 'I-'.$number]);
        $po = PurchaseOrder::query()->create(['number' => $number, 'trans_date' => '2026-11-10', 'vendor_id' => $vendor->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => $this->admin->id]);
        $po->lines()->create(['sort' => 0, 'item_id' => $item->id, 'quantity' => 30, 'unit_id' => $item->unit1_id, 'base_quantity' => 30, 'unit_price' => 100_000, 'warehouse_id' => Warehouse::default()->id]);
        $po->refreshTotal();
        app(DocumentRepository::class)->created($po);

        return $po->fresh();
    }

    private function rule(string $kind): TransactionApprover
    {
        return TransactionApprover::query()->create(['transaction_type' => 'purchase_order', 'min_amount' => 1_000_000, 'rule' => $kind, 'is_active' => true]);
    }

    private function signIn(string $email): Testable
    {
        auth()->logout();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Livewire::test(Login::class)->fillForm(['email' => $email, 'password' => 'password'])->call('authenticate');
    }

    public function test_users_have_no_delete_and_cannot_be_deleted(): void
    {
        $rina = $this->operator('Rina');
        Livewire::test(ListUsers::class)->assertTableActionDoesNotExist('delete')->assertTableActionVisible('deactivate', $rina)->assertTableActionHidden('deactivate', $this->admin);
        Livewire::test(EditUser::class, ['record' => $rina->getRouteKey()])->assertActionDoesNotExist('delete')->assertActionVisible('deactivate');

        $this->expectException(RuntimeException::class);
        try {
            $rina->delete();
        } finally {
            $this->assertNotNull($rina->fresh());
        }
    }

    public function test_a_deactivated_user_cannot_sign_in_keeps_their_name_and_comes_back_when_reactivated(): void
    {
        $rina = $this->operator('Rina');
        $po = $this->order('PO-1');
        $po->forceFill(['created_by' => $rina->id])->saveQuietly();

        Livewire::test(ListUsers::class)->callTableAction('deactivate', $rina);
        $this->assertFalse($rina->fresh()->is_active);
        $meta = json_decode((string) DB::table('audit_logs')->where('action', 'updated')->where('reference', 'Rina')->latest('id')->value('meta'), true);
        $this->assertEquals(['before' => ['is_active' => true], 'after' => ['is_active' => false]], $meta);
        $this->assertSame('Rina', User::query()->find($po->fresh()->created_by)?->name, 'their name stays on what they entered');

        $this->signIn('rina@example.test')->assertHasFormErrors(['email']);
        $this->assertGuest();

        $this->actingAs($this->admin);
        Livewire::test(ListUsers::class)->callTableAction('reactivate', $rina->fresh());
        $this->assertTrue($rina->fresh()->is_active);
        $this->signIn('rina@example.test')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($rina->fresh());
    }

    public function test_an_open_session_ends_at_the_next_click(): void
    {
        $rina = $this->operator('Rina');
        $rina->update(['is_active' => false]);

        $this->actingAs($rina->fresh())->withHeader('X-Livewire', 'true')->get('/admin')->assertForbidden();
        $this->assertGuest();

        $this->flushHeaders()->actingAs($rina->fresh())->get('/admin')->assertRedirect('/admin/login');
        $this->assertGuest();
        $this->assertSame(['Your account has been deactivated.'], collect(session('filament.notifications'))->pluck('title')->all());
    }

    public function test_yourself_and_the_last_active_administrator_are_refused(): void
    {
        $this->assertSame(['You cannot deactivate your own account.'], UserDeactivation::reasons($this->admin, $this->admin));

        $other = User::factory()->create(['name' => 'Owner', 'access_type' => 'administrator']);
        $this->assertSame([], UserDeactivation::reasons($other, $this->admin), 'another administrator remains');
        User::query()->where('access_type', 'administrator')->whereKeyNot($other->id)->update(['is_active' => false]); // the seeded installer administrator too
        $this->assertSame(['Owner is the only active administrator.'], UserDeactivation::reasons($other, null));
    }

    public function test_a_named_approver_and_the_last_member_of_an_approving_group_are_refused(): void
    {
        $rina = $this->operator('Rina');
        $rule = $this->rule(TransactionApprover::ANY_ONE);
        $rule->approvers()->attach($rina, ['sort' => 0]);

        Livewire::test(ListUsers::class)->callTableAction('deactivate', $rina)->assertNotified('Cannot deactivate Rina');
        $this->assertTrue($rina->fresh()->is_active);
        $this->assertStringContainsString('Rina approves under Purchase Order from Rp 1.000.000', implode(' ', UserDeactivation::reasons($rina, $this->admin)));

        Livewire::test(EditUser::class, ['record' => $rina->getRouteKey()])->fillForm(['is_active' => false])->call('save')->assertHasFormErrors(['is_active']);
        $this->assertTrue($rina->fresh()->is_active);

        $rule->approvers()->detach($rina);
        $group = AccessGroup::query()->create(['name' => 'Approvers']);
        $group->users()->attach([$rina->id, User::factory()->create(['is_active' => false])->id]);
        $rule->groups()->attach($group, ['sort' => 0]);
        $this->assertSame(['Rina is the only active member of Approvers, which approves under Purchase Order from Rp 1.000.000: add someone to the group first.'], UserDeactivation::reasons($rina, $this->admin));

        $group->users()->attach($this->operator('Budi'));
        $this->assertSame([], UserDeactivation::reasons($rina, $this->admin));
        Livewire::test(ListUsers::class)->callTableAction('deactivate', $rina);
        $this->assertFalse($rina->fresh()->is_active);
    }

    public function test_a_document_waiting_in_order_for_them_is_named_until_they_decide(): void
    {
        $andi = $this->operator('Andi');
        $rina = $this->operator('Rina');
        $rule = $this->rule(TransactionApprover::IN_ORDER);
        $rule->approvers()->attach([$andi->id => ['sort' => 0], $rina->id => ['sort' => 1]]);

        // Undecided: taking Rina off the rule frees her at once.
        $first = $this->order('PO-1');
        $rule->approvers()->detach($rina);
        $this->assertSame([], app(ApprovalEngine::class)->waitingOn($rina));
        $rule->approvers()->attach($rina, ['sort' => 1]);
        app(DocumentRepository::class)->delete($first);

        // Half decided: the request keeps its approvers, so the document still needs her.
        $po = $this->order('PO-2');
        app(ApprovalEngine::class)->approve($po, $andi);
        $rule->approvers()->detach($rina);
        $this->assertSame(['PO-2 cannot be approved without Rina: they decide first, or edit the document so it asks again under the current rules.'], UserDeactivation::reasons($rina, $this->admin));

        $this->assertTrue(app(ApprovalEngine::class)->approve($po, $rina));
        $this->assertSame([], UserDeactivation::reasons($rina, $this->admin));
    }

    public function test_two_factor_secrets_never_reach_the_activity_log(): void
    {
        $rina = $this->operator('Rina');
        $rina->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $rina->saveAppAuthenticationRecoveryCodes(['alpha-111', 'beta-222']);
        $rina->saveAppAuthenticationRecoveryCodes(['gamma-333']);
        $rina->saveAppAuthenticationSecret(null);

        foreach (['JBSWY3DPEHPK3PXP', 'alpha-111', 'gamma-333'] as $secret) {
            $this->assertFalse(DB::table('audit_logs')->whereRaw('meta::text like ?', ["%{$secret}%"])->exists(), "{$secret} is in the activity log");
        }
        $this->assertSame(4, DB::table('audit_logs')->where('reference', 'Rina')->where('action', 'updated')->whereRaw('meta::text like ?', ['%(changed, not shown)%'])->count(), 'each change is still recorded');
    }
}
