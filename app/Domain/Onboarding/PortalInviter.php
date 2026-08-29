<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Domain\Audit\AuditLogger;
use App\Models\CustomerUser;
use App\Models\User;
use App\Notifications\UndanganPortal;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Password;

/**
 * Hands a buyer their portal access without anybody knowing their password.
 *
 * Staff create the account; this sends a set-your-password link on the
 * buyer's own broker — the same token table and expiry as "lupa kata sandi",
 * so an invitation that leaks is exactly as dangerous as a reset email that
 * leaks: single-use, signed, and dead in an hour. The alternative — staff
 * typing a password and reading it out over WhatsApp — is how a pilot
 * customer's login ends up known to three people before their first order.
 */
class PortalInviter
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function undang(CustomerUser $akun, ?User $actor = null): void
    {
        $token = Password::broker('customer_users')->createToken($akun);

        $akun->notify(new UndanganPortal(
            url: Filament::getPanel('portal')->getResetPasswordUrl($token, $akun),
            namaPerusahaan: $akun->company->nama,
        ));

        $this->audit->log(
            action: 'customer_portal_invite_sent',
            subject: $akun,
            newValue: ['email' => $akun->email],
            actor: $actor,
        );
    }
}
