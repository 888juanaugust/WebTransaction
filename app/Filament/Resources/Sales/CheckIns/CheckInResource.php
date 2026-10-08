<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\CheckIns;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Format;
use App\Filament\Resources\Sales\CheckIns\Pages\CreateCheckIn;
use App\Filament\Resources\Sales\CheckIns\Pages\EditCheckIn;
use App\Filament\Resources\Sales\CheckIns\Pages\ListCheckIns;
use App\Filament\Resources\Sales\SalesOrders\SalesOrderResource;
use App\Filament\Support\CustomerFields;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\NumberFields;
use App\Models\Company\Employee;
use App\Models\Sales\CheckIn;
use App\Models\Sales\Customer;
use App\Models\Sales\SalesOrder;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Check-ins: a salesperson's visit to a customer, where and when, and the order it led to. */
class CheckInResource extends ErpResource
{
    protected static ?string $model = CheckIn::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $modelLabel = 'Check-in';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::CheckIns;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                DateTimePicker::make('checked_in_at')->label(__('Checked in at'))->required()->native(false)->seconds(false)->default(now()),
                NumberFields::make(TransactionType::CheckIn),
                CustomerFields::select(fillsTerms: false)->required(false)
                    ->afterStateUpdated(fn (Set $set, $state) => $set('customer_name', $state ? Customer::query()->find($state)?->name : null)),
                TextInput::make('customer_name')->label(__('Customer name at check-in'))->required()->maxLength(150),
                Select::make('salesman_id')->label(__('fields.salesman'))->options(fn () => Employee::query()->salesmen()->orderBy('name')->pluck('name', 'id'))->required()->native(false)->searchable(),
                Select::make('sales_order_id')->label(__('Order taken'))->options(fn () => SalesOrder::query()->orderByDesc('trans_date')->limit(100)->pluck('number', 'id'))->searchable()->native(false),
                TextInput::make('latitude')->label(__('Latitude'))->numeric(),
                TextInput::make('longitude')->label(__('Longitude'))->numeric(),
                Textarea::make('notes')->label(__('fields.memo'))->rows(3)->columnSpanFull(),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['salesman', 'salesOrder']))
            ->columns([
                TextColumn::make('checked_in_at')->label(__('fields.trans_date'))->formatStateUsing(fn ($state) => Format::dateTime($state))->sortable(),
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('customer_name')->label(__('Customer (at check-in)'))->searchable(),
                TextColumn::make('salesman.name')->label(__('fields.salesman')),
                TextColumn::make('salesOrder.number')->label(__('Transaction'))->fontFamily('mono')->placeholder('—'),
                TextColumn::make('location')->label(__('Location'))->state(fn (CheckIn $r) => $r->latitude !== null ? "{$r->latitude}, {$r->longitude}" : null)->placeholder('—')->toggleable(),
            ])
            ->defaultSort('checked_in_at', 'desc')
            ->filters([DocumentListFilters::dateRange(), SelectFilter::make('salesman_id')->label(__('fields.salesman'))->options(fn () => Employee::query()->salesmen()->orderBy('name')->pluck('name', 'id'))])
            ->recordActions([
                EditAction::make(),
                Action::make('order')->label(__('Take an order'))->icon('heroicon-m-shopping-cart')->color('primary')
                    ->visible(fn (CheckIn $record) => $record->sales_order_id === null && $record->customer_id !== null && SalesOrderResource::canCreate())
                    ->url(fn (CheckIn $record) => SalesOrderResource::getUrl('create', ['customer' => $record->customer_id, 'check_in' => $record->id])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCheckIns::route('/'),
            'create' => CreateCheckIn::route('/create'),
            'edit' => EditCheckIn::route('/{record}/edit'),
        ];
    }
}
