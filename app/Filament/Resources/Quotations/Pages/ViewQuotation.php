<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Pages;

use App\Domain\Quotes\QuotationFlow;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Quotation;
use App\Models\Warehouse;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * One quote, and everything that can lawfully happen to it. Every button
 * calls the flow; the flow decides — the buttons only hide when the answer
 * is obviously no, and the flow still refuses if a stale screen asks.
 */
class ViewQuotation extends ViewRecord
{
    protected static string $resource = QuotationResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('nomor')->label('Nomor'),
            TextEntry::make('company.nama')->label('Pelanggan'),
            TextEntry::make('status')
                ->label('Status')
                ->state(fn (Quotation $record) => $record->statusTampil())
                ->badge()
                ->color(fn (string $state) => match ($state) {
                    'diterima' => 'success',
                    'terkirim' => 'info',
                    'kedaluwarsa', 'batal' => 'danger',
                    default => 'gray',
                }),
            TextEntry::make('valid_until')
                ->label('Berlaku sampai')->date('d/m/Y'),
            TextEntry::make('subtotal_rupiah')
                ->label('Subtotal')->money('IDR', 0),
            TextEntry::make('ppn_rupiah')
                ->label('PPN')->money('IDR', 0),
            TextEntry::make('total_rupiah')
                ->label('Total')->money('IDR', 0),
            TextEntry::make('order.nomor')
                ->label('Menjadi order')->placeholder('— belum —'),
            RepeatableEntry::make('lines')
                ->label('Barang')
                ->columnSpanFull()
                ->columns(5)
                ->schema([
                    TextEntry::make('sku')->label('KODE'),
                    TextEntry::make('description_snapshot')->label('Nama'),
                    TextEntry::make('ordered_qty')
                        ->label('Jumlah')
                        ->state(fn ($record) => $record->ordered_qty.' '.$record->ordered_unit),
                    TextEntry::make('unit_price_rupiah')
                        ->label('Harga satuan')->money('IDR', 0),
                    TextEntry::make('line_total_rupiah')
                        ->label('Jumlah harga')->money('IDR', 0),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('kirim')
                ->label('Kirim ke pelanggan')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->getRecord()->status === Quotation::STATUS_DRAFT)
                ->action(fn () => $this->jalankan(fn (QuotationFlow $flow) => $flow
                    ->send($this->getRecord(), auth()->user()), 'Penawaran terkirim')),

            Action::make('terima')
                ->label('Pelanggan menerima')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->getRecord()->status === Quotation::STATUS_TERKIRIM)
                ->action(fn () => $this->jalankan(fn (QuotationFlow $flow) => $flow
                    ->accept($this->getRecord(), auth()->user()), 'Penawaran diterima')),

            Action::make('jadikanOrder')
                ->label('Jadikan order')
                ->icon(Heroicon::OutlinedArrowRightCircle)
                ->color('primary')
                ->visible(fn (): bool => $this->getRecord()->status === Quotation::STATUS_DITERIMA
                    && $this->getRecord()->order_id === null
                    && (auth()->user()?->role()->canCreateOrders() ?? false))
                ->schema([
                    Select::make('warehouse_id')
                        ->label('Gudang pengirim')
                        ->options(fn () => Warehouse::query()->where('aktif', true)->pluck('nama', 'id'))
                        ->required(),
                ])
                ->action(function (array $data) {
                    $this->jalankan(function (QuotationFlow $flow) use ($data) {
                        $order = $flow->toOrder(
                            $this->getRecord(),
                            Warehouse::query()->findOrFail($data['warehouse_id']),
                            auth()->user(),
                        );

                        $this->redirect('/admin/orders/'.$order->id);
                    }, 'Order draft dibuat dari penawaran');
                }),

            Action::make('batal')
                ->label('Batalkan')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (): bool => in_array($this->getRecord()->status,
                    [Quotation::STATUS_DRAFT, Quotation::STATUS_TERKIRIM], true))
                ->schema([
                    Textarea::make('alasan')->label('Alasan')->rows(2),
                ])
                ->action(fn (array $data) => $this->jalankan(fn (QuotationFlow $flow) => $flow
                    ->cancel($this->getRecord(), auth()->user(), $data['alasan'] ?? null), 'Penawaran dibatalkan')),

            Action::make('cetak')
                ->label('Cetak')
                ->icon(Heroicon::OutlinedPrinter)
                ->url(fn (): string => route('dokumen.penawaran', $this->getRecord()), true),
        ];
    }

    private function jalankan(callable $callback, string $sukses): void
    {
        try {
            $callback(app(QuotationFlow::class));

            Notification::make()->title($sukses)->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title('Tidak bisa')->body($e->getMessage())->danger()->send();
        }
    }
}
