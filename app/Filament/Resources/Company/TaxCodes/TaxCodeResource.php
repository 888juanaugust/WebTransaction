<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\TaxCodes;

use App\Domain\Access\MenuKey;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Domain\Tax\TaxType;
use App\Filament\Resources\Company\TaxCodes\Pages\ManageTaxCodes;
use App\Filament\Support\MasterResource;
use App\Models\Company\TaxCode;
use App\Models\GeneralLedger\Account;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TaxCodeResource extends MasterResource
{
    protected static ?string $model = TaxCode::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $modelLabel = 'Tax code';

    protected static ?string $recordTitleAttribute = 'description';

    public static function menuKey(): MenuKey
    {
        return MenuKey::TaxCodes;
    }

    public static function form(Schema $schema): Schema
    {
        $accounts = fn () => Account::options(AccountType::OtherCurrentAsset, AccountType::OtherCurrentLiability, AccountType::AccountsPayable, AccountType::AccountsReceivable, AccountType::Expense, AccountType::OtherExpense);

        return $schema->components([
            Select::make('tax_type')->label(__('Tax type'))->options(TaxType::class)->required()->native(false),
            TextInput::make('description')->label(__('Description'))->required()->maxLength(120),
            TextInput::make('rate_percent')->label(__('Rate (%)'))->numeric()->minValue(0)->maxValue(100)->step('0.0001')->required()->default(0),
            Fieldset::make(__('Tax base'))
                ->columns(2)
                ->schema([
                    TextInput::make('dpp_numerator')->label(__('Numerator'))->numeric()->integer()->minValue(1)->default(1)->required(),
                    TextInput::make('dpp_denominator')->label(__('Denominator'))->numeric()->integer()->minValue(1)->default(1)->required(),
                ])
                ->columnSpanFull(),
            Select::make('sales_tax_account_id')->label(__('Sales tax account'))->options($accounts)->searchable()->required()->native(false),
            Select::make('purchase_tax_account_id')->label(__('Purchase tax account'))->options($accounts)->searchable()->required()->native(false),
            Toggle::make('is_default')->label(__('Default tax code'))->inline(false),
            self::activeToggle()->inline(false),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('description')->label(__('Description'))->searchable()->sortable(),
                TextColumn::make('tax_type')->label(__('Tax type'))->badge()->color('gray'),
                TextColumn::make('rate_percent')->label(__('Rate'))->state(fn (TaxCode $r) => Format::percent($r->rate_percent))->alignEnd(),
                TextColumn::make('effective')->label(__('Burden'))->state(fn (TaxCode $r) => Format::percent($r->effectiveRatePercent()))->alignEnd()
                    ->tooltip(fn (TaxCode $r) => __('Tax base :numerator/:denominator of the price', ['numerator' => $r->dpp_numerator, 'denominator' => $r->dpp_denominator])),
                IconColumn::make('is_default')->label(__('fields.is_default'))->boolean(),
                self::activeColumn(),
            ])
            ->defaultSort('description')
            ->filters([
                SelectFilter::make('tax_type')->label(__('Tax type'))->options(TaxType::class),
                self::activeFilter(),
            ])
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTaxCodes::route('/')];
    }
}
