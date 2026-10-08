<?php

declare(strict_types=1);

namespace App\Filament\Resources\GeneralLedger\PostingLogs\Pages;

use App\Filament\Resources\GeneralLedger\PostingLogs\PostingLogResource;
use Filament\Resources\Pages\ListRecords;

class ListPostingLogs extends ListRecords
{
    protected static string $resource = PostingLogResource::class;
}
