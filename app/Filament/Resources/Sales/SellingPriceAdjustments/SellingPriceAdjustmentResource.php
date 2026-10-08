<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SellingPriceAdjustments;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Format;
use App\Filament\Resources\Sales\SellingPriceAdjustments\Pages\CreateSellingPriceAdjustment;
use App\Filament\Resources\Sales\SellingPriceAdjustments\Pages\EditSellingPriceAdjustment;
use App\Filament\Resources\Sales\SellingPriceAdjustments\Pages\ListSellingPriceAdjustments;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\LineItemFields;
use App\Filament\Support\NumberFields;
use App\Models\Sales\SellingPriceAdjustment;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/** Price / Discount Adjustments: new prices or discounts per item for a price category from a date; the resolver reads the one in force. */
class SellingPriceAdjustmentResource extends ErpResource
{
    protected static ?string $model = SellingPriceAdjustment::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $modelLabel = 'Price adjustment';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::PriceAndDiscountAdjustments;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                Select::make('price_category_id')->label(__('Price category'))->relationship('priceCategory', 'name')->preload()->required()->native(false),
                Select::make('sales_adjustment_type')->label(__('Adjustment type'))->options(['price' => __('Price'), 'discount' => __('Discount (%)')])->default('price')->required()->native(false)->live(),
                NumberFields::make(TransactionType::PriceAdjustment),
                DatePicker::make('trans_date')->label(__('Effective from'))->required()->native(false)->default(today()),
                DatePicker::make('end_date')->label(__('Ends on'))->native(false),
                Toggle::make('is_active')->label(__('fields.is_active'))->default(true)->inline(false),
            ]),
            Tabs::make('adjustment')->tabs([
                Tab::make(__('fields.lines'))->schema([
                    Repeater::make('lines')->label(__('fields.lines'))
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn('sort')
                        ->table([TableColumn::make(__('Item')), TableColumn::make(__('Unit')), TableColumn::make(__('From quantity'))->alignment(Alignment::End), TableColumn::make(__('New value'))->alignment(Alignment::End)])
                        ->schema([
                            LineItemFields::item(),
                            LineItemFields::unit(),
                            // A wholesale break: applies from this quantity (in the line's unit) on items that use wholesale prices.
                            TextInput::make('min_quantity')->label(__('From quantity'))->numeric()->minValue(0)->default(0),
                            TextInput::make('value')->label(__('New value'))->numeric()->required()->minValue(0)
                                ->prefix(fn (Get $get) => $get('../../sales_adjustment_type') === 'discount' ? '%' : Format::symbol()),
                        ])
                        ->minItems(1)->defaultItems(1)->addActionLabel(__('Add item')),
                ]),
                Tab::make(__('fields.other_info'))->schema([
                    Textarea::make('description')->label(__('fields.description'))->rows(3),
                ]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('priceCategory'))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('Effective from')),
                TextColumn::make('priceCategory.name')->label(__('Price category')),
                TextColumn::make('description')->label(__('fields.description'))->limit(40)->placeholder('—'),
                Tanggal::make('end_date')->label(__('Ends on'))->placeholder('—'),
                TextColumn::make('sales_adjustment_type')->label(__('Adjustment type'))->badge()->color('gray')->formatStateUsing(fn (string $state) => $state === 'price' ? __('Price') : __('Discount (%)')),
                IconColumn::make('is_active')->label(__('fields.is_active'))->boolean(),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([
                DocumentListFilters::dateRange('trans_date', __('Effective')),
                TernaryFilter::make('is_active')->label(__('fields.is_active')),
                SelectFilter::make('price_category_id')->label(__('Price category'))->relationship('priceCategory', 'name'),
                SelectFilter::make('sales_adjustment_type')->label(__('Adjustment type'))->options(['price' => __('Price'), 'discount' => __('Discount (%)')]),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSellingPriceAdjustments::route('/'),
            'create' => CreateSellingPriceAdjustment::route('/create'),
            'edit' => EditSellingPriceAdjustment::route('/{record}/edit'),
        ];
    }
}
