<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Domain\Stock\StockTransferPoster;
use App\Models\StockTransfer;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Posting a transfer: the goods leave one shelf and arrive on another.
 *
 * The confirmation talks about cartons, not money. Whoever presses this is
 * warehouse staff, and the value that travels with the goods is none of their
 * business — it is also unchanged, so there is nothing to warn about.
 */
class PostStockTransferAction
{
    public static function make(string $name = 'posting'): Action
    {
        return Action::make($name)
            ->label('Posting')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Posting transfer gudang')
            ->modalDescription(function (StockTransfer $record) {
                $lines = $record->lines()->get();

                return sprintf(
                    '%s unit dasar pada %d baris akan pindah dari %s ke %s. '
                    .'Setelah diposting, dokumen ini tidak bisa diubah lagi.',
                    number_format((int) $lines->sum('qty_base'), 0, ',', '.'),
                    $lines->count(),
                    $record->fromWarehouse?->nama ?? '—',
                    $record->toWarehouse?->nama ?? '—',
                );
            })
            ->modalSubmitActionLabel('Posting')
            ->visible(fn (StockTransfer $record) => $record->isDraft()
                && (auth()->user()?->role()->canTransferStock() ?? false))
            ->action(function (StockTransfer $record) {
                try {
                    app(StockTransferPoster::class)->post($record, auth()->user());
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Tidak bisa diposting')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $record->refresh();

                Notification::make()
                    ->title("Transfer {$record->nomor} diposting")
                    ->body('Stok sudah pindah gudang.')
                    ->success()
                    ->send();
            });
    }
}
