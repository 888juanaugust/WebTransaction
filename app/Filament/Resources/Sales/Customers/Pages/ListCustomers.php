<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\Customers\Pages;

use App\Domain\Imports\MasterImporter;
use App\Filament\Resources\Sales\Customers\CustomerResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New customer')),
            Action::make('template')
                ->label(__('Download template'))
                ->icon('heroicon-m-document-arrow-down')
                ->color('gray')
                ->action(fn () => response()->streamDownload(fn () => print (MasterImporter::template('customers')), 'customers-template.csv', ['Content-Type' => 'text/csv'])),
            Action::make('import')
                ->label(__('Import'))
                ->icon('heroicon-m-arrow-up-tray')
                ->color('gray')
                ->visible(fn () => static::getResource()::canCreate())
                ->schema([
                    FileUpload::make('file')
                        ->label(__('CSV or XLSX file'))
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->disk('local')
                        ->directory('imports')
                        ->required()
                        ->storeFileNamesIn('file_name')
                        ->helperText(__('The first row names the columns, as in the template. A row with a number updates that record; without one, a new record is numbered from the series.')),
                ])
                ->action(function (array $data): void {
                    $path = Storage::disk('local')->path($data['file']);

                    try {
                        $result = app(MasterImporter::class)->import('customers', $path);
                    } catch (RuntimeException $e) {
                        Notification::make()->title(__('Cannot import'))->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    } finally {
                        Storage::disk('local')->delete($data['file']); // read once; the file's people and prices are not kept
                    }

                    Notification::make()->title(__(':created created, :updated updated', ['created' => $result['created'], 'updated' => $result['updated']]))->success()->send();

                    if ($result['errors'] !== []) {
                        $lines = array_slice($result['errors'], 0, 15);
                        if (count($result['errors']) > 15) {
                            $lines[] = '…';
                        }
                        Notification::make()->title(__(':count row(s) skipped', ['count' => count($result['errors'])]))->body(implode("\n", $lines))->warning()->persistent()->send();
                    }

                    $this->resetTable();
                }),
        ];
    }
}
