<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Expenses\ClaimStatus;
use App\Domain\Money;
use App\Filament\Actions\SalesExpenseClaimActions;
use App\Models\SalesExpenseClaim;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Queue: expedition spending claimed by sales, waiting for finance to check
 * by hand. Every day it waits is a day the books understate cost and a
 * sales is out of pocket.
 */
class ExpenseClaimsAwaitingVerification extends TableWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->role()->canPostJournals() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Biaya ekspedisi menunggu verifikasi')
            ->emptyStateHeading('Tidak ada klaim menunggu')
            ->query(
                SalesExpenseClaim::query()
                    ->where('status', ClaimStatus::Diajukan)
                    ->with('sales')
                    ->orderBy('tanggal')
            )
            ->columns([
                TextColumn::make('tanggal')->label('Tanggal')->date('d/m/Y'),
                TextColumn::make('sales.name')->label('Sales'),
                TextColumn::make('amount_rupiah')
                    ->label('Jumlah')
                    ->formatStateUsing(fn (int $state) => Money::format($state)),
                TextColumn::make('keterangan')->label('Untuk apa')->wrap(),
            ])
            ->recordActions([
                SalesExpenseClaimActions::setujui(),
                SalesExpenseClaimActions::tolak(),
            ]);
    }
}
