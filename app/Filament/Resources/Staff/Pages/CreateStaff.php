<?php

declare(strict_types=1);

namespace App\Filament\Resources\Staff\Pages;

use App\Domain\Access\Role;
use App\Domain\Access\StaffRegistrar;
use App\Filament\Resources\Staff\StaffResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStaff extends CreateRecord
{
    protected static string $resource = StaffResource::class;

    public function getTitle(): string
    {
        return 'Staf baru';
    }

    /**
     * Hand the work to the registrar rather than letting Filament mass-assign
     * a row.
     *
     * The registrar is the only path that writes the audit entry, and a second
     * way to create an account is a second way to create one nobody can trace.
     * `$data` has already been validated by the form schema above.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(StaffRegistrar::class)->create(
            nama: $data['name'],
            email: $data['email'],
            role: Role::from($data['role']),
            password: $data['password'],
            actor: auth()->user(),
            regionId: isset($data['region_id']) ? (int) $data['region_id'] : null,
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
