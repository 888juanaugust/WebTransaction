<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerUser;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A buyer who forgot their password helps themselves — the alternative is a
 * phone call to whoever last knew somebody at the supplier.
 *
 * The flow rides the customer guard's own broker and its own token table:
 * staff and buyers sharing an email address must never share a token. Staff
 * deliberately have no such flow — their passwords are reset by the Owner on
 * the staff screen, where the change is audited to a person.
 */
class PortalPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_request_page_is_reachable_from_the_portal_login(): void
    {
        $this->get('/portal/password-reset/request')->assertOk();
    }

    public function test_requesting_a_reset_mails_the_buyer_and_stores_a_token_in_the_buyer_table(): void
    {
        Notification::fake();

        $buyer = CustomerUser::factory()->create(['email' => 'bengkel@pembeli.example']);

        Filament::setCurrentPanel('portal');

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $buyer->email])
            ->call('request');

        Notification::assertSentTo($buyer, ResetPassword::class);

        $this->assertDatabaseHas('customer_password_reset_tokens', ['email' => $buyer->email]);
        // And nothing bled into the staff side's table.
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_a_staff_email_gets_nothing_from_the_portal(): void
    {
        Notification::fake();

        $staff = User::factory()->finance()->create(['email' => 'finance@example.test']);

        Filament::setCurrentPanel('portal');

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $staff->email])
            ->call('request');

        /*
         * The portal's broker resolves against customer_users, so a staff
         * address is simply unknown here — no token in either table, no
         * mail. The page's response is the same either way, which is what
         * keeps this from confirming which emails exist.
         */
        Notification::assertNothingSent();
        $this->assertDatabaseCount('customer_password_reset_tokens', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_completing_the_reset_changes_the_password_and_lands_in_the_audit_log(): void
    {
        $buyer = CustomerUser::factory()->create([
            'password' => Hash::make('lama-sekali'),
        ]);

        $token = Password::broker('customer_users')->createToken($buyer);

        $status = Password::broker('customer_users')->reset(
            [
                'email' => $buyer->email,
                'password' => 'baru-dan-panjang',
                'password_confirmation' => 'baru-dan-panjang',
                'token' => $token,
            ],
            function (CustomerUser $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();

                event(new PasswordReset($user));
            },
        );

        $this->assertSame(Password::PASSWORD_RESET, $status);
        $this->assertTrue(Hash::check('baru-dan-panjang', $buyer->fresh()->password));

        // The trail: which login's credential changed, and when. No actor —
        // the buyer is not staff, and the subject already names the account.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'customer_password_reset',
            'subject_type' => CustomerUser::class,
            'subject_id' => $buyer->id,
            'actor_id' => null,
        ]);
    }

    public function test_a_used_token_does_not_work_twice(): void
    {
        $buyer = CustomerUser::factory()->create();
        $token = Password::broker('customer_users')->createToken($buyer);

        $reset = fn () => Password::broker('customer_users')->reset(
            [
                'email' => $buyer->email,
                'password' => 'baru-dan-panjang',
                'password_confirmation' => 'baru-dan-panjang',
                'token' => $token,
            ],
            fn (CustomerUser $user, string $password) => $user
                ->forceFill(['password' => Hash::make($password)])->save(),
        );

        $this->assertSame(Password::PASSWORD_RESET, $reset());
        $this->assertSame(Password::INVALID_TOKEN, $reset());
    }
}
