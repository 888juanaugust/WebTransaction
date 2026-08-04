<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CustomerUser;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Buyers and staff authenticate on different guards against different tables.
 *
 * These tests exist because the failure mode is not a broken page — it is a
 * buyer seeing another customer's trading data, or reaching the admin panel.
 */
class CustomerPortalAccessTest extends TestCase
{
    use RefreshDatabase;

    private function portalPanel(): Panel
    {
        return Filament::getPanel('portal');
    }

    private function adminPanel(): Panel
    {
        return Filament::getPanel('admin');
    }

    // --- the two doors are genuinely separate ------------------------------

    public function test_the_portal_and_admin_panels_use_different_guards(): void
    {
        $this->assertSame('customer', $this->portalPanel()->getAuthGuard());
        $this->assertSame('web', $this->adminPanel()->getAuthGuard());
    }

    public function test_a_buyer_may_reach_the_portal(): void
    {
        $buyer = CustomerUser::factory()->create([
            'company_id' => Company::factory()->create(['status' => Company::STATUS_ACTIVE]),
        ]);

        $this->assertTrue($buyer->canAccessPanel($this->portalPanel()));
    }

    /**
     * The one that matters. A buyer must not be able to reach the admin panel
     * even if a route or a link somehow points them at it.
     */
    public function test_a_buyer_may_never_reach_the_admin_panel(): void
    {
        $buyer = CustomerUser::factory()->create([
            'company_id' => Company::factory()->create(['status' => Company::STATUS_ACTIVE]),
        ]);

        $this->assertFalse($buyer->canAccessPanel($this->adminPanel()));
    }

    public function test_a_buyer_session_is_not_authenticated_on_the_staff_guard(): void
    {
        $buyer = CustomerUser::factory()->create([
            'company_id' => Company::factory()->create(['status' => Company::STATUS_ACTIVE]),
        ]);

        $this->actingAs($buyer, 'customer');

        $this->assertTrue(auth('customer')->check());
        $this->assertFalse(auth('web')->check(), 'a buyer must carry no staff identity');
    }

    public function test_an_unauthenticated_visitor_is_redirected_away_from_the_portal(): void
    {
        $this->get('/portal')->assertRedirect();
    }

    public function test_a_staff_member_cannot_sign_in_to_the_portal_guard(): void
    {
        User::factory()->owner()->create(['email' => 'owner@example.test']);

        // Staff live in `users`; the portal guard only ever looks at
        // `customer_users`, so this address does not exist to it.
        $this->assertFalse(
            auth('customer')->attempt(['email' => 'owner@example.test', 'password' => 'password'])
        );
    }

    public function test_a_buyer_cannot_sign_in_to_the_staff_guard(): void
    {
        CustomerUser::factory()->create([
            'email' => 'buyer@example.test',
            'company_id' => Company::factory()->create(),
        ]);

        $this->assertFalse(
            auth('web')->attempt(['email' => 'buyer@example.test', 'password' => 'password'])
        );
    }

    // --- account status gates access ---------------------------------------

    public function test_a_deactivated_buyer_login_is_refused(): void
    {
        $buyer = CustomerUser::factory()->inactive()->create([
            'company_id' => Company::factory()->create(['status' => Company::STATUS_ACTIVE]),
        ]);

        $this->assertFalse($buyer->canAccessPanel($this->portalPanel()));
    }

    public function test_a_buyer_whose_company_is_not_yet_approved_is_refused(): void
    {
        $buyer = CustomerUser::factory()->create([
            'company_id' => Company::factory()->pending()->create(),
        ]);

        $this->assertFalse($buyer->canAccessPanel($this->portalPanel()));
    }

    public function test_a_buyer_whose_company_is_suspended_is_refused(): void
    {
        $buyer = CustomerUser::factory()->create([
            'company_id' => Company::factory()->suspended()->create(),
        ]);

        $this->assertFalse($buyer->canAccessPanel($this->portalPanel()));
    }

    // --- data scoping -------------------------------------------------------

    /**
     * A buyer sees their own company's data and nobody else's. The widgets all
     * scope on the authenticated buyer's company_id; this pins the underlying
     * relationship those queries rely on.
     */
    public function test_a_buyer_is_bound_to_exactly_one_company(): void
    {
        $mine = Company::factory()->create(['nama' => 'Bengkel Saya']);
        $theirs = Company::factory()->create(['nama' => 'Bengkel Orang Lain']);

        $buyer = CustomerUser::factory()->create(['company_id' => $mine->id]);

        Order::factory()->count(2)->create(['company_id' => $mine->id]);
        Order::factory()->count(3)->create(['company_id' => $theirs->id]);

        Invoice::factory()->create(['company_id' => $mine->id]);
        Invoice::factory()->count(4)->create(['company_id' => $theirs->id]);

        $this->assertSame($mine->id, $buyer->company_id);
        $this->assertSame(2, Order::where('company_id', $buyer->company_id)->count());
        $this->assertSame(1, Invoice::where('company_id', $buyer->company_id)->count());
    }

    public function test_deleting_a_company_removes_its_portal_logins(): void
    {
        $company = Company::factory()->create();
        CustomerUser::factory()->count(2)->create(['company_id' => $company->id]);

        $company->delete();

        $this->assertSame(0, CustomerUser::count());
    }
}
