<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Domain\Cart\CartEstimate;
use App\Domain\Cart\CartService;
use App\Domain\Cart\CartTotals;
use App\Domain\Money;
use App\Filament\Portal\Resources\Katalog\KatalogResource;
use App\Filament\Portal\Resources\Orders\OrderResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CustomerUser;
use App\Models\Warehouse;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The basket.
 *
 * Reorder handles the common case — last time's order again. This is the other
 * path: browse the catalogue, add things, adjust, submit.
 *
 * Every rupiah on this screen is an indication. Prices are resolved live and
 * nothing is stored, because the binding number is the snapshot the order line
 * takes at `confirmed`. The page says so out loud rather than leaving a buyer
 * to assume the total is a quote.
 *
 * Short stock and a tight credit limit are shown here too, and neither blocks
 * submission. Both are decided at `confirmed`, under a row lock — a decision
 * made on this screen would be stale by the time staff looked at it, and
 * refusing an order the warehouse could actually fill is worse than a warning.
 */
class Keranjang extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $navigationLabel = 'Keranjang';

    protected static ?int $navigationSort = 25;

    protected static ?string $slug = 'keranjang';

    protected string $view = 'filament.portal.pages.keranjang';

    /** Computed once per render, not once per row. */
    private ?CartEstimate $estimate = null;

    public function getTitle(): string
    {
        return 'Keranjang Anda';
    }

    public static function getNavigationBadge(): ?string
    {
        $buyer = auth('customer')->user();

        if (! $buyer instanceof CustomerUser) {
            return null;
        }

        $count = app(CartService::class)->itemCount($buyer);

        return $count > 0 ? (string) $count : null;
    }

    // --- state --------------------------------------------------------------

    public function buyer(): CustomerUser
    {
        $buyer = auth('customer')->user();

        if (! $buyer instanceof CustomerUser) {
            abort(403);
        }

        return $buyer;
    }

    public function cart(): Cart
    {
        return app(CartService::class)->forBuyer($this->buyer());
    }

    public function estimate(): CartEstimate
    {
        return $this->estimate ??= app(CartTotals::class)->for($this->cart());
    }

    // --- the table ----------------------------------------------------------

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => CartItem::query()->where('cart_id', $this->cart()->id))
            ->emptyStateHeading('Keranjang masih kosong')
            ->emptyStateDescription('Tambahkan barang dari katalog, atau ulangi pesanan sebelumnya.')
            ->emptyStateActions([
                Action::make('ke_katalog')
                    ->label('Buka katalog')
                    ->icon(Heroicon::OutlinedCube)
                    ->url(fn () => KatalogResource::getUrl()),
            ])
            ->columns([
                TextColumn::make('sku')->label('Kode'),

                TextColumn::make('nama')
                    ->label('Barang')
                    ->wrap()
                    ->state(fn (CartItem $record) => trim(
                        ($record->product?->merk ?? '').' '.($record->product?->description ?? '')
                    ) ?: $record->sku),

                TextColumn::make('ordered_qty')
                    ->label('Jumlah')
                    ->state(fn (CartItem $record) => $record->ordered_qty.' '.$record->ordered_unit->label()),

                TextColumn::make('qty_base')
                    ->label('Satuan dasar')
                    ->state(fn (CartItem $record) => $record->baseQuantity() === null
                        ? '—'
                        : $record->baseQuantity().' '.($record->product?->satuan_dasar ?? '')),

                TextColumn::make('harga')
                    ->label('Perkiraan harga')
                    ->state(function (CartItem $record) {
                        $line = $this->estimate()->line($record->id);

                        return $line->unitPrice === null ? '—' : Money::format($line->unitPrice);
                    }),

                TextColumn::make('subtotal')
                    ->label('Perkiraan subtotal')
                    ->weight('bold')
                    ->state(function (CartItem $record) {
                        $line = $this->estimate()->line($record->id);

                        return $line->lineTotal === null ? '—' : Money::format($line->lineTotal);
                    }),

                TextColumn::make('catatan')
                    ->label('Catatan')
                    ->badge()
                    ->color('danger')
                    ->placeholder('—')
                    ->state(fn (CartItem $record) => $this->estimate()->line($record->id)->problem),
            ])
            ->recordActions([
                Action::make('ubah_jumlah')
                    ->label('Ubah')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->schema(fn (CartItem $record) => [
                        TextInput::make('ordered_qty')
                            ->label('Jumlah ('.$record->ordered_unit->label().')')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->default($record->ordered_qty)
                            ->helperText('Isi 0 untuk menghapus baris ini.'),
                    ])
                    ->action(function (CartItem $record, array $data) {
                        app(CartService::class)->setQuantity(
                            $this->buyer(), $record, (int) $data['ordered_qty']
                        );

                        $this->refreshEstimate();
                    }),

                Action::make('hapus')
                    ->label('Hapus')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (CartItem $record) {
                        app(CartService::class)->remove($this->buyer(), $record);

                        $this->refreshEstimate();
                    }),
            ])
            ->paginated(false);
    }

    // --- header actions -----------------------------------------------------

    protected function getHeaderActions(): array
    {
        return [
            $this->checkoutAction(),
            $this->warehouseAction(),
            $this->clearAction(),
        ];
    }

    private function checkoutAction(): Action
    {
        return Action::make('checkout')
            ->label('Ajukan pesanan')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->visible(fn () => ! $this->cart()->isEmpty())
            ->modalHeading('Ajukan pesanan')
            ->modalDescription(
                'Tim kami akan mengonfirmasi harga dan ketersediaan stok sebelum '
                .'pesanan diproses. Angka di layar ini masih perkiraan.'
            )
            ->modalSubmitActionLabel('Ajukan')
            ->schema([
                TextInput::make('po_pelanggan')
                    ->label('Nomor PO Anda')
                    ->maxLength(60)
                    ->helperText('Opsional. Dipakai untuk mencocokkan tagihan di sisi Anda.'),

                Textarea::make('catatan')
                    ->label('Catatan')
                    ->rows(3)
                    ->maxLength(500),
            ])
            ->action(function (array $data) {
                try {
                    $order = app(CartService::class)->checkout(
                        $this->buyer(),
                        $data['po_pelanggan'] ?? null,
                        $data['catatan'] ?? null,
                    );
                } catch (DomainException $e) {
                    Notification::make()
                        ->title('Pesanan tidak bisa diajukan')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $this->refreshEstimate();

                Notification::make()
                    ->title("Pesanan {$order->nomor} diajukan")
                    ->body('Tim kami akan mengonfirmasi harga dan stok, lalu mengirimkan tagihan.')
                    ->success()
                    ->send();

                $this->redirect(
                    OrderResource::getUrl('view', ['record' => $order])
                );
            });
    }

    private function warehouseAction(): Action
    {
        return Action::make('gudang')
            ->label('Ganti gudang')
            ->icon(Heroicon::OutlinedBuildingStorefront)
            ->color('gray')
            // Only worth showing when there is a choice to make.
            ->visible(fn () => Warehouse::query()->where('aktif', true)->count() > 1)
            ->schema([
                Select::make('warehouse_id')
                    ->label('Gudang')
                    ->options(fn () => Warehouse::query()->where('aktif', true)->pluck('nama', 'id'))
                    ->default(fn () => $this->cart()->warehouse_id)
                    ->required(),
            ])
            ->action(function (array $data) {
                app(CartService::class)->setWarehouse($this->buyer(), (int) $data['warehouse_id']);

                $this->refreshEstimate();
            });
    }

    private function clearAction(): Action
    {
        return Action::make('kosongkan')
            ->label('Kosongkan')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            // Red still means "this destroys something", but outlined rather
            // than solid so it does not compete with the primary action beside
            // it. A filled red button next to "Ajukan pesanan" reads as the
            // more important of the two, which is backwards.
            ->outlined()
            ->visible(fn () => ! $this->cart()->isEmpty())
            ->requiresConfirmation()
            ->modalHeading('Kosongkan keranjang?')
            ->modalDescription('Semua baris akan dihapus. Tindakan ini tidak bisa dibatalkan.')
            ->action(function () {
                app(CartService::class)->clear($this->buyer());

                $this->refreshEstimate();
            });
    }

    /** Quantities changed, so last render's figures are stale. */
    private function refreshEstimate(): void
    {
        $this->estimate = null;

        $this->resetTable();
    }
}
