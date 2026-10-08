<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\AccessGroups\Pages;

use App\Filament\Resources\Settings\AccessGroups\AccessGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAccessGroup extends CreateRecord
{
    use HandlesRights;

    protected static string $resource = AccessGroupResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->liftRights($data);
    }

    protected function afterCreate(): void
    {
        $this->syncRights();
    }
}
