<?php

declare(strict_types=1);

namespace App\Filament\Resources\Staff\Pages;

use App\Filament\Resources\Staff\StaffResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStaff extends ListRecords
{
    protected static string $resource = StaffResource::class;

    public function getTitle(): string
    {
        return 'Staf';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Staf baru')];
    }
}
