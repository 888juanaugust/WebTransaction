<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Onboarding\KesiapanOnboarding;
use App\Domain\Onboarding\PortalInviter;
use App\Filament\Pages\OnboardingPelanggan;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\RelationManagers\CustomerUsersRelationManager;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CustomerUser;
use App\Models\Order;
use App\Models\PriceTier;
use App\Models\Region;
use App\Models\User;
use App\Notifications\UndanganPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pilot onboarding: portal access is granted by invitation — nobody but the
 * buyer ever knows the password — and the checklist that says whether a
 * customer is actually ready is derived, never ticked.
 */
class OnboardingPilotTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
        $this->company = Company::factory()->create(['nama' => 'Bengkel Pilot Jaya']);
    }

    // --- the invitation ------------------------------------------------------

    public function test_creating_a_portal_account_sends_an_invitation_not_a_password(): void
    {
        Notification::fake();

        Livewire::actingAs($this->owner)
            ->test(CustomerUsersRelationManager::class, [
                'ownerRecord' => $this->company,
                'pageClass' => EditCompany::class,
            ])
            ->callTableAction('create', data: [
                'name' => 'Pak Budi',
                'email' => 'budi@pilot.example',
                'telepon' => '081234567890',
                'is_active' => true,
            ])
            ->assertHasNoTableActionErrors();

        $akun = CustomerUser::query()->sole();

        // The invitation carries a signed link to the portal's own
        // set-password page — the buyer chooses; staff never see it.
        Notification::assertSentTo($akun, UndanganPortal::class, function (UndanganPortal $n) {
            return str_contains($n->url, '/portal/password-reset/reset')
                && str_contains($n->url, 'token=')
                && $n->namaPerusahaan === 'Bengkel Pilot Jaya';
        });

        // The account was born with a password nobody typed or was shown.
        $this->assertFalse(Hash::check('password', $akun->password));
        $this->assertSame($this->owner->id, (int) $akun->created_by);

        // Both halves audited: access granted, invitation sent.
        $this->assertSame(1, AuditLog::query()->where('action', 'customer_portal_access_granted')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'customer_portal_invite_sent')->count());
    }

    public function test_the_invitation_can_be_resent(): void
    {
        Notification::fake();

        $akun = CustomerUser::factory()->for($this->company)->create();

        Livewire::actingAs($this->owner)
            ->test(CustomerUsersRelationManager::class, [
                'ownerRecord' => $this->company,
                'pageClass' => EditCompany::class,
            ])
            ->callTableAction('kirimUndangan', $akun);

        Notification::assertSentToTimes($akun, UndanganPortal::class, 1);

        $audit = AuditLog::query()->where('action', 'customer_portal_invite_sent')->sole();
        $this->assertSame($this->owner->id, (int) $audit->actor_id);
    }

    public function test_a_fresh_invitation_kills_the_previous_token(): void
    {
        Notification::fake();

        $akun = CustomerUser::factory()->for($this->company)->create();

        app(PortalInviter::class)->undang($akun, $this->owner);
        app(PortalInviter::class)->undang($akun, $this->owner);

        // The broker keeps one token per user; re-inviting replaces it.
        $this->assertSame(1, DB::table('customer_password_reset_tokens')
            ->where('email', $akun->email)->count());
    }

    // --- the derived checklist ----------------------------------------------

    public function test_a_bare_customer_fails_the_steps_a_ready_one_passes(): void
    {
        $kesiapan = app(KesiapanOnboarding::class);

        $bare = Company::factory()->pending()->creditLimit(0)->create([
            'npwp' => null, 'nama_wajib_pajak' => null, 'alamat_pajak' => null,
            'email' => null,
        ]);

        $byKey = fn (Company $c) => collect($kesiapan->langkah($c))->keyBy('kunci');

        $steps = $byKey($bare);
        foreach (['data_pajak', 'disetujui', 'limit_kredit', 'tim', 'harga', 'akun_portal', 'masuk_pertama', 'order_pertama'] as $kunci) {
            $this->assertFalse($steps[$kunci]->selesai, "$kunci should not pass");
        }

        // Now dress a customer fully and watch every step derive to true.
        $ready = Company::factory()->create([
            'email' => 'toko@pilot.example',
            'price_tier_id' => PriceTier::factory()->create()->id,
        ]);
        $ready->forceFill([
            'sales_user_id' => User::factory()->sales()->create()->id,
            'marketing_user_id' => User::factory()->marketing()->create()->id,
        ])->save();
        CustomerUser::factory()->for($ready)->create(['last_login_at' => now()]);
        Order::factory()->for($ready)->create(['status' => 'submitted']);

        $steps = $byKey($ready->fresh());
        foreach ($steps as $langkah) {
            $this->assertTrue($langkah->selesai, "{$langkah->kunci} should pass: {$langkah->temuan}");
        }
        $this->assertSame($kesiapan->total(), $kesiapan->selesai($ready));
    }

    public function test_the_first_order_counts_across_regions(): void
    {
        // A split can book the customer's first order in another region's
        // documents; "has this customer ordered" must still say yes.
        $other = Region::factory()->create();

        Order::factory()->for($this->company)->create([
            'status' => 'submitted',
            'region_id' => $other->id,
        ]);

        $langkah = collect(app(KesiapanOnboarding::class)->langkah($this->company))
            ->keyBy('kunci');

        $this->assertTrue($langkah['order_pertama']->selesai);
    }

    public function test_a_draft_is_not_a_first_order(): void
    {
        Order::factory()->for($this->company)->create(['status' => 'draft']);

        $langkah = collect(app(KesiapanOnboarding::class)->langkah($this->company))
            ->keyBy('kunci');

        $this->assertFalse($langkah['order_pertama']->selesai);
    }

    // --- the screen ----------------------------------------------------------

    public function test_credit_roles_may_open_the_worklist_and_inventori_may_not(): void
    {
        $this->actingAs($this->owner, 'web')
            ->get('/admin/onboarding-pelanggan')->assertOk();

        $this->actingAs(User::factory()->sales()->create(), 'web')
            ->get('/admin/onboarding-pelanggan')->assertOk();

        // Inventori is blind to customers' credit; the checklist shows
        // credit limits, so the door stays shut.
        $this->actingAs(User::factory()->warehouse()->create(), 'web')
            ->get('/admin/onboarding-pelanggan')->assertForbidden();
    }

    public function test_the_worklist_puts_the_least_ready_customer_first(): void
    {
        $this->company->forceFill(['nama' => 'Bengkel Hampir Siap'])->save();

        Company::factory()->pending()->creditLimit(0)->create([
            'nama' => 'Toko Baru Kosong',
            'npwp' => null, 'nama_wajib_pajak' => null, 'alamat_pajak' => null,
        ]);
        Company::factory()->suspended()->create(['nama' => 'CV Beku Lama']);

        Livewire::actingAs($this->owner)
            ->test(OnboardingPelanggan::class)
            ->assertSeeInOrder(['Toko Baru Kosong', 'Bengkel Hampir Siap'])
            ->assertDontSee('CV Beku Lama');
    }
}
