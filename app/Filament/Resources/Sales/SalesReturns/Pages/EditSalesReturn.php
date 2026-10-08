<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesReturns\Pages;

use App\Filament\Resources\Sales\SalesReturns\SalesReturnResource;
use App\Filament\Support\EditDocument;

class EditSalesReturn extends EditDocument
{
    protected static string $resource = SalesReturnResource::class;
}
