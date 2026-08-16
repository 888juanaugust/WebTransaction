<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Filament\Resources\CreditNotes\CreditNoteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCreditNote extends CreateRecord
{
    protected static string $resource = CreditNoteResource::class;

    public function getTitle(): string
    {
        return 'Nota kredit baru';
    }

    protected function getRedirectUrl(): string
    {
        // Back to the draft, not to the list: nothing has been posted yet, and
        // the next thing anybody wants is the Posting button.
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
