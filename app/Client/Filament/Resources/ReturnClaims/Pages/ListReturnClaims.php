<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\ReturnClaims\Pages;

use App\Client\Filament\Resources\ReturnClaims\ReturnClaimResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReturnClaims extends ListRecords
{
    protected static string $resource = ReturnClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('File a return claim'))];
    }
}
