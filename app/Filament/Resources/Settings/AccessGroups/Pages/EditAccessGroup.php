<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\AccessGroups\Pages;

use App\Filament\Resources\Settings\AccessGroups\AccessGroupResource;
use App\Models\Settings\AccessGroup;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAccessGroup extends EditRecord
{
    use HandlesRights;

    protected static string $resource = AccessGroupResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var AccessGroup $group */
        $group = $this->record->load('rights', 'specialRights');
        $data['rights'] = $group->rightsMatrix();
        $data['special_rights'] = $group->specialRights->pluck('right')->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->liftRights($data);
    }

    protected function afterSave(): void
    {
        $this->syncRights();
    }

    protected function getHeaderActions(): array
    {
        return [
            // The standard's "Salin Hak": take another group's matrix as the starting point.
            Action::make('copyRights')
                ->label(__('Copy rights from…'))
                ->icon('heroicon-o-document-duplicate')
                ->visible(fn () => AccessGroupResource::canEdit($this->record))
                ->schema([
                    Select::make('source_id')
                        ->label(__('Copy the rights of'))
                        ->options(fn () => AccessGroup::query()->whereKeyNot($this->record->getKey())->orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data): void {
                    $this->record->copyRightsFrom(AccessGroup::query()->findOrFail($data['source_id']));
                    $this->fillForm();
                    Notification::make()->title(__('Rights copied'))->success()->send();
                }),
            DeleteAction::make(),
        ];
    }
}
