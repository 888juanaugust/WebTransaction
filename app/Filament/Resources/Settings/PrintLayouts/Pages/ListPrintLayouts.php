<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\PrintLayouts\Pages;

use App\Filament\Resources\Settings\PrintLayouts\PrintLayoutResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPrintLayouts extends ListRecords
{
    protected static string $resource = PrintLayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New print layout'))];
    }
}
