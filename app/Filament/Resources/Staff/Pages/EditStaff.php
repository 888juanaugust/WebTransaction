<?php

declare(strict_types=1);

namespace App\Filament\Resources\Staff\Pages;

use App\Domain\Access\Role;
use App\Domain\Access\StaffRegistrar;
use App\Filament\Actions\StaffAccountActions;
use App\Filament\Resources\Staff\StaffResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditStaff extends EditRecord
{
    protected static string $resource = StaffResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->name;
    }

    protected function getHeaderActions(): array
    {
        return StaffAccountActions::all();
    }

    /**
     * Two separate events, not one "user updated".
     *
     * A name being corrected and a role being moved are different kinds of
     * fact. Folding them into a single audit entry would bury the one that
     * crosses a control boundary inside the one that fixes a typo, and the log
     * exists to make the first of those findable.
     *
     * The registrar refuses a self role change; the select is disabled on your
     * own row so it never gets that far, but the guard is what makes it true
     * rather than the disabled attribute.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $registrar = app(StaffRegistrar::class);
        $actor = auth()->user();

        $registrar->rename($record, $data['name'], $data['email'], $actor);

        /*
         * Absent when the field was disabled — Filament omits disabled fields
         * from the submitted data, which is exactly your own row. Falling back
         * to the current role makes that a no-op rather than a null crash.
         */
        $role = Role::from($data['role'] ?? $record->role->value);
        $registrar->changeRole($record, $role, $actor);

        /*
         * Region moves through the registrar too — audited, self-change
         * refused, and the person's open session ended so their next page
         * load reads the right books. Hidden-and-absent for the Owner role,
         * whose region must stay null; array_key_exists so an explicit null
         * (a role just changed to Owner) still clears it.
         */
        if (array_key_exists('region_id', $data) || $role === Role::Owner) {
            $regionId = $role === Role::Owner
                ? null
                : (isset($data['region_id']) ? (int) $data['region_id'] : null);

            $registrar->assignRegion($record->refresh(), $regionId, $actor);
        }

        return $record->refresh();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
