<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Domain\Billing\CreditNotePoster;
use App\Domain\Money;
use App\Models\CreditNote;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Throwable;

/**
 * Posting a credit note: the customer owes less, and the goods come back.
 *
 * Shared by the list and the edit page, so a note can be posted from wherever
 * it was found — the same reason the order transitions live in one place.
 *
 * The confirmation names what will happen rather than asking "are you sure",
 * because the numbers are not on the draft: every figure is computed at
 * posting, so until this runs there is nothing on the screen saying how much
 * the customer is about to be credited.
 */
class PostCreditNoteAction
{
    public static function make(string $name = 'posting'): Action
    {
        return Action::make($name)
            ->label('Posting')
            ->icon('heroicon-o-receipt-refund')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Posting nota kredit')
            ->modalDescription(function (CreditNote $record) {
                $lines = $record->lines()->get();
                $qty = (int) $lines->sum('qty_base');

                $text = sprintf(
                    'Tagihan pelanggan atas faktur %s akan berkurang, dan nilainya dihitung '
                    .'dari harga pada faktur tersebut — bukan harga hari ini.',
                    $record->invoice?->nomor ?? '—',
                );

                if ($record->jenis->movesStock() && $qty > 0) {
                    $text .= sprintf(
                        ' %s unit dasar akan masuk kembali ke gudang, dinilai dengan biaya '
                        .'saat barang dikirim dulu.',
                        number_format($qty, 0, ',', '.'),
                    );
                }

                return $text.' Setelah diposting, dokumen ini tidak bisa diubah lagi.';
            })
            ->modalSubmitActionLabel('Posting')
            ->visible(fn (CreditNote $record) => $record->isDraft()
                && (auth()->user()?->role()->canIssueCreditNote() ?? false))
            ->action(function (CreditNote $record) {
                try {
                    app(CreditNotePoster::class)->post($record, auth()->user());
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
                    ->title("Nota kredit {$record->nomor} diposting")
                    ->body(sprintf(
                        'Tagihan berkurang %s.%s',
                        Money::format((int) $record->total_rupiah),
                        $record->hpp_rupiah > 0
                            ? ' Barang masuk kembali ke stok senilai '.Money::format((int) $record->hpp_rupiah).'.'
                            : '',
                    ))
                    ->success()
                    ->send();
            });
    }
}
