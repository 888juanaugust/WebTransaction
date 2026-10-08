<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\AssetTransfers;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\FixedAssets\AssetTransfers\Pages\CreateAssetTransfer;
use App\Filament\Resources\FixedAssets\AssetTransfers\Pages\EditAssetTransfer;
use App\Filament\Resources\FixedAssets\AssetTransfers\Pages\ListAssetTransfers;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\NumberFields;
use App\Models\FixedAssets\AssetLocation;
use App\Models\FixedAssets\AssetTransfer;
use App\Models\FixedAssets\FixedAsset;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

/** Asset Transfers: assets moved between locations; no journal, their location follows. */
class AssetTransferResource extends ErpResource
{
    protected static ?string $model = AssetTransfer::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $modelLabel = 'Asset transfer';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::AssetTransfers;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                DatePicker::make('trans_date')->label(__('Date'))->required()->native(false)->default(today()),
                NumberFields::make(TransactionType::AssetTransfer, __('Transfer No.')),
                Select::make('from_location_id')->label(__('From location'))->options(fn () => AssetLocation::options())->required()->native(false)->live(),
                Select::make('to_location_id')->label(__('To location'))->options(fn () => AssetLocation::options())->required()->native(false)
                    ->different('from_location_id')
                    ->validationMessages(['different' => 'Pick two different locations.']),
            ]),
            Tabs::make('transfer')->tabs([
                Tab::make(__('Asset details'))->schema([
                    Repeater::make('lines')->label(__('fields.lines'))
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn('sort')
                        ->table([
                            TableColumn::make(__('Asset code')),
                            TableColumn::make(__('Asset name')),
                            TableColumn::make(__('Quantity'))->alignment(Alignment::End),
                            TableColumn::make(__('Memo')),
                        ])
                        ->schema([
                            Select::make('fixed_asset_id')->label(__('Asset'))
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search, Get $get) => FixedAsset::query()->active()
                                    ->when($get('../../from_location_id'), fn ($query, $location) => $query->where('location_id', $location))
                                    ->where(fn ($query) => $query->where('number', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"))
                                    ->orderBy('number')->limit(30)->get()
                                    ->mapWithKeys(fn (FixedAsset $a) => [$a->id => $a->number])->all())
                                ->getOptionLabelUsing(fn ($value) => FixedAsset::query()->find($value)?->number)
                                ->required()
                                ->live()
                                ->native(false)
                                ->afterStateUpdated(fn (Set $set, $state) => $set('quantity', $state ? FixedAsset::query()->find($state)?->quantityRemaining() : null)),
                            Placeholder::make('asset_name')->label(__('Asset name'))->hiddenLabel()->content(fn (Get $get) => ($id = $get('fixed_asset_id')) ? (FixedAsset::query()->find($id)?->name ?? '') : ''),
                            TextInput::make('quantity')->label(__('Quantity'))->numeric()->required(),
                            TextInput::make('memo')->label(__('Memo'))->maxLength(255),
                        ])
                        ->minItems(1)
                        ->defaultItems(1)
                        ->live()
                        ->addActionLabel(__('Add asset')),
                ]),
                Tab::make(__('Other info'))->schema([
                    Textarea::make('description')->label(__('Notes'))->rows(3),
                ]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['fromLocation', 'toLocation']))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('Date')),
                TextColumn::make('description')->label(__('Notes'))->limit(40)->placeholder('—'),
                TextColumn::make('fromLocation.name')->label(__('From')),
                TextColumn::make('toLocation.name')->label(__('To')),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([
                DocumentListFilters::dateRange(),
                SelectFilter::make('from_location_id')->label(__('From'))->options(fn () => AssetLocation::options()),
                SelectFilter::make('to_location_id')->label(__('To'))->options(fn () => AssetLocation::options()),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssetTransfers::route('/'),
            'create' => CreateAssetTransfer::route('/create'),
            'edit' => EditAssetTransfer::route('/{record}/edit'),
        ];
    }
}
