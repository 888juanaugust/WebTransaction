<?php

namespace App\Filament\Resources\PriceListImports\Pages;

use App\Domain\Import\CsvTemplate;
use App\Domain\Import\TemplateKind;
use App\Filament\Resources\PriceListImports\PriceListImportResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListPriceListImports extends ListRecords
{
    protected static string $resource = PriceListImportResource::class;

    /**
     * The example file, beside the upload button rather than in a manual.
     *
     * The testers could see an import and no way to learn what belonged in
     * it. Generated from the same column constant the parser reads, so it
     * cannot describe a format the importer does not accept.
     */
    public function unduhContoh(): StreamedResponse
    {
        $template = app(CsvTemplate::class);
        $csv = $template->toCsv(TemplateKind::Harga);

        return response()->streamDownload(
            fn () => print $csv,
            $template->namaBerkas(TemplateKind::Harga),
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('contoh')
                ->label('Unduh contoh CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action('unduhContoh'),

            CreateAction::make(),
        ];
    }
}
