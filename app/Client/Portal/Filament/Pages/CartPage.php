<?php

declare(strict_types=1);

namespace App\Client\Portal\Filament\Pages;

use App\Client\Models\PortalCartLine;
use App\Client\Portal\Domain\Cart;
use App\Client\Portal\Domain\CartEstimate;
use App\Client\Portal\Portal;
use App\Domain\Shared\Format;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use RuntimeException;

/**
 * The cart: the buyer's lines in the item's units, each priced today from
 * their own rules, with availability and the totals; the free credit and
 * a warning when the cart exceeds it; checkout, or clear.
 */
class CartPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'client.portal.pages.cart';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $slug = 'cart';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('Cart');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = app(Cart::class)->count(Portal::buyer());

        return $count > 0 ? (string) $count : null;
    }

    public function getTitle(): string
    {
        return __('Cart');
    }

    /** @return array<string, mixed> */
    public function estimate(): array
    {
        return app(CartEstimate::class)->of(app(Cart::class)->forBuyer(Portal::buyer()));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => PortalCartLine::query()->whereHas('cart', fn ($q) => $q->where('customer_user_id', Portal::buyer()->id))->with(['item.units', 'item.unit1', 'unit']))
            ->columns([
                TextColumn::make('item.number')->label(__('Item code'))->fontFamily('mono'),
                TextColumn::make('item.name')->label(__('Item name'))->weight('medium')->wrap(),
                TextInputColumn::make('quantity')->label(__('fields.quantity'))->type('number')->rules(['numeric', 'min:0'])
                    ->updateStateUsing(function (PortalCartLine $record, $state): string {
                        app(Cart::class)->setQuantity(Portal::buyer(), $record, (string) $state);

                        return (string) $state;
                    }),
                TextColumn::make('unit.name')->label(__('fields.unit')),
                TextColumn::make('price')->label(__('Your price'))->alignEnd()->state(fn (PortalCartLine $r) => $this->rowOf($r)['unpriced'] ? __('Ask us') : Format::money((int) round((float) $this->rowOf($r)['unit_price']))),
                TextColumn::make('amount')->label(__('fields.amount'))->alignEnd()->weight('medium')->state(fn (PortalCartLine $r) => Format::money($this->rowOf($r)['amount'])),
                TextColumn::make('availability')->label(__('Stock'))->badge()
                    ->state(fn (PortalCartLine $r) => $this->rowOf($r)['availability'])
                    ->formatStateUsing(fn (string $state) => CartEstimate::availabilityLabel($state))
                    ->color(fn (string $state) => CartEstimate::availabilityColor($state)),
            ])
            ->recordActions([
                Action::make('remove')->label(__('Remove'))->icon('heroicon-m-trash')->color('gray')
                    ->action(fn (PortalCartLine $record) => app(Cart::class)->remove(Portal::buyer(), $record)),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('Your cart is empty'))
            ->emptyStateDescription(__('Add items from the catalogue, or order your last order again from Home.'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('clear')->label(__('Clear the cart'))->icon('heroicon-m-trash')->color('gray')
                ->requiresConfirmation()
                ->visible(fn () => app(Cart::class)->count(Portal::buyer()) > 0)
                ->action(function (): void {
                    app(Cart::class)->clear(Portal::buyer());
                    Notification::make()->title(__('Cart cleared'))->success()->send();
                }),
        ];
    }

    /** @var array<int, array<string, mixed>>|null */
    private ?array $rows = null;

    /** @return array<string, mixed> */
    private function rowOf(PortalCartLine $line): array
    {
        if ($this->rows === null) {
            $this->rows = [];
            foreach ($this->estimate()['lines'] as $row) {
                $this->rows[(int) $row['line']->id] = $row;
            }
        }

        return $this->rows[(int) $line->id] ?? ['unpriced' => true, 'unit_price' => '0', 'amount' => 0, 'availability' => CartEstimate::ASK];
    }

    protected function handleRuntime(callable $work): void
    {
        try {
            $work();
        } catch (RuntimeException $e) {
            Notification::make()->title(__('Cannot do that'))->body($e->getMessage())->danger()->send();
        }
    }
}
