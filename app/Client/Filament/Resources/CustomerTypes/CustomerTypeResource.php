<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\CustomerTypes;

use App\Client\Domain\Customers\CustomerTypeTerms;
use App\Client\Filament\Resources\CustomerTypes\Pages\ManageCustomerTypes;
use App\Client\Models\CustomerType;
use App\Client\Screens\CentralScreen;
use App\Filament\Support\MasterResource;
use App\Models\Company\PaymentTerm;
use App\Models\Sales\PriceCategory;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Customer Types: the kinds of customer and the terms each trades on — its price tier (its promo), its payment term, its credit age limit. */
class CustomerTypeResource extends MasterResource
{
    protected static ?string $model = CustomerType::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $modelLabel = 'Customer type';

    protected static ?string $recordTitleAttribute = 'name';

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::CustomerTypes;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label(__('Code'))->maxLength(20)->required()->unique(ignoreRecord: true)->alphaDash(),
            TextInput::make('name')->label(__('fields.name'))->maxLength(100)->required(),
            Select::make('price_category_id')->label(__('Price tier (the promo)'))->options(fn () => PriceCategory::query()->orderBy('name')->pluck('name', 'id'))->native(false)
                ->helperText(__('Its adjustments and blanket discount are the type\'s promo; blank keeps each customer\'s own tier.')),
            Select::make('payment_term_id')->label(__('fields.payment_term'))->options(fn () => PaymentTerm::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))->native(false)
                ->helperText(__('The due days of the type\'s invoices; blank keeps each customer\'s own term.')),
            TextInput::make('credit_limit_age_days')->label(__('Block when an invoice is older than (days)'))->numeric()->integer()->minValue(0)
                ->helperText(__('The type\'s own limit before the company\'s freeze; blank or 0 means none.')),
            TextInput::make('description')->label(__('Description'))->maxLength(255),
            self::activeToggle()->inline(false),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['priceCategory', 'paymentTerm'])->withCount('customers'))
            ->columns([
                TextColumn::make('code')->label(__('Code'))->fontFamily('mono')->searchable()->sortable(),
                TextColumn::make('name')->label(__('fields.name'))->searchable()->sortable()->weight('medium'),
                TextColumn::make('priceCategory.name')->label(__('Price tier'))->placeholder('—'),
                TextColumn::make('paymentTerm.name')->label(__('fields.payment_term'))->placeholder('—'),
                TextColumn::make('credit_limit_age_days')->label(__('Age limit (days)'))->alignEnd()->placeholder('—'),
                TextColumn::make('customers_count')->label(__('Customers'))->alignEnd(),
                self::activeColumn(),
            ])
            ->defaultSort('code')
            ->filters([self::activeFilter()])
            ->recordActions([
                EditAction::make()->slideOver(),
                Action::make('reapply')->label(__('Re-apply type terms'))->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()->modalHeading(__('Re-apply the type\'s terms?'))
                    ->modalDescription(__('The tier, the payment term and the credit age limit of this type overwrite those of every customer of it. Each change is logged.'))
                    ->action(function (CustomerType $record): void {
                        $changed = app(CustomerTypeTerms::class)->applyToAll($record, auth()->user());
                        Notification::make()->title(__(':n customer(s) updated', ['n' => $changed]))->success()->send();
                    }),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCustomerTypes::route('/')];
    }
}
