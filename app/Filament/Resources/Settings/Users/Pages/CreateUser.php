<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\Users\Pages;

use App\Filament\Resources\Settings\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        // The first password is chosen by whoever made the account: the user replaces it at the first sign-in.
        $this->getRecord()->forceFill(['password_change_required' => true])->save();
        $this->getRecord()->logMembershipChange(['groups' => [], 'branches' => []]);
    }
}
