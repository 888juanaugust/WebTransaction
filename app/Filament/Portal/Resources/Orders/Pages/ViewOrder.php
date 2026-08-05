<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Orders\Pages;

use App\Filament\Portal\Actions\PesanUlangAction;
use App\Filament\Portal\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        return "Pesanan {$this->record->nomor}";
    }

    protected function getHeaderActions(): array
    {
        return [
            PesanUlangAction::make(),
        ];
    }
}
