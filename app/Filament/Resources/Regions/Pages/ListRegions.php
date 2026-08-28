<?php

declare(strict_types=1);

namespace App\Filament\Resources\Regions\Pages;

use App\Filament\Resources\Regions\RegionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRegions extends ListRecords
{
    protected static string $resource = RegionResource::class;

    public function getTitle(): string
    {
        return 'Wilayah';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Wilayah baru')];
    }
}
