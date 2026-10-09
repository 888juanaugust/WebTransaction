<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\ExpenseClaims;

use App\Client\Domain\Claims\ClaimStatus;
use App\Client\Filament\Resources\ExpenseClaims\Pages\CreateExpenseClaim;
use App\Client\Filament\Resources\ExpenseClaims\Pages\ListExpenseClaims;
use App\Client\Filament\Resources\ExpenseClaims\Pages\ViewExpenseClaim;
use App\Client\Models\ExpenseClaim;
use App\Client\Screens\CentralScreen;
use App\Domain\Shared\Format;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\ErpResource;
use App\Filament\Support\MoneyInput;
use App\Models\Sales\Customer;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Expense Claims: what a sales user spent on the road or for a customer, awaiting Finance's key. */
class ExpenseClaimResource extends ErpResource
{
    protected static ?string $model = ExpenseClaim::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $modelLabel = 'Expense claim';

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::ExpenseClaims;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The expense'))->columns(2)->schema([
                DatePicker::make('trans_date')->label(__('fields.date'))->native(false)->required()->default(today())->maxDate(today()),
                MoneyInput::make('amount')->label(__('Amount'))->prefix(Format::symbol())->required(),
                Select::make('customer_id')->label(__('For a customer'))
                    ->options(fn () => Customer::query()->where('is_active', true)->where('sales_user_id', auth()->id())->orderBy('name')->pluck('name', 'id'))
                    ->searchable()->native(false)->placeholder(__('None: road costs'))->columnSpanFull(),
                Textarea::make('description')->label(__('Spent on'))->rows(3)->required()->columnSpanFull()
                    ->helperText(__('What, where and for whom; the receipt stays with you for Finance.')),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['salesUser', 'customer', 'decidedBy']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tanggal::make('trans_date')->label(__('fields.date'))->sortable(),
                TextColumn::make('salesUser.name')->label(__('Sales'))->searchable()->weight('medium'),
                TextColumn::make('customer.name')->label(__('fields.customer'))->placeholder(__('road costs')),
                TextColumn::make('description')->label(__('Spent on'))->limit(50)->wrap(),
                Rupiah::make('amount')->label(__('Amount')),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state) => ClaimStatus::label($state))
                    ->color(fn (string $state) => ClaimStatus::color($state)),
                TextColumn::make('decidedBy.name')->label(__('Decided by'))->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options([
                    ClaimStatus::FILED => ClaimStatus::label(ClaimStatus::FILED),
                    ClaimStatus::VERIFIED => ClaimStatus::label(ClaimStatus::VERIFIED),
                    ClaimStatus::REJECTED => ClaimStatus::label(ClaimStatus::REJECTED),
                ])->default(ClaimStatus::FILED),
            ])
            ->recordActions([ViewAction::make()->label(__('Open'))])
            ->emptyStateHeading(__('No expense claims'))
            ->emptyStateDescription(__('A sales user files what they spent; Finance verifies it into a cash payment.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseClaims::route('/'),
            'create' => CreateExpenseClaim::route('/create'),
            'view' => ViewExpenseClaim::route('/{record}'),
        ];
    }
}
