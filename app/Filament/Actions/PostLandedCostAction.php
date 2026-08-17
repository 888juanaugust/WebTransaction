<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Domain\Money;
use App\Domain\Purchasing\LandedCostPoster;
use App\Models\LandedCost;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Posting an allocation, which is what makes it real.
 *
 * The confirmation names the charge and the basis but not the split, because
 * the split is not known until the movements are written — it depends on how
 * much of each SKU is still on the shelf at that instant. Quoting a figure the
 * posting might not produce is worse than quoting none; the notification
 * afterwards says what actually happened.
 */
class PostLandedCostAction
{
    public static function make(string $name = 'posting'): Action
    {
        return Action::make($name)
            ->label('Posting alokasi')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Posting biaya perolehan')
            ->modalDescription(fn (LandedCost $record) => sprintf(
                '%s dibagi ke %d baris penerimaan berdasarkan %s. Bagian barang yang masih '
                .'ada akan menaikkan nilai persediaan; bagian yang sudah terjual dibebankan '
                .'ke HPP bulan ini, karena pengiriman yang sudah lewat tidak bisa dihitung ulang.',
                Money::format((int) $record->amount_rupiah),
                $record->lines()->count(),
                strtolower($record->dasar->label()),
            ))
            ->modalSubmitActionLabel('Posting')
            ->visible(fn (LandedCost $record) => $record->isDraft()
                && (auth()->user()?->role()->canAllocateLandedCost() ?? false))
            ->action(function (LandedCost $record) {
                try {
                    app(LandedCostPoster::class)->post($record, auth()->user());
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
                    ->title("Alokasi {$record->nomor} diposting")
                    ->body(sprintf(
                        '%s masuk ke nilai persediaan, %s ke HPP.',
                        Money::format((int) $record->ke_persediaan_rupiah),
                        Money::format((int) $record->ke_hpp_rupiah),
                    ))
                    ->success()
                    ->send();
            });
    }
}
