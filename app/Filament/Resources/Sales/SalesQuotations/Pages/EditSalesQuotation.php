<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesQuotations\Pages;

use App\Filament\Resources\Sales\SalesQuotations\SalesQuotationResource;
use App\Filament\Support\EditDocument;

class EditSalesQuotation extends EditDocument
{
    protected static string $resource = SalesQuotationResource::class;
}
