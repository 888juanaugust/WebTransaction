<?php

declare(strict_types=1);

namespace App\Client\Portal\Domain;

use App\Client\Models\CustomerUser;
use App\Client\Portal\Mail\PortalInvitation;
use App\Client\Portal\PortalPanelProvider;
use App\Domain\Audit\Auditor;
use App\Domain\Shared\Locales;
use App\Models\Sales\Customer;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Staff open and close the portal's doors: a login is made under a customer
 * with a password nobody holds and an invitation to set one; it can be
 * invited again, deactivated and reactivated. Every step is audited.
 */
final class BuyerAccounts
{
    public function invite(Customer $customer, string $name, string $email, ?string $phone, User $actor): CustomerUser
    {
        $customer = $customer->fresh() ?? $customer;
        if (! $customer->is_active) {
            throw new RuntimeException(__(':name is not an active customer.', ['name' => $customer->name]));
        }
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException(__('A valid email address is needed; the invitation goes there.'));
        }
        if (CustomerUser::query()->where('email', $email)->exists()) {
            throw new RuntimeException(__(':email already has a portal account.', ['email' => $email]));
        }

        $buyer = CustomerUser::query()->create([
            'customer_id' => $customer->id, 'name' => trim($name), 'email' => $email, 'phone' => $phone ? trim($phone) : null,
            'password' => Str::password(48), 'is_active' => true, 'created_by' => $actor->id,
        ]);
        Auditor::log('portal_access_granted', $buyer, null, ['customer' => $customer->name, 'email' => $email]);
        $this->sendInvitation($buyer);

        return $buyer;
    }

    public function sendInvitation(CustomerUser $buyer): void
    {
        if (! $buyer->is_active) {
            throw new RuntimeException(__(':email is deactivated; activate the account before inviting.', ['email' => $buyer->email]));
        }
        $token = Password::broker('customer_users')->createToken($buyer);
        $url = Filament::getPanel(PortalPanelProvider::ID)->getResetPasswordUrl($token, $buyer);
        Mail::to($buyer->email)->queue((new PortalInvitation($buyer, $url))->locale(Locales::companyDefault()));
        $buyer->forceFill(['invited_at' => now()])->saveQuietly();
        Auditor::log('portal_invitation_sent', $buyer, null, ['email' => $buyer->email]);
    }

    public function deactivate(CustomerUser $buyer, User $actor): void
    {
        if (! $buyer->is_active) {
            return;
        }
        $buyer->forceFill(['is_active' => false])->saveQuietly();
        Auditor::log('portal_access_revoked', $buyer, null, ['email' => $buyer->email, 'by' => $actor->name]);
    }

    public function activate(CustomerUser $buyer, User $actor): void
    {
        if ($buyer->is_active) {
            return;
        }
        $buyer->forceFill(['is_active' => true])->saveQuietly();
        Auditor::log('portal_access_granted', $buyer, null, ['email' => $buyer->email, 'by' => $actor->name]);
    }
}
