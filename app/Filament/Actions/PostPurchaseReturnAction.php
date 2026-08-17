<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Domain\Money;
use App\Domain\Purchasing\PurchaseReturnPoster;
use App\Models\PurchaseReturn;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Posting a return, which is what takes the goods off the shelf.
 *
 * The confirmation says what will happen but names no money, because none of
 * it is known until the movements are written: stock leaves at the running
 * average at that instant, and how much of the return reduces a debt rather
 * than an accrual depends on what the supplier has billed by then. Quoting a
 * figure the posting might not produce is worse than quoting none.
 *
 * The notification afterwards says what actually happened, in the two parts
 * that matter — what the supplier now owes us, and what merely came off the
 * accrual.
 */
class PostPurchaseReturnAction
{
    public static function make(string $name = 'posting'): Action
    {
        return Action::make($name)
            ->label('Posting retur')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Posting retur pembelian')
            ->modalDescription(fn (PurchaseReturn $record) => sprintf(
                '%s unit keluar dari %s dan tidak bisa dibatalkan. Bagian yang sudah ditagih '
                .'pemasok mengurangi utang berikut PPN masukannya; sisanya membatalkan akrual '
                .'penerimaan. Pastikan barangnya benar-benar sudah dikirim balik.',
                number_format($record->qtyReturned(), 0, ',', '.'),
                $record->warehouse?->nama ?? 'gudang',
            ))
            ->modalSubmitActionLabel('Posting')
            ->visible(fn (PurchaseReturn $record) => ! $record->isPosted()
                && (auth()->user()?->role()->canReturnToSupplier() ?? false))
            ->action(function (PurchaseReturn $record) {
                try {
                    app(PurchaseReturnPoster::class)->post($record, auth()->user());
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
                    ->title("Retur {$record->nomor} diposting")
                    ->body(sprintf(
                        '%s dikreditkan pemasok, %s membatalkan akrual penerimaan.',
                        Money::format((int) $record->total_rupiah),
                        Money::format((int) $record->nilai_belum_ditagih_rupiah),
                    ))
                    ->success()
                    ->send();
            });
    }
}
