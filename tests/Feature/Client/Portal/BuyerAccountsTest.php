<?php

namespace Tests\Feature\Client\Portal;

use App\Client\Filament\Resources\BuyerAccounts\Pages\ManageBuyerAccounts;
use App\Client\Models\CustomerUser;
use App\Client\Portal\Domain\BuyerAccounts;
use App\Client\Portal\Mail\PortalInvitation;
use App\Client\Portal\PortalActor;
use App\Models\Company\AuditLog;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** Staff open the portal's doors: an invitation with a set-password link, invited again, deactivated; the Portal user is seeded. */
class BuyerAccountsTest extends TestCase
{
    use OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        Mail::fake();
    }

    private function accounts(): BuyerAccounts
    {
        return app(BuyerAccounts::class);
    }

    public function test_the_portal_user_and_its_group_are_seeded_once(): void
    {
        $portal = PortalActor::user();
        $this->assertSame('operator', $portal->access_type);
        $this->assertTrue($portal->is_active);
        $this->assertSame(['Portal'], $portal->accessGroups()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['view', 'create', 'print'], AccessGroup::query()->where('name', 'Portal')->firstOrFail()->load('rights')->rightsMatrix()['customer__sales-order']);
        $this->assertCount(2, $portal->branches()->get(), 'every branch');

        $this->seed();
        $this->assertSame(1, User::query()->where('email', PortalActor::email())->count());
    }

    public function test_marketing_invites_a_buyer_and_the_link_sets_a_password(): void
    {
        $this->actingAs($this->marketing);
        $buyer = $this->accounts()->invite($this->customer, 'Budi', 'Budi@Toko.test', '0812', $this->marketing);

        $this->assertSame('budi@toko.test', $buyer->email, 'lower-cased');
        $this->assertSame($this->marketing->id, $buyer->created_by);
        $this->assertNotNull($buyer->fresh()->invited_at);
        $this->assertTrue($buyer->is_active);

        $url = null;
        Mail::assertQueued(PortalInvitation::class, function (PortalInvitation $mail) use ($buyer, &$url): bool {
            $url = $mail->url;

            return $mail->hasTo('budi@toko.test') && $mail->buyer->is($buyer) && str_contains($mail->url, '/portal/password-reset/reset') && str_contains($mail->url, 'signature=');
        });
        $this->assertStringContainsString($buyer->name, (new PortalInvitation($buyer, (string) $url))->render());
        auth()->forgetUser();
        $this->get((string) $url)->assertOk();

        $actions = AuditLog::query()->where('document_type', 'customer_user')->pluck('action')->all();
        $this->assertContains('portal_access_granted', $actions);
        $this->assertContains('portal_invitation_sent', $actions);
    }

    public function test_an_invitation_needs_an_active_customer_a_valid_address_and_a_new_one(): void
    {
        $this->actingAs($this->marketing);
        try {
            $this->accounts()->invite($this->customer, 'X', 'not-an-address', null, $this->marketing);
            $this->fail('bad email');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('valid email', $e->getMessage());
        }
        $this->accounts()->invite($this->customer, 'Budi', 'budi@toko.test', null, $this->marketing);
        try {
            $this->accounts()->invite($this->customer, 'Budi again', 'BUDI@toko.test', null, $this->marketing);
            $this->fail('duplicate');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already has a portal account', $e->getMessage());
        }
        $closed = $this->sampleCustomer(['name' => 'Closed', 'number' => 'C-CLOSED', 'is_active' => false, 'branch_id' => $this->jakarta->id]);
        try {
            $this->accounts()->invite($closed, 'Y', 'y@toko.test', null, $this->marketing);
            $this->fail('inactive customer');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not an active customer', $e->getMessage());
        }
        $this->assertSame(1, CustomerUser::query()->count());
    }

    public function test_inviting_again_deactivating_and_a_password_reset_are_on_the_record(): void
    {
        $this->actingAs($this->finance);
        $buyer = $this->accounts()->invite($this->customer, 'Budi', 'budi@toko.test', null, $this->finance);
        $this->accounts()->sendInvitation($buyer);
        Mail::assertQueuedCount(2);

        $this->accounts()->deactivate($buyer, $this->finance);
        $this->assertFalse($buyer->fresh()->is_active);
        $this->assertFalse($buyer->fresh()->canAccessPanel(Filament::getPanel('portal')));
        try {
            $this->accounts()->sendInvitation($buyer->fresh());
            $this->fail('invited a deactivated account');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('deactivated', $e->getMessage());
        }
        $this->accounts()->activate($buyer->fresh(), $this->finance);
        $this->assertTrue($buyer->fresh()->is_active);

        event(new PasswordReset($buyer->fresh()));
        $actions = AuditLog::query()->where('document_type', 'customer_user')->pluck('action')->all();
        $this->assertContains('portal_access_revoked', $actions);
        $this->assertContains('portal_password_reset', $actions);
    }

    public function test_the_screen_invites_and_sales_only_reads(): void
    {
        $this->actingAs($this->marketing);
        $this->get('/admin/client/buyer-accounts')->assertOk();
        Livewire::test(ManageBuyerAccounts::class)
            ->callAction('create', ['customer_id' => $this->customer->id, 'name' => 'Budi', 'email' => 'budi@toko.test', 'phone' => '0812'])
            ->assertHasNoActionErrors();
        $buyer = CustomerUser::query()->sole();
        $this->assertSame($this->marketing->id, $buyer->created_by);
        Mail::assertQueuedCount(1);

        Livewire::test(ManageBuyerAccounts::class)->callTableAction('invite', $buyer)->assertHasNoTableActionErrors();
        Mail::assertQueuedCount(2);
        Livewire::test(ManageBuyerAccounts::class)->callTableAction('edit', $buyer, ['name' => 'Budi S.', 'phone' => '0813', 'is_active' => false])->assertHasNoTableActionErrors();
        $this->assertFalse($buyer->fresh()->is_active);
        $this->assertSame('Budi S.', $buyer->fresh()->name);

        $this->actingAs($this->sales);
        $this->get('/admin/client/buyer-accounts')->assertOk();
        Livewire::test(ManageBuyerAccounts::class)->assertActionHidden('create');
    }
}
