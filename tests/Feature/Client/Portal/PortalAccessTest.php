<?php

namespace Tests\Feature\Client\Portal;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Client\Support\Buyer;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** The portal is its own door: the customer guard, active logins of active customers, never the staff panel. */
class PortalAccessTest extends TestCase
{
    use Buyer, OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        auth()->forgetUser();
    }

    public function test_the_login_page_carries_the_company_name_and_the_reset_link(): void
    {
        app(Preferensi::class)->set(PreferensiKey::CompanyName, 'Central Parts');

        $this->get('/portal/login')->assertOk()->assertSee('Central Parts')->assertSee('Portal');
        $this->get('/portal/password-reset/request')->assertOk();
        $this->get('/portal')->assertRedirect('/portal/login');
    }

    public function test_a_buyer_signs_in_with_their_password_and_the_login_is_stamped(): void
    {
        $buyer = $this->buyer();
        Filament::setCurrentPanel(Filament::getPanel('portal'));

        Livewire::test(Login::class)->fillForm(['email' => $buyer->email, 'password' => 'secret-password'])->call('authenticate')->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($buyer, 'customer');
        $this->assertNotNull($buyer->fresh()->last_login_at);
        $this->get('/portal')->assertOk()->assertSee('Home');
    }

    public function test_a_wrong_password_or_a_staff_email_is_refused(): void
    {
        $this->buyer(attributes: ['email' => 'buyer@example.test']);
        Filament::setCurrentPanel(Filament::getPanel('portal'));

        Livewire::test(Login::class)->fillForm(['email' => 'buyer@example.test', 'password' => 'wrong'])->call('authenticate')->assertHasFormErrors(['email']);
        Livewire::test(Login::class)->fillForm(['email' => $this->owner->email, 'password' => 'password'])->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest('customer');
    }

    public function test_an_inactive_login_is_signed_out(): void
    {
        $this->actingAsBuyer($this->buyer(attributes: ['is_active' => false]));

        $this->get('/portal')->assertRedirect('/portal/login');
        $this->assertGuest('customer');
    }

    public function test_a_login_of_an_inactive_customer_is_signed_out(): void
    {
        $closed = $this->sampleCustomer(['name' => 'Closed shop', 'number' => 'C-CLOSED', 'is_active' => false, 'branch_id' => $this->jakarta->id]);
        $this->actingAsBuyer($this->buyer($closed));

        $this->get('/portal')->assertRedirect('/portal/login');
        $this->assertGuest('customer');
    }

    public function test_a_buyer_session_never_opens_the_staff_panel(): void
    {
        $this->actingAsBuyer($this->buyer());
        $this->get('/portal')->assertOk();

        $this->assertGuest('web');
        $this->freshRequest();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        auth()->shouldUse('web');
        $this->get('/admin/dashboard')->assertRedirect('/admin/login');
    }

    public function test_a_staff_session_never_opens_the_portal(): void
    {
        $this->actingAsStaff($this->owner);
        $this->get('/admin/dashboard')->assertOk();

        $this->assertGuest('customer');
        $this->freshRequest();
        Filament::setCurrentPanel(Filament::getPanel('portal'));
        auth()->shouldUse('customer');
        $this->get('/portal')->assertRedirect('/portal/login');
    }

    public function test_the_portal_speaks_the_companys_language(): void
    {
        app(Preferensi::class)->set(PreferensiKey::Language, 'id');
        $this->actingAsBuyer($this->buyer());

        $this->get('/portal')->assertOk()->assertSee('Beranda');
    }

    public function test_a_buyer_who_chose_a_language_gets_it(): void
    {
        app(Preferensi::class)->set(PreferensiKey::Language, 'id');
        $this->actingAsBuyer($this->buyer(attributes: ['locale' => 'en']));

        $this->get('/portal')->assertOk()->assertSee('Home');
    }
}
