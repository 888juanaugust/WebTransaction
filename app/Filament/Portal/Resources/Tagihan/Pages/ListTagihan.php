<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Tagihan\Pages;

use App\Filament\Portal\Resources\Tagihan\TagihanResource;
use Filament\Resources\Pages\ListRecords;

class ListTagihan extends ListRecords
{
    protected static string $resource = TagihanResource::class;

    public function getTitle(): string
    {
        return 'Tagihan Anda';
    }
}
