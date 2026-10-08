<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\AssetDisposals;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Filament\Resources\FixedAssets\AssetDisposals\Pages\CreateAssetDisposal;
use App\Filament\Resources\FixedAssets\AssetDisposals\Pages\EditAssetDisposal;
use App\Filament\Resources\FixedAssets\AssetDisposals\Pages\ListAssetDisposals;
use App\Filament\Support\AssetFields;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\NumberFields;
use App\Filament\Support\PricedDocumentForm;
use App\Models\FixedAssets\AssetDisposal;
use App\Models\FixedAssets\AssetLocation;
use App\Models\FixedAssets\FixedAsset;
use App\Models\GeneralLedger\Account;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Asset Disposals: the quantity disposed of leaves the books with its share of cost and accumulated depreciation; what it fetched, less what it was worth, is the gain or loss. */
class AssetDisposalResource extends ErpResource
{
    protected static ?string $model = AssetDisposal::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxXMark;

    protected static ?string $modelLabel = 'Asset disposal';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::AssetDisposals;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                AssetFields::select()
                    ->afterStateUpdated(fn (Set $set, $state) => $set('quantity', $state ? FixedAsset::query()->find($state)?->quantityRemaining() : null)),
                NumberFields::make(TransactionType::FixedAssetDisposal, __('Disposal No.')),
                DatePicker::make('trans_date')->label(__('Date'))->required()->native(false)->default(today()),
                TextInput::make('quantity')->label(__('Quantity'))->numeric()->required()->minValue(0.0001),
                Select::make('gain_loss_account_id')->label(__('Gain / loss account'))->options(fn () => Account::options(AccountType::OtherIncome, AccountType::OtherExpense))->searchable()->required()->native(false),
                Select::make('location_id')->label(__('Asset location'))->options(fn () => AssetLocation::options())->native(false),
                Toggle::make('selling_asset')->label(__('Sold'))->live()->inline(false),
                PricedDocumentForm::money('proceeds', __('Proceeds'))->visible(fn (Get $get) => (bool) $get('selling_asset')),
                Select::make('proceeds_account_id')->label(__('Proceeds to'))->options(fn () => Account::options(AccountType::CashBank, AccountType::AccountsReceivable))->searchable()->native(false)
                    ->visible(fn (Get $get) => (bool) $get('selling_asset'))
                    ->required(fn (Get $get) => (bool) $get('selling_asset')),
                Textarea::make('description')->label(__('Notes'))->rows(2)->columnSpanFull(),
                Placeholder::make('book_value')->label(__('Book value of the asset'))
                    ->content(fn (Get $get) => ($id = $get('fixed_asset_id')) && ($asset = FixedAsset::query()->find($id)) ? Format::rupiah($asset->bookValue()).' for '.Format::quantity($asset->quantityRemaining()).' remaining' : '—'),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['fixedAsset', 'gainLossAccount']))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('Date')),
                TextColumn::make('description')->label(__('Notes'))->limit(40)->placeholder('—'),
                TextColumn::make('fixedAsset.number')->label(__('Asset'))->fontFamily('mono'),
                TextColumn::make('fixedAsset.name')->label(__('Asset name')),
                TextColumn::make('quantity')->label(__('Qty'))->alignEnd()->formatStateUsing(fn ($state) => Format::quantity($state)),
                Rupiah::make('proceeds')->label(__('Proceeds')),
                Rupiah::make('gain_loss')->label(__('Gain / (loss)')),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([
                DocumentListFilters::dateRange(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssetDisposals::route('/'),
            'create' => CreateAssetDisposal::route('/create'),
            'edit' => EditAssetDisposal::route('/{record}/edit'),
        ];
    }
}
