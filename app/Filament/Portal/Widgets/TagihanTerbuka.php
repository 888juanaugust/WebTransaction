<?php

declare(strict_types=1);

namespace App\Filament\Portal\Widgets;

use App\Domain\Money;
use App\Models\Invoice;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Outstanding invoices with due dates — last on the buyer portal priority
 * list, and the thing that stops "I didn't know it was due" conversations.
 */
class TagihanTerbuka extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $companyId = auth('customer')->user()?->company_id;

        return $table
            ->heading('Tagihan belum lunas')
            ->emptyStateHeading('Tidak ada tagihan terbuka')
            ->emptyStateDescription('Semua faktur Anda sudah lunas.')
            ->query(
                Invoice::query()
                    ->where('company_id', $companyId)
                    ->where('status', Invoice::STATUS_OPEN)
                    ->orderBy('due_date')
            )
            ->columns([
                TextColumn::make('nomor')->label('Nomor faktur')->searchable(),

                TextColumn::make('issued_on')->label('Tanggal')->date('d/m/Y'),

                TextColumn::make('due_date')
                    ->label('Jatuh tempo')
                    ->date('d/m/Y')
                    ->sortable()
                    // Red only when it is actually late.
                    ->color(fn (Invoice $record) => $record->due_date->isPast() ? 'danger' : 'gray'),

                TextColumn::make('status_jatuh_tempo')
                    ->label('Keterangan')
                    ->badge()
                    ->state(fn (Invoice $record) => $record->due_date->isPast()
                        ? 'Lewat jatuh tempo'
                        : 'Jatuh tempo '.$record->due_date->diffForHumans())
                    ->color(fn (Invoice $record) => $record->due_date->isPast() ? 'danger' : 'gray'),

                TextColumn::make('sisa')
                    ->label('Sisa tagihan')
                    ->state(fn (Invoice $record) => Money::format($record->amountOutstanding())),
            ])
            ->paginated([5, 10, 25]);
    }
}
