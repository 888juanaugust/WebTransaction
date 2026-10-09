<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\SettlementClaims\Pages;

use App\Client\Filament\Resources\SettlementClaims\SettlementClaimResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSettlementClaims extends ListRecords
{
    protected static string $resource = SettlementClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('File a settlement claim'))];
    }
}
