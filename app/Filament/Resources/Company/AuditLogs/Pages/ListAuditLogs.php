<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\AuditLogs\Pages;

use App\Filament\Resources\Company\AuditLogs\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;
}
