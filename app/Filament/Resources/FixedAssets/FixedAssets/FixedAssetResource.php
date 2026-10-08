<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\FixedAssets;

use App\Domain\Access\MenuKey;
use App\Domain\FixedAssets\DepreciationMethod;
use App\Domain\FixedAssets\FiscalDepreciator;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Filament\Resources\FixedAssets\FixedAssets\Pages\CreateFixedAsset;
use App\Filament\Resources\FixedAssets\FixedAssets\Pages\EditFixedAsset;
use App\Filament\Resources\FixedAssets\FixedAssets\Pages\ListFixedAssets;
use App\Filament\Support\BranchFields;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\ErpResource;
use App\Filament\Support\LineTotals;
use App\Filament\Support\NumberFields;
use App\Filament\Support\PricedDocumentForm;
use App\Models\FixedAssets\AssetCategory;
use App\Models\FixedAssets\AssetLocation;
use App\Models\FixedAssets\FiscalAssetCategory;
use App\Models\FixedAssets\FixedAsset;
use App\Models\GeneralLedger\Account;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
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
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Fixed Assets: bought from the accounts on the expenditure tab, depreciated
 * monthly by method over the useful life down to the salvage value.
 */
class FixedAssetResource extends ErpResource
{
    protected static ?string $model = FixedAsset::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $modelLabel = 'Fixed asset';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::FixedAssets;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->schema([
                    TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                    DatePicker::make('trans_date')->label(__('Purchase date'))->required()->native(false)->default(today())->live(),
                    DatePicker::make('usage_date')->label(__('In use from'))->required()->native(false)->default(today()),
                    NumberFields::make(TransactionType::FixedAsset, __('Asset code')),
                    Select::make('asset_category_id')->label(__('Asset category'))
                        ->options(fn () => AssetCategory::query()->active()->orderBy('name')->pluck('name', 'id'))
                        ->required()->native(false)->live()
                        ->afterStateUpdated(function (Set $set, $state, string $operation): void {
                            if ($operation !== 'create' || ! $state) {
                                return;
                            }
                            $category = AssetCategory::query()->find($state);
                            if ($category === null) {
                                return;
                            }
                            $set('asset_account_id', $category->asset_account_id);
                            $set('accumulated_depreciation_account_id', $category->accumulated_depreciation_account_id);
                            $set('depreciation_expense_account_id', $category->depreciation_expense_account_id);
                            $set('depreciation_method', $category->depreciation_method?->value);
                            $set('useful_life_months', $category->useful_life_months);
                        }),
                    Toggle::make('intangible')->label(__('Intangible asset'))->inline(false),
                    Select::make('depreciation_method')->label(__('Depreciation method'))->options(DepreciationMethod::class)->default(DepreciationMethod::StraightLine)->required()->native(false),
                    TextInput::make('quantity')->label(__('Quantity'))->numeric()->default(1)->required()->minValue(0.0001),
                    TextInput::make('useful_life_months')->label(__('Useful life (months)'))->numeric()->integer()->required()->default(48)->minValue(0),
                    PricedDocumentForm::money('salvage_value', __('Salvage value')),
                ]),
            Tabs::make('asset')
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make(__('General'))->columns(3)->schema([
                        self::accountSelect('asset_account_id', __('Asset account'), AccountType::FixedAsset, AccountType::OtherCurrentAsset),
                        self::accountSelect('accumulated_depreciation_account_id', __('Accumulated depreciation account'), AccountType::AccumulatedDepreciation),
                        self::accountSelect('depreciation_expense_account_id', __('Depreciation expense account'), AccountType::Expense, AccountType::OtherExpense),
                    ]),
                    Tab::make(__('Other info'))->columns(2)->schema([
                        Select::make('location_id')->label(__('Initial location'))
                            ->options(fn () => AssetLocation::options())
                            ->native(false)
                            ->createOptionForm([
                                TextInput::make('name')->label(__('Name'))->required()->maxLength(100),
                                TextInput::make('address')->label(__('Address'))->maxLength(255),
                            ])
                            ->createOptionUsing(fn (array $data) => AssetLocation::query()->create($data + ['is_active' => true])->id),
                        Textarea::make('notes')->label(__('Notes'))->rows(3)->columnSpanFull(),
                        Hidden::make('purchase_invoice_line_id')->dehydrated(),
                        Toggle::make('fiscal')->label(__('Fiscal asset'))->live()->inline(false),
                        Select::make('fiscal_asset_category_id')->label(__('Fiscal asset group'))
                            ->options(fn () => FiscalAssetCategory::query()->orderBy('name')->pluck('name', 'id'))
                            ->native(false)
                            ->visible(fn (Get $get): bool => (bool) $get('fiscal')),
                        BranchFields::select(__('Branch')),
                    ]),
                    Tab::make(__('Fiscal'))
                        ->visible(fn (?FixedAsset $record) => $record?->fiscal && $record->fiscal_asset_category_id)
                        ->schema([
                            Placeholder::make('fiscal_schedule')->hiddenLabel()
                                ->content(fn (?FixedAsset $record) => $record ? new HtmlString(view('filament.fixed-assets.fiscal-schedule', ['years' => FiscalDepreciator::years($record->loadMissing('fiscalCategory'))])->render()) : null),
                        ]),
                    Tab::make(__('Expenditure accounts'))->schema([
                        Repeater::make('expenditures')->label(__('Expenditures'))
                            ->hiddenLabel()
                            ->relationship()
                            ->orderColumn('sort')
                            ->table([
                                TableColumn::make(__('Account')),
                                TableColumn::make(__('Description')),
                                TableColumn::make(__('Date')),
                                TableColumn::make(__('Amount'))->alignment(Alignment::End),
                            ])
                            ->schema([
                                Select::make('account_id')->label(__('Account'))->options(fn () => Account::options())->searchable()->required()->native(false),
                                TextInput::make('description')->label(__('Description'))->maxLength(255),
                                DatePicker::make('trans_date')->label(__('fields.trans_date'))->native(false)->default(fn (Get $get) => $get('../../trans_date')),
                                PricedDocumentForm::money('amount', __('Amount'))->required()->live(onBlur: true),
                            ])
                            ->minItems(1)
                            ->defaultItems(1)
                            ->live()
                            ->addActionLabel(__('Add expenditure')),
                        Placeholder::make('cost_preview')->label(__('Total cost'))->content(fn (Get $get) => Format::rupiah(LineTotals::sum($get('expenditures'), 'amount'))),
                    ]),
                ]),
        ])->columns(1);
    }

    private static function accountSelect(string $name, string $label, AccountType ...$types): Select
    {
        return Select::make($name)->label($label)->options(fn () => Account::options(...$types))->searchable()->required()->native(false);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['category', 'location']))
            ->columns([
                TextColumn::make('number')->label(__('Asset code'))->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable()->weight('medium')->wrap(),
                Tanggal::make('trans_date')->label(__('Purchase date')),
                TextColumn::make('category.name')->label(__('Category')),
                TextColumn::make('location.name')->label(__('Location'))->placeholder('—'),
                TextColumn::make('quantity')->label(__('Qty'))->alignEnd()->formatStateUsing(fn ($state): string => Format::quantity($state)),
                Rupiah::make('cost')->label(__('Total cost')),
                TextColumn::make('book_value')->label(__('Book value'))
                    ->state(fn (FixedAsset $record): int => $record->bookValue())
                    ->formatStateUsing(fn ($state): string => Format::number((int) $state))
                    ->alignEnd()
                    ->extraCellAttributes(['class' => 'ae-money']),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state): string => $state === FixedAsset::ACTIVE ? 'In use' : 'Disposed')
                    ->color(fn (string $state): string => $state === FixedAsset::ACTIVE ? 'success' : 'gray'),
            ])
            ->defaultSort('number')
            ->filters([
                SelectFilter::make('asset_category_id')->label(__('Asset category'))->relationship('category', 'name'),
                SelectFilter::make('location_id')->label(__('Location'))->relationship('location', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('schedule')
                    ->label(__('Depreciation'))
                    ->icon('heroicon-m-table-cells')
                    ->color('gray')
                    ->modalHeading(fn (FixedAsset $record): string => "Depreciation of {$record->number}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->modalContent(fn (FixedAsset $record) => view('filament.resources.fixed-assets.schedule', [
                        'rows' => $record->depreciations()->get(),
                        'asset' => $record,
                    ])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFixedAssets::route('/'),
            'create' => CreateFixedAsset::route('/create'),
            'edit' => EditFixedAsset::route('/{record}/edit'),
        ];
    }
}
