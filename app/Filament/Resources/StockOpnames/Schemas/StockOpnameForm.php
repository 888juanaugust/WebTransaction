<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockOpnames\Schemas;

use App\Models\StockOpname;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Filling in a count sheet.
 *
 * The lines are drawn by StockOpnameSheet, not typed here: the sheet has to
 * cover every SKU the warehouse holds — including the ones the system says are
 * at zero, because that is exactly where unrecorded stock accumulates. So this
 * form edits what is already there and cannot add or remove a line.
 *
 * The system quantity is shown and locked. Hiding it would make this a blind
 * count, which is stricter and catches more, but needs a second pass to
 * investigate every difference — and a warehouse that cannot spare two people
 * will simply stop counting.
 *
 * No money on this screen at all. It is filled in by whoever is standing at
 * the shelf.
 */
class StockOpnameForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Lembar opname')
                    ->columns(3)
                    ->schema([
                        TextInput::make('nomor')->label('Nomor')->disabled(),

                        TextInput::make('warehouse.nama')
                            ->label('Gudang')
                            ->disabled()
                            ->formatStateUsing(fn ($state, ?StockOpname $record) => $record?->warehouse?->nama),

                        /*
                         * Formatted off the record rather than the form state.
                         * Livewire serialises a Carbon into the wire payload as
                         * a UTC instant, so a date that is midnight in Jakarta
                         * comes back as 17:00 the previous day and re-parsing it
                         * here printed the count as a day earlier than the list
                         * did. The record is not round-tripped, so it is right.
                         */
                        TextInput::make('tanggal')
                            ->label('Tanggal')
                            ->disabled()
                            ->formatStateUsing(fn (?StockOpname $record) => $record?->tanggal?->format('d/m/Y')),

                        Textarea::make('catatan')
                            ->label('Catatan')
                            ->rows(2)
                            ->columnSpanFull()
                            ->placeholder('mis. hitungan rutin akhir bulan, rak A sampai D'),
                    ]),

                Section::make('Hitungan')
                    ->description('Isi jumlah yang benar-benar ada di rak. Kosongkan baris yang belum dihitung.')
                    ->schema([
                        Repeater::make('lines')
                            ->relationship()
                            ->hiddenLabel()
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(4)
                            ->schema([
                                TextInput::make('sku')->label('Kode')->disabled(),

                                TextInput::make('qty_system')
                                    ->label('Catatan sistem')
                                    ->disabled(),

                                TextInput::make('qty_counted')
                                    ->label('Hasil hitung')
                                    ->numeric()
                                    ->minValue(0)
                                    // Blank, not zero. A zero here means "the
                                    // shelf was empty", which is a finding.
                                    ->placeholder('belum dihitung'),

                                TextInput::make('catatan')
                                    ->label('Keterangan')
                                    ->placeholder('mis. dus rusak, isi kurang'),
                            ]),
                    ]),
            ]);
    }
}
