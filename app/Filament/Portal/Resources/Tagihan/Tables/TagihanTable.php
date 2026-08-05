<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Tagihan\Tables;

use App\Domain\Money;
use App\Filament\Portal\Support\PortalLabels;
use App\Models\Invoice;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

class TagihanTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Soonest due first. An AR list sorted by invoice number is a list
            // nobody can act on.
            ->defaultSort('due_date')
            ->emptyStateHeading('Tidak ada tagihan')
            ->emptyStateDescription('Faktur Anda akan muncul di sini setelah pesanan dikonfirmasi.')
            ->columns([
                TextColumn::make('nomor')->label('Nomor faktur')->searchable()->sortable(),

                TextColumn::make('order.nomor')->label('Pesanan')->placeholder('—')->toggleable(),

                TextColumn::make('issued_on')->label('Tanggal')->date('d/m/Y')->sortable(),

                TextColumn::make('due_date')
                    ->label('Jatuh tempo')
                    ->date('d/m/Y')
                    ->sortable()
                    // Red only when it is genuinely late — red has to stay
                    // scarce to keep meaning anything.
                    ->color(fn (Invoice $record) => PortalLabels::isLate($record) ? 'danger' : 'gray'),

                TextColumn::make('keterangan')
                    ->label('Keterangan')
                    ->badge()
                    ->state(fn (Invoice $record) => PortalLabels::dueLabel($record))
                    ->color(fn (Invoice $record) => PortalLabels::dueColor($record)),

                TextColumn::make('total_rupiah')
                    ->label('Nilai faktur')
                    ->state(fn (Invoice $record) => Money::format($record->total_rupiah)),

                TextColumn::make('sisa')
                    ->label('Sisa tagihan')
                    ->weight('bold')
                    ->state(fn (Invoice $record) => Money::format($record->amountOutstanding())),
            ])
            ->filters([
                Filter::make('belum_lunas')
                    ->label('Hanya yang belum lunas')
                    ->query(fn ($query) => $query->where('status', Invoice::STATUS_OPEN))
                    ->default(),
            ])
            ->recordActions([
                ViewAction::make()->label('Lihat'),
            ])
            ->paginated([10, 25, 50]);
    }
}
