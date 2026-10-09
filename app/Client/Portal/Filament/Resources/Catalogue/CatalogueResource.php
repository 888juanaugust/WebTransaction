<?php

declare(strict_types=1);

namespace App\Client\Portal\Filament\Resources\Catalogue;

use App\Client\Domain\Pricing\PriceReason;
use App\Client\Models\PriceListItem;
use App\Client\Models\PriceListVersion;
use App\Client\Portal\Domain\Cart;
use App\Client\Portal\Domain\CartEstimate;
use App\Client\Portal\Filament\Resources\Catalogue\Pages\ListCatalogue;
use App\Client\Portal\Filament\Support\PortalResource;
use App\Client\Portal\Portal;
use App\Domain\Sales\Contracts\Prices;
use App\Domain\Shared\Format;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemBrand;
use App\Models\Inventory\ItemCategory;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/** Catalogue: every item on sale, with the buyer's own price and whether the company's warehouses have it. Add to cart from here. */
class CatalogueResource extends PortalResource
{
    protected static ?string $model = Item::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $slug = 'catalogue';

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return __('Catalogue');
    }

    public static function getModelLabel(): string
    {
        return __('Item');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Catalogue');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->active()->whereIn('item_type', ['inventory', 'group'])->with(['brand', 'category', 'unit1', 'units.unit']);
    }

    public static function table(Table $table): Table
    {
        $customer = Portal::customer();
        $version = PriceListVersion::current();
        $ctn = fn (Item $item): ?int => $version ? (int) PriceListItem::query()->where('version_id', $version->id)->where('item_id', $item->id)->value('qty_per_ctn') ?: null : null;

        return $table
            ->columns([
                TextColumn::make('number')->label(__('Item code'))->fontFamily('mono')->searchable()->sortable(),
                TextColumn::make('brand.name')->label(__('Brand'))->placeholder('—')->sortable(),
                TextColumn::make('name')->label(__('Item name'))->searchable()->weight('medium')->wrap(),
                TextColumn::make('category.name')->label(__('Category'))->placeholder('—')->toggleable(),
                TextColumn::make('vehicle')->label(__('Vehicle'))->placeholder('—')->searchable()->toggleable(),
                TextColumn::make('part_number')->label(__('Part number'))->placeholder('—')->searchable()->fontFamily('mono')->toggleable(),
                TextColumn::make('unit1.name')->label(__('fields.unit')),
                TextColumn::make('ctn')->label(__('Per carton'))->alignEnd()->state(fn (Item $r) => $ctn($r))->placeholder('—'),
                TextColumn::make('your_price')->label(__('Your price'))->alignEnd()->weight('medium')
                    ->state(fn (Item $r) => self::priceText($r, $customer))
                    ->description(fn (Item $r) => __('per :unit', ['unit' => $r->unit1?->name])),
                TextColumn::make('availability')->label(__('Stock'))->badge()
                    ->state(fn (Item $r) => app(CartEstimate::class)->availability($r->id, '1'))
                    ->formatStateUsing(fn (string $state) => CartEstimate::availabilityLabel($state))
                    ->color(fn (string $state) => CartEstimate::availabilityColor($state)),
            ])
            ->filters([
                SelectFilter::make('brand_id')->label(__('Brand'))->options(fn () => ItemBrand::query()->orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('category_id')->label(__('Category'))->options(fn () => ItemCategory::query()->orderBy('name')->pluck('name', 'id')),
            ])
            ->defaultSort('name')
            ->paginated([25, 50, 100])
            ->recordActions([self::addToCart()])
            ->emptyStateHeading(__('Nothing on sale yet'));
    }

    private static function addToCart(): Action
    {
        return Action::make('add')
            ->label(__('Add to cart'))
            ->icon('heroicon-m-shopping-cart')
            ->color('primary')
            ->modalHeading(fn (Item $record) => $record->name)
            ->modalWidth('sm')
            ->schema([
                Select::make('unit_id')->label(__('fields.unit'))->options(fn (Item $record) => Cart::unitsOf($record))->default(fn (Item $record) => (int) $record->unit1_id)->required()->native(false),
                TextInput::make('quantity')->label(__('fields.quantity'))->numeric()->minValue(1)->default(1)->required(),
            ])
            ->action(function (Item $record, array $data): void {
                try {
                    app(Cart::class)->add(Portal::buyer(), $record, (int) $data['unit_id'], (string) $data['quantity']);
                    Notification::make()->title(__(':item added to your cart', ['item' => $record->name]))->success()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title(__('Cannot add'))->body($e->getMessage())->danger()->send();
                }
            });
    }

    public static function priceText(Item $item, $customer): string
    {
        $answer = app(Prices::class)->resolve($customer, $item, (int) $item->unit1_id, today(), '1');
        if (($answer['reason'] ?? null) === PriceReason::Unpriced->value) {
            return __('Ask us');
        }
        $discount = $answer['discount_percent'] ?? '0';
        $price = Format::money((int) round((float) $answer['price']));

        return (float) $discount > 0 ? $price.' − '.Format::percent($discount).'%' : $price;
    }

    public static function getPages(): array
    {
        return ['index' => ListCatalogue::route('/')];
    }
}
