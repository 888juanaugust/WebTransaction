<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Credit\DebtRemovalStatus;
use App\Domain\Money;
use App\Filament\Actions\DebtRemovalActions;
use App\Models\DebtRemoval;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Queue: debt-removal claims waiting for finance's key.
 *
 * A claim sitting here is a customer who believes they have paid and a
 * marketing who has already said so — every day it waits is a day the
 * receivables overstate and, past four months, a customer wrongly frozen.
 * That is why it is a dashboard queue and not a page finance must remember
 * to open.
 */
class DebtRemovalsAwaitingVerification extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->role()->canConfirmPayment() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Pelunasan piutang menunggu verifikasi')
            ->emptyStateHeading('Tidak ada pengajuan menunggu')
            ->query(
                DebtRemoval::query()
                    ->where('status', DebtRemovalStatus::Diajukan)
                    ->with(['invoice', 'company', 'initiator'])
                    ->orderBy('created_at')
            )
            ->columns([
                TextColumn::make('created_at')->label('Diajukan')->since(),
                TextColumn::make('invoice.nomor')->label('Faktur')->searchable(),
                TextColumn::make('company.nama')->label('Pelanggan')->searchable(),
                TextColumn::make('amount_rupiah')
                    ->label('Jumlah')
                    ->formatStateUsing(fn (int $state) => Money::format($state)),
                TextColumn::make('alasan')->label('Bagaimana diterima')->wrap(),
                TextColumn::make('initiator.name')->label('Diajukan oleh'),
            ])
            ->recordActions([
                DebtRemovalActions::setujui(),
                DebtRemovalActions::tolak(),
            ]);
    }
}
