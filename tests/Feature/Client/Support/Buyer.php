<?php

namespace Tests\Feature\Client\Support;

use App\Client\Models\CustomerUser;
use App\Models\Sales\Customer;
use App\Models\User;
use Filament\Facades\Filament;

/** A buyer's login on a customer, and the portal panel as the current one. */
trait Buyer
{
    protected function buyer(?Customer $customer = null, array $attributes = []): CustomerUser
    {
        $customer ??= $this->customer;

        return CustomerUser::query()->create(array_merge([
            'customer_id' => $customer->id,
            'name' => 'Buyer at '.$customer->name,
            'email' => 'buyer-'.uniqid().'@example.test',
            'password' => 'secret-password',
            'is_active' => true,
        ], $attributes));
    }

    protected function actingAsBuyer(CustomerUser $buyer): CustomerUser
    {
        Filament::setCurrentPanel(Filament::getPanel('portal'));
        auth()->shouldUse('customer');
        $this->actingAs($buyer, 'customer');

        return $buyer;
    }

    /** Back to a staff request in the same test: the admin panel, the web guard, this user. */
    protected function actingAsStaff(User $user): User
    {
        $this->freshRequest();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        auth()->shouldUse('web');
        $this->actingAs($user);

        return $user;
    }
}
