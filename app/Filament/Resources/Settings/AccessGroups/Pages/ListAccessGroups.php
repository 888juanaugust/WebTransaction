<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\AccessGroups\Pages;

use App\Filament\Resources\Settings\AccessGroups\AccessGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccessGroups extends ListRecords
{
    protected static string $resource = AccessGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New group'))];
    }
}
