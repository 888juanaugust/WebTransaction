<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\Shipments;

use App\Domain\Access\MenuKey;
use App\Filament\Resources\Company\Shipments\Pages\ManageShipments;
use App\Filament\Support\MasterResource;
use App\Models\Company\Shipment;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShipmentResource extends MasterResource
{
    protected static ?string $model = Shipment::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $modelLabel = 'Shipping method';

    public static function menuKey(): MenuKey
    {
        return MenuKey::ShippingMethods;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('shipment')->tabs([
                Tab::make(__('General'))->schema([
                    TextInput::make('name')->label(__('fields.name'))->required()->maxLength(100)->unique(ignoreRecord: true),
                    TextInput::make('pic_name')->label(__('Contact person'))->maxLength(100),
                    TextInput::make('pic_phone_number')->label(__('Phone number'))->tel()->maxLength(30),
                ]),
                Tab::make(__('Other info'))->schema([
                    Textarea::make('address')->label(__('Address'))->rows(3),
                    self::activeToggle()->inline(false),
                ]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('fields.name'))->searchable()->sortable(),
                TextColumn::make('pic_name')->label(__('Contact person'))->placeholder('—'),
                TextColumn::make('pic_phone_number')->label(__('Phone number'))->placeholder('—'),
                TextColumn::make('address')->label(__('Address'))->limit(50)->placeholder('—'),
                self::activeColumn(),
            ])
            ->defaultSort('name')
            ->filters([self::activeFilter()])
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageShipments::route('/')];
    }
}
