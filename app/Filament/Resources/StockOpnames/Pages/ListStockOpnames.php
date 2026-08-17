<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockOpnames\Pages;

use App\Domain\Stock\StockOpnameSheet;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Models\Warehouse;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Throwable;

class ListStockOpnames extends ListRecords
{
    protected static string $resource = StockOpnameResource::class;

    public function getTitle(): string
    {
        return 'Stok opname';
    }

    /**
     * Drawing a sheet, rather than a generic Create.
     *
     * The lines come from the shelf: every SKU the warehouse holds, including
     * the ones the system says are at zero. Typing them by hand would mean the
     * count could only ever find what somebody already suspected.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('buat')
                ->label('Buat lembar opname')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->visible(fn () => auth()->user()?->role()->canCountStock() ?? false)
                ->schema([
                    Select::make('warehouse_id')
                        ->label('Gudang')
                        ->options(fn () => Warehouse::query()->where('aktif', true)->pluck('nama', 'id'))
                        ->required(),

                    Textarea::make('catatan')
                        ->label('Catatan')
                        ->rows(2)
                        ->placeholder('mis. hitungan rutin akhir bulan'),
                ])
                ->action(function (array $data) {
                    try {
                        $opname = app(StockOpnameSheet::class)->draw(
                            Warehouse::findOrFail($data['warehouse_id']),
                            auth()->user(),
                            catatan: $data['catatan'] ?: null,
                        );
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Lembar tidak bisa dibuat')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title("Lembar {$opname->nomor} dibuat")
                        ->body($opname->lines()->count().' baris siap dihitung.')
                        ->success()
                        ->send();

                    $this->redirect(StockOpnameResource::getUrl('edit', ['record' => $opname]));
                }),
        ];
    }
}
