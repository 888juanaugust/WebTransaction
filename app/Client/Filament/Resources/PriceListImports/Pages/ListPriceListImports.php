<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\PriceListImports\Pages;

use App\Client\Domain\PriceList\PriceListExporter;
use App\Client\Filament\Resources\PriceListImports\PriceListImportResource;
use App\Client\Models\PriceListVersion;
use App\Domain\Shared\Format;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPriceListImports extends ListRecords
{
    protected static string $resource = PriceListImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(fn () => ($v = PriceListVersion::current()) ? __('Export list in force (v:id, :date)', ['id' => $v->id, 'date' => Format::date($v->effective_from)]) : __('No list in force yet'))
                ->icon('heroicon-m-arrow-down-tray')->color('gray')
                ->disabled(fn () => PriceListVersion::current() === null)
                ->action(fn () => app(PriceListExporter::class)->download(PriceListVersion::current())),
            CreateAction::make()->label(__('Upload price list')),
        ];
    }
}
