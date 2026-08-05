<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Orders\Pages;

use App\Filament\Portal\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        return 'Pesanan Anda';
    }
}
