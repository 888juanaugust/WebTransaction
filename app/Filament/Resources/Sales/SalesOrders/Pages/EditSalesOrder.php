<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesOrders\Pages;

use App\Filament\Resources\Sales\SalesOrders\SalesOrderResource;
use App\Filament\Support\DocumentPages;
use App\Filament\Support\EditDocument;

class EditSalesOrder extends EditDocument
{
    protected static string $resource = SalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [SalesOrderResource::approveAction(), SalesOrderResource::rejectAction(), DocumentPages::deleteAction()];
    }
}
