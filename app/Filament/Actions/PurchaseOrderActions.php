<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Domain\Money;
use App\Domain\Purchasing\PurchaseOrderFlow;
use App\Domain\Purchasing\PurchaseOrderStatus;
use App\Domain\Purchasing\ThreeWayMatch;
use App\Models\PurchaseOrder;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * Every purchase order transition, in one place, for every screen that offers
 * one — the same arrangement OrderTransitionActions uses on the sell side.
 *
 * None of these writes `status`. That is PurchaseOrderFlow's job alone, and it
 * logs an actor for every move.
 */
class PurchaseOrderActions
{
    /** Send to the supplier. This is what locks the lines. */
    public static function kirim(string $name = 'kirim_po'): Action
    {
        return Action::make($name)
            ->label('Kirim ke pemasok')
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Kirim pesanan pembelian')
            ->modalDescription(fn (PurchaseOrder $record) => sprintf(
                'Nilai pesanan %s. Setelah dikirim, baris tidak bisa diubah lagi — '
                .'dokumen ini menjadi catatan apa yang disepakati.',
                Money::format((int) $record->lines()->sum('line_value_rupiah')),
            ))
            ->visible(fn (PurchaseOrder $record) => $record->status === PurchaseOrderStatus::Draft
                && (auth()->user()?->role()->canRecordPurchases() ?? false))
            ->action(fn (PurchaseOrder $record) => self::run(
                fn () => app(PurchaseOrderFlow::class)->send($record, auth()->user()),
                "PO {$record->nomor} dikirim",
                'Baris terkunci. Penerimaan barang sekarang bisa dicatat terhadap PO ini.',
            ));
    }

    /** Close: everything arrived, or nothing more will. */
    public static function selesaikan(string $name = 'selesaikan_po'): Action
    {
        return Action::make($name)
            ->label('Selesaikan')
            ->icon('heroicon-o-check-circle')
            ->color('gray')
            ->modalHeading('Selesaikan pesanan pembelian')
            ->modalDescription('Sisa barang yang belum datang akan dicatat sebagai tidak jadi diterima.')
            ->schema([
                Textarea::make('alasan')
                    ->label('Alasan')
                    ->rows(2)
                    ->helperText('Opsional. Diisi bila pesanan ditutup sebelum lengkap.'),
            ])
            ->visible(fn (PurchaseOrder $record) => $record->status === PurchaseOrderStatus::Dikirim
                && (auth()->user()?->role()->canRecordPurchases() ?? false))
            ->action(fn (PurchaseOrder $record, array $data) => self::run(
                fn () => app(PurchaseOrderFlow::class)->close(
                    $record, auth()->user(), $data['alasan'] ?? null
                ),
                "PO {$record->nomor} selesai",
            ));
    }

    public static function batalkan(string $name = 'batalkan_po'): Action
    {
        return Action::make($name)
            ->label('Batalkan')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('Batalkan pesanan pembelian')
            ->schema([
                Textarea::make('alasan')
                    ->label('Alasan pembatalan')
                    ->required()
                    ->maxLength(500)
                    ->helperText('Tercatat di jejak audit.'),
            ])
            // Hidden once goods have arrived: a cancelled order that stock was
            // received against would leave the receipt pointing at nothing.
            ->visible(fn (PurchaseOrder $record) => $record->status->canTransitionTo(PurchaseOrderStatus::Dibatalkan)
                && (int) $record->lines()->sum('qty_base_received') === 0
                && (auth()->user()?->role()->canRecordPurchases() ?? false))
            ->action(fn (PurchaseOrder $record, array $data) => self::run(
                fn () => app(PurchaseOrderFlow::class)->cancel($record, auth()->user(), $data['alasan']),
                "PO {$record->nomor} dibatalkan",
            ));
    }

    /**
     * Ordered against received against billed.
     *
     * A read-only modal rather than a page: it is something you look at while
     * deciding whether to pay a bill, not somewhere you navigate to.
     */
    public static function cocokkan(string $name = 'cocokkan'): Action
    {
        return Action::make($name)
            ->label('Cocokkan')
            // Not an icon button. It was one while these sat inline on the row
            // and width was tight; inside the action menu that strips the label
            // and leaves an unlabelled glyph nobody can identify.
            ->icon('heroicon-o-scale')
            ->color('gray')
            ->modalHeading(fn (PurchaseOrder $record) => "Pencocokan tiga arah — {$record->nomor}")
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Tutup')
            ->modalWidth('5xl')
            ->modalContent(fn (PurchaseOrder $record) => view(
                'filament.purchasing.three-way-match',
                ['lines' => app(ThreeWayMatch::class)->forPurchaseOrder($record)],
            ))
            // Nothing to compare until the order has gone out.
            ->visible(fn (PurchaseOrder $record) => $record->status !== PurchaseOrderStatus::Draft
                && (auth()->user()?->role()->canRecordPurchases() ?? false));
    }

    /**
     * The document the supplier actually receives.
     *
     * Hidden while the order is a draft: nothing has been agreed, the lines are
     * still being edited, and printing one would create an obligation the
     * system does not believe exists. The controller refuses it either way.
     */
    public static function cetak(string $name = 'cetak_po'): Action
    {
        return Action::make($name)
            ->label('Cetak PO')
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->url(fn (PurchaseOrder $record) => route('dokumen.pesanan-pembelian', $record))
            ->openUrlInNewTab()
            ->visible(fn (PurchaseOrder $record) => $record->status !== PurchaseOrderStatus::Draft
                && (auth()->user()?->role()->canRecordPurchases() ?? false));
    }

    /** @return list<Action> */
    public static function all(): array
    {
        return [self::kirim(), self::cetak(), self::cocokkan(), self::selesaikan(), self::batalkan()];
    }

    private static function run(callable $do, string $title, ?string $body = null): void
    {
        try {
            $do();

            Notification::make()->title($title)->body($body)->success()->send();
        } catch (DomainException $e) {
            Notification::make()
                ->title('Tidak bisa dilakukan')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
