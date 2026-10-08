<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\DocumentSeries\Pages;

use App\Filament\Resources\Settings\DocumentSeries\DocumentSeriesResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDocumentSeries extends ListRecords
{
    protected static string $resource = DocumentSeriesResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New format'))];
    }
}
