<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\DocumentSeries\Pages;

use App\Filament\Resources\Settings\DocumentSeries\DocumentSeriesResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDocumentSeries extends EditRecord
{
    protected static string $resource = DocumentSeriesResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
