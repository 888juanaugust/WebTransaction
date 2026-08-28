<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Domain\Credit\DebtRemovalStatus;
use App\Domain\Credit\DebtRemover;
use App\Domain\Money;
use App\Models\DebtRemoval;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * Finance's two buttons on a debt-removal claim, shared by the dashboard
 * queue and the resource list so the two screens cannot drift apart.
 *
 * Visibility mirrors DebtRemover::assertMayDecide — finance's seat, never
 * the initiator's — and the domain class still enforces it, so a stale
 * button refuses politely rather than posting.
 */
class DebtRemovalActions
{
    private static function mayDecide(DebtRemoval $record): bool
    {
        $user = auth()->user();

        return $user !== null
            && $record->status === DebtRemovalStatus::Diajukan
            && $user->role()->canConfirmPayment()
            && (int) $record->initiated_by !== (int) $user->getKey();
    }

    public static function setujui(string $name = 'setujui_penghapusan'): Action
    {
        return Action::make($name)
            ->label('Setujui')
            ->icon('heroicon-o-check-circle')
            // Green is reserved for settled money — which is exactly what
            // this click makes.
            ->color('success')
            ->modalHeading('Setujui pelunasan piutang')
            ->modalDescription(fn (DebtRemoval $record) => 'Menyetujui akan mencatat pembayaran '
                .Money::format($record->amount_rupiah)
                ." untuk faktur {$record->invoice->nomor} — sama seperti pembayaran biasa, masuk buku dan tidak bisa diedit.")
            ->schema([
                Textarea::make('catatan')
                    ->label('Catatan verifikasi')
                    ->helperText('Bagaimana uangnya dicek — opsional, tercatat di pengajuan.')
                    ->maxLength(500),
            ])
            ->visible(fn (DebtRemoval $record) => static::mayDecide($record))
            ->action(function (DebtRemoval $record, array $data) {
                try {
                    app(DebtRemover::class)->approve($record, auth()->user(), $data['catatan'] ?? null);

                    Notification::make()
                        ->title("Pelunasan piutang faktur {$record->invoice->nomor} disetujui")
                        ->body('Pembayaran tercatat di buku.')
                        ->success()
                        ->send();
                } catch (DomainException $e) {
                    Notification::make()
                        ->title('Tidak bisa disetujui')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function tolak(string $name = 'tolak_penghapusan'): Action
    {
        return Action::make($name)
            ->label('Tolak')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('Tolak pelunasan piutang')
            ->schema([
                Textarea::make('catatan')
                    ->label('Kenapa ditolak')
                    ->helperText('Marketing yang mengajukan akan membaca ini.')
                    ->required()
                    ->maxLength(500),
            ])
            ->visible(fn (DebtRemoval $record) => static::mayDecide($record))
            ->action(function (DebtRemoval $record, array $data) {
                try {
                    app(DebtRemover::class)->reject($record, auth()->user(), $data['catatan']);

                    Notification::make()
                        ->title("Pengajuan untuk faktur {$record->invoice->nomor} ditolak")
                        ->success()
                        ->send();
                } catch (DomainException $e) {
                    Notification::make()
                        ->title('Tidak bisa ditolak')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
