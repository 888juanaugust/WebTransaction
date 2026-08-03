<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Money;
use App\Models\Invoice;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Queue: overdue invoices, oldest first — an ageing list, not a list of
 * invoices. The age band is what tells finance who to ring today.
 */
class OverdueInvoices extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->role()->canSeeCreditData() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Faktur jatuh tempo')
            ->emptyStateHeading('Tidak ada faktur jatuh tempo')
            ->query(
                Invoice::query()
                    ->overdue()
                    ->with('company')
                    ->orderBy('due_date')
            )
            ->columns([
                TextColumn::make('nomor')->label('Nomor')->searchable(),
                TextColumn::make('company.nama')->label('Pelanggan')->searchable(),
                TextColumn::make('due_date')->label('Jatuh tempo')->date('d/m/Y')->sortable(),

                TextColumn::make('umur')
                    ->label('Umur')
                    ->state(fn (Invoice $record) => self::ageInDays($record).' hari')
                    ->badge()
                    ->color(fn (Invoice $record) => match (true) {
                        self::ageInDays($record) > 90 => 'danger',
                        self::ageInDays($record) > 30 => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('sisa')
                    ->label('Sisa tagihan')
                    ->state(fn (Invoice $record) => Money::format($record->amountOutstanding())),
            ]);
    }

    /**
     * Whole days overdue.
     *
     * Carbon returns a float here, and "45.811393477072 hari" is not an
     * ageing bucket anyone can read — compare date to date, not instant to
     * instant.
     */
    private static function ageInDays(Invoice $invoice): int
    {
        return (int) $invoice->due_date->startOfDay()->diffInDays(now()->startOfDay());
    }
}
