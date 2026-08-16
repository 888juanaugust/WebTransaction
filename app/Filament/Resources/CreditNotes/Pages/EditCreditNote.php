<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Filament\Actions\PostCreditNoteAction;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use Filament\Resources\Pages\EditRecord;

class EditCreditNote extends EditRecord
{
    protected static string $resource = CreditNoteResource::class;

    public function getTitle(): string
    {
        return "Nota kredit {$this->record->nomor}";
    }

    protected function getHeaderActions(): array
    {
        return [PostCreditNoteAction::make()];
    }
}
