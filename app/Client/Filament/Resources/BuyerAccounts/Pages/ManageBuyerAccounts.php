<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\BuyerAccounts\Pages;

use App\Client\Filament\Resources\BuyerAccounts\BuyerAccountResource;
use App\Client\Portal\Domain\BuyerAccounts;
use App\Filament\Support\ManageMaster;
use App\Models\Sales\Customer;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Inviting goes through the domain service. */
class ManageBuyerAccounts extends ManageMaster
{
    protected static string $resource = BuyerAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Invite a buyer'))
                ->using(function (array $data): Model {
                    try {
                        return app(BuyerAccounts::class)->invite(Customer::query()->findOrFail((int) $data['customer_id']), (string) $data['name'], (string) $data['email'], $data['phone'] ?? null, auth()->user());
                    } catch (RuntimeException $e) {
                        Notification::make()->title(__('Cannot invite'))->body($e->getMessage())->danger()->persistent()->send();
                        throw new Halt;
                    }
                })
                ->successNotificationTitle(__('Invitation sent')),
        ];
    }
}
