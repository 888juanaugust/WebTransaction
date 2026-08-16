<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Filament\Resources\CreditNotes\CreditNoteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCreditNotes extends ListRecords
{
    protected static string $resource = CreditNoteResource::class;

    public function getTitle(): string
    {
        return 'Nota kredit';
    }

    protected function getHeaderActions(): array
    {
        /*
         * The visibility is stated explicitly rather than left to the
         * resource's canCreate(). Filament rendered the button as a live link
         * for Finance — who are allowed to read credit notes and not to raise
         * one — and the route then refused them with a 403. The authorisation
         * held; the invitation to click it should not have been there.
         */
        return [
            CreateAction::make()
                ->label('Nota kredit baru')
                ->visible(fn () => CreditNoteResource::canCreate()),
        ];
    }
}
