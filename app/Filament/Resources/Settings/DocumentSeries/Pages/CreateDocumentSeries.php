<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\DocumentSeries\Pages;

use App\Filament\Resources\Settings\DocumentSeries\DocumentSeriesResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDocumentSeries extends CreateRecord
{
    protected static string $resource = DocumentSeriesResource::class;
}
