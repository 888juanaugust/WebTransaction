<?php

declare(strict_types=1);

namespace App\Filament\Portal\Actions;

use App\Domain\Orders\BuyerOrderPlacer;
use App\Models\CustomerUser;
use App\Models\Order;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Reorder — item 1 on the buyer portal priority list, and by the spec's own
 * estimate 80% of what this portal is for.
 *
 * A B2B buyer restocks the same 15–20 SKUs forever. The whole interaction is
 * meant to be "same as last time, but three of these instead of two", so the
 * modal opens with last time's quantities already filled in and every line
 * editable. Setting a line to 0 drops it.
 *
 * It submits rather than confirms. Staff still approve — that is where credit
 * and stock are decided, and neither is a customer's call.
 */
class PesanUlangAction
{
    public static function make(string $name = 'pesan_ulang'): Action
    {
        return Action::make($name)
            ->label('Pesan ulang')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('primary')
            ->modalHeading(fn (Order $record) => "Pesan ulang {$record->nomor}")
            ->modalDescription(
                'Ubah jumlah bila perlu, lalu ajukan. Tim kami akan mengonfirmasi '
                .'harga dan ketersediaan stok sebelum pesanan diproses.'
            )
            ->modalSubmitActionLabel('Ajukan pesanan')
            // An order with no lines is not a template for anything.
            ->visible(fn (Order $record) => $record->lines()->exists())
            ->schema(fn (Order $record) => $record->lines
                ->map(fn ($line) => TextInput::make("qty.{$line->id}")
                    ->label(self::lineLabel($line))
                    ->helperText('Isi 0 untuk menghapus baris ini.')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(999_999)
                    ->required()
                    ->default($line->ordered_qty)
                    ->suffix($line->ordered_unit->label()))
                ->all())
            ->action(function (Order $record, array $data) {
                $buyer = auth('customer')->user();

                if (! $buyer instanceof CustomerUser) {
                    return;
                }

                try {
                    $baru = app(BuyerOrderPlacer::class)->repeat(
                        $buyer,
                        $record,
                        array_map(intval(...), $data['qty'] ?? []),
                    );
                } catch (DomainException $e) {
                    Notification::make()
                        ->title('Pesanan tidak bisa diajukan')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title("Pesanan {$baru->nomor} diajukan")
                    ->body('Tim kami akan mengonfirmasi harga dan stok, lalu mengirimkan tagihan.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Name the line the way the buyer knows it.
     *
     * Snapshots first: a historical order shows what was ordered then, not what
     * the catalogue happens to say now.
     */
    private static function lineLabel($line): string
    {
        $description = $line->description_snapshot
            ?? $line->product?->description
            ?? '';

        $merk = $line->merk_snapshot ?? $line->product?->merk ?? '';

        return trim("{$line->sku} — {$merk} {$description}");
    }
}
