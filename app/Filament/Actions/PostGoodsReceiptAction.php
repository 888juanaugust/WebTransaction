<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Domain\Money;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Models\GoodsReceipt;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Posting a goods receipt: stock arrives and the average cost moves.
 *
 * Shared by the list and the edit page, so a receipt can be posted from
 * wherever it was found — the same reason the order transitions live in one
 * place rather than being copied onto each screen.
 *
 * The confirmation names the numbers, because this is irreversible: a posted
 * receipt is never edited, and a mistake has to be corrected with an opposing
 * document rather than by rewriting this one.
 */
class PostGoodsReceiptAction
{
    public static function make(string $name = 'posting'): Action
    {
        return Action::make($name)
            ->label('Posting')
            ->icon('heroicon-o-inbox-arrow-down')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Posting penerimaan barang')
            ->modalDescription(function (GoodsReceipt $record) {
                $lines = $record->lines()->get();

                return sprintf(
                    'Stok akan bertambah %s unit dasar pada %d baris, senilai %s. '
                    .'Setelah diposting, dokumen ini tidak bisa diubah lagi.',
                    number_format((int) $lines->sum('qty_base'), 0, ',', '.'),
                    $lines->count(),
                    Money::format((int) $lines->sum('line_value_rupiah')),
                );
            })
            ->modalSubmitActionLabel('Posting')
            ->visible(fn (GoodsReceipt $record) => ! $record->isPosted()
                && (auth()->user()?->role()->canRecordPurchases() ?? false))
            ->action(function (GoodsReceipt $record) {
                try {
                    app(GoodsReceiptPoster::class)->post($record, auth()->user());

                    $record->refresh();

                    Notification::make()
                        ->title("Penerimaan {$record->nomor} diposting")
                        ->body('Stok bertambah dan harga pokok rata-rata diperbarui.')
                        ->success()
                        ->send();
                } catch (DomainException $e) {
                    Notification::make()
                        ->title('Tidak bisa diposting')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
