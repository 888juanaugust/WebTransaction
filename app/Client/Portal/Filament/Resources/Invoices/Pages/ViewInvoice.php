<?php

declare(strict_types=1);

namespace App\Client\Portal\Filament\Resources\Invoices\Pages;

use App\Client\Portal\Filament\Resources\Invoices\InvoiceResource;
use App\Client\Portal\PortalDocuments;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/** One invoice: its lines and totals, what was paid, what is still open, and the PDF. */
class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected string $view = 'client.portal.resources.invoices.view';

    public function getTitle(): string
    {
        return (string) $this->record->number;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')->label(__('Invoice PDF'))->icon('heroicon-m-arrow-down-tray')->color('gray')
                ->url(fn () => PortalDocuments::url($this->record), shouldOpenInNewTab: true),
        ];
    }
}
