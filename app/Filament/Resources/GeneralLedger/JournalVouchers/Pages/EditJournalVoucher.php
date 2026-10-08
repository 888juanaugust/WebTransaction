<?php

declare(strict_types=1);

namespace App\Filament\Resources\GeneralLedger\JournalVouchers\Pages;

use App\Filament\Resources\GeneralLedger\JournalVouchers\JournalVoucherResource;
use App\Filament\Support\EditDocument;

class EditJournalVoucher extends EditDocument
{
    protected static string $resource = JournalVoucherResource::class;
}
