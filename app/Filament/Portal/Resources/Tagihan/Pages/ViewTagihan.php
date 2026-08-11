<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Tagihan\Pages;

use App\Filament\Portal\Resources\Tagihan\TagihanResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewTagihan extends ViewRecord
{
    protected static string $resource = TagihanResource::class;

    public function getTitle(): string
    {
        return "Faktur {$this->record->nomor}";
    }

    protected function getHeaderActions(): array
    {
        return [
            /*
             * The printable copy. A buyer's own accounts need the document,
             * not the screen — this is the one thing on this page that leaves
             * the portal.
             */
            Action::make('cetak')
                ->label('Cetak faktur')
                ->icon('heroicon-o-printer')
                ->url(fn () => route('portal.dokumen.faktur', $this->record))
                ->openUrlInNewTab(),
        ];
    }
}
