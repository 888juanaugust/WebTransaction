<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesQuotations\Pages;

use App\Filament\Resources\Sales\SalesQuotations\SalesQuotationResource;
use App\Filament\Support\ListDocuments;

class ListSalesQuotations extends ListDocuments
{
    protected static string $resource = SalesQuotationResource::class;
}
