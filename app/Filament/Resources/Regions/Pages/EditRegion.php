<?php

declare(strict_types=1);

namespace App\Filament\Resources\Regions\Pages;

use App\Filament\Resources\Regions\RegionResource;
use Filament\Resources\Pages\EditRecord;

class EditRegion extends EditRecord
{
    protected static string $resource = RegionResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->nama;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
