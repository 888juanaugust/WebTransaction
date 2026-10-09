<?php

declare(strict_types=1);

namespace App\Client\Portal\Filament\Pages;

use App\Client\Models\PortalCartLine;
use App\Client\Portal\Domain\BuyerOrderPlacer;
use App\Client\Portal\Domain\Cart;
use App\Client\Portal\Domain\CartEstimate;
use App\Client\Portal\Portal;
use App\Domain\Shared\Format;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
            Action::make('checkout')->label(__('Place the order'))->icon('heroicon-m-paper-airplane')->color('primary')
                ->visible(fn () => app(Cart::class)->count(Portal::buyer()) > 0)
                ->modalHeading(__('Place the order'))
                ->modalDescription(fn () => __('Total :total. The order goes to your marketing for approval; prices are set when it is placed.', ['total' => Format::money($this->estimate()['total'])]))
                ->schema([
                    TextInput::make('po_number')->label(__('Your PO number'))->maxLength(60),
                    Textarea::make('note')->label(__('Note for us'))->rows(2)->maxLength(500),
                    Checkbox::make('terms')->label(__('I order on the credit terms agreed with the company.'))->accepted()->required(),
                ])
                ->action(function (array $data): void {
                    $buyer = Portal::buyer();
                    $cart = app(Cart::class);
                    $cart->forBuyer($buyer)->forceFill(['po_number' => $data['po_number'] ?: null, 'note' => $data['note'] ?: null])->save();
                    try {
                        $order = app(BuyerOrderPlacer::class)->checkout($buyer, $cart);
                        Notification::make()->title(__('Order :number placed', ['number' => $order->number]))->body(__('It is with your marketing for approval.'))->success()->persistent()->send();
                        $this->redirect(Home::getUrl());
                    } catch (RuntimeException $e) {
                        Notification::make()->title(__('Cannot place the order'))->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
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
