<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceListImports\Tables;

use App\Domain\PriceList\PriceListImporter;
use App\Models\PriceListImport;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PriceListImportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('original_filename')->label('Berkas')->searchable(),

                TextColumn::make('uploadedBy.name')->label('Diunggah oleh'),

                TextColumn::make('created_at')->label('Diunggah')->dateTime('d/m/Y H:i')->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        PriceListImport::STATUS_PUBLISHED => 'success',
                        PriceListImport::STATUS_FAILED => 'danger',
                        PriceListImport::STATUS_PARSED => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('row_count')->label('Baris'),

                TextColumn::make('blocker_count')
                    ->label('Blokir')
                    ->color(fn (int $state) => $state > 0 ? 'danger' : 'gray'),

                // The five buckets, at a glance.
                TextColumn::make('ringkasan')
                    ->label('Diff')
                    ->state(fn (PriceListImport $record) => self::diffSummary($record))
                    ->wrap(),

                TextColumn::make('brake')
                    ->label('Rem pengaman')
                    ->state(fn (PriceListImport $record) => ($record->diff['brake_tripped'] ?? false)
                        ? 'AKTIF'
                        : '—')
                    ->badge()
                    ->color(fn (PriceListImport $record) => ($record->diff['brake_tripped'] ?? false)
                        ? 'danger'
                        : 'gray'),
            ])
            ->recordActions([
                Action::make('publikasikan')
                    ->label('Publikasikan')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (PriceListImport $record) => $record->status === PriceListImport::STATUS_PARSED)
                    ->modalHeading('Publikasikan sebagai versi harga baru')
                    ->modalDescription(fn (PriceListImport $record) => self::diffSummary($record))
                    ->schema(fn (PriceListImport $record) => array_values(array_filter([
                        DatePicker::make('effective_from')
                            ->label('Berlaku mulai')
                            ->required()
                            ->default($record->effective_from ?? now()),

                        /*
                         * The safety brake. When more than 20% of prices move,
                         * or any single price moves more than 50%, publishing
                         * needs a second confirmation that names the numbers —
                         * so the approver has to read them, not just click OK.
                         */
                        ($record->diff['brake_tripped'] ?? false)
                            ? Textarea::make('brake_acknowledgement')
                                ->label('Konfirmasi kedua')
                                ->required()
                                ->helperText(
                                    'Perubahan besar terdeteksi: '
                                    .implode(' ', $record->diff['brake_reasons'] ?? [])
                                    .' Tulis ulang konfirmasi Anda untuk melanjutkan.'
                                )
                            : null,
                    ])))
                    ->action(function (PriceListImport $record, array $data) {
                        try {
                            $version = app(PriceListImporter::class)->publish(
                                import: $record,
                                approver: auth()->user(),
                                effectiveFrom: \Illuminate\Support\Carbon::parse($data['effective_from']),
                                brakeAcknowledgement: $data['brake_acknowledgement'] ?? null,
                            );

                            Notification::make()
                                ->title("Versi harga #{$version->id} dipublikasikan")
                                ->success()
                                ->send();
                        } catch (\DomainException $e) {
                            Notification::make()
                                ->title('Tidak bisa dipublikasikan')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function diffSummary(PriceListImport $import): string
    {
        $buckets = $import->diff['buckets'] ?? null;

        if ($buckets === null) {
            return 'Belum diproses.';
        }

        return sprintf(
            'SKU baru %d · harga berubah %d · tidak berubah %d · tidak ada di berkas %d · error %d',
            $buckets['sku_baru'],
            $buckets['harga_berubah'],
            $buckets['tidak_berubah'],
            $buckets['tidak_ada_di_file'],
            $buckets['error'],
        );
    }
}
