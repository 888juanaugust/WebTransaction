<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Domain\Money;
use App\Domain\Stock\StockOpnamePoster;
use App\Models\StockOpname;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Approving a count, which writes the difference off.
 *
 * Only Finance and the Owner see this, and never the person who counted. The
 * confirmation names the quantity rather than the money: the variance in
 * rupiah is not known until the movements are written, and quoting a figure
 * the posting might not produce is worse than quoting none.
 */
class PostStockOpnameAction
{
    public static function make(string $name = 'setujui'): Action
    {
        return Action::make($name)
            ->label('Setujui selisih')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Setujui hasil opname')
            ->modalDescription(function (StockOpname $record) {
                $counted = $record->lines()->whereNotNull('qty_counted')->get();
                $variance = $counted->sum(fn ($l) => (int) $l->qty_counted - (int) $l->qty_system);

                return sprintf(
                    '%d baris dihitung, dengan selisih bersih %s unit dasar. Stok akan '
                    .'disesuaikan ke hasil hitungan dan selisihnya dibebankan ke Selisih '
                    .'Persediaan. Baris yang tidak dihitung tidak disentuh.',
                    $counted->count(),
                    number_format($variance, 0, ',', '.'),
                );
            })
            ->modalSubmitActionLabel('Setujui')
            ->visible(fn (StockOpname $record) => $record->isDraft()
                && (auth()->user()?->role()->canApproveStockCount() ?? false))
            ->action(function (StockOpname $record) {
                try {
                    app(StockOpnamePoster::class)->post($record, auth()->user());
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Tidak bisa disetujui')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $record->refresh();

                Notification::make()
                    ->title("Opname {$record->nomor} disetujui")
                    ->body($record->selisih_rupiah === 0
                        ? 'Hitungan cocok dengan catatan.'
                        : 'Selisih '.Money::format((int) $record->selisih_rupiah).' dibebankan ke Selisih Persediaan.')
                    ->success()
                    ->send();
            });
    }
}
