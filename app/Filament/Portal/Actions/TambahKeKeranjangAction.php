<?php

declare(strict_types=1);

namespace App\Filament\Portal\Actions;

use App\Domain\Cart\CartService;
use App\Domain\Uom\Unit;
use App\Models\CustomerUser;
use App\Models\Product;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * "Tambah ke keranjang" on a catalogue row.
 *
 * The unit matters more here than in a consumer shop: a bengkel ordering
 * `2 DUS` and `2 PCS` of the same part means two very different things, so the
 * unit is asked for explicitly and only the units this product actually
 * supports are offered. Guessing PCS would be a quiet way to ship a customer a
 * twelfth of what they wanted.
 */
class TambahKeKeranjangAction
{
    public static function make(string $name = 'tambah_keranjang'): Action
    {
        return Action::make($name)
            ->label('Tambah')
            ->icon(Heroicon::OutlinedShoppingCart)
            ->color('primary')
            ->modalHeading(fn (Product $record) => 'Tambah '.$record->kode.' ke keranjang')
            ->modalSubmitActionLabel('Tambah ke keranjang')
            ->schema(fn (Product $record) => [
                Select::make('ordered_unit')
                    ->label('Satuan')
                    ->options(self::unitsFor($record))
                    ->default($record->satuan_dasar)
                    ->required()
                    ->live()
                    ->helperText(fn () => $record->qty_per_ctn > 1
                        ? "1 DUS = {$record->qty_per_ctn} {$record->satuan_dasar}"
                        : null),

                TextInput::make('ordered_qty')
                    ->label('Jumlah')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(999_999)
                    ->default(1)
                    ->required(),
            ])
            ->action(function (Product $record, array $data) {
                $buyer = auth('customer')->user();

                if (! $buyer instanceof CustomerUser) {
                    return;
                }

                try {
                    app(CartService::class)->add(
                        $buyer,
                        $record->kode,
                        Unit::from($data['ordered_unit']),
                        (int) $data['ordered_qty'],
                    );
                } catch (DomainException|\InvalidArgumentException $e) {
                    Notification::make()
                        ->title('Tidak bisa ditambahkan')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title("{$record->kode} ditambahkan ke keranjang")
                    ->success()
                    ->send();
            });
    }

    /**
     * The units this product can actually be ordered in: its own base unit,
     * plus the carton when a carton size is known.
     *
     * Offering SET for a PCS product would only produce an error on submit.
     *
     * @return array<string, string>
     */
    private static function unitsFor(Product $product): array
    {
        $base = Unit::from($product->satuan_dasar);

        $units = [$base->value => $base->label()];

        if ($product->qty_per_ctn > 1) {
            $units[Unit::Ctn->value] = Unit::Ctn->label();
        }

        return $units;
    }
}
