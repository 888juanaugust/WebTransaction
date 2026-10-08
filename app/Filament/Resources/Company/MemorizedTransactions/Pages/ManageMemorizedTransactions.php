<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\MemorizedTransactions\Pages;

use App\Filament\Resources\Company\MemorizedTransactions\MemorizedTransactionResource;
use App\Filament\Support\ManageMaster;

/** A template is memorized from a document's list, so this page has no "new" button of its own. */
class ManageMemorizedTransactions extends ManageMaster
{
    protected static string $resource = MemorizedTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        return 'Memorize a journal voucher, payment or receipt from its list; use it again from here or from the document\'s create page.';
    }
}
