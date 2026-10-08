<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\PaymentTerms;

use App\Domain\Access\MenuKey;
use App\Domain\Shared\Format;
use App\Filament\Resources\Company\PaymentTerms\Pages\ManagePaymentTerms;
use App\Filament\Support\MasterResource;
use App\Models\Company\PaymentTerm;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentTermResource extends MasterResource
{
    protected static ?string $model = PaymentTerm::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $modelLabel = 'Payment term';

    public static function menuKey(): MenuKey
    {
        return MenuKey::PaymentTerms;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('fields.name'))->required()->maxLength(60)->unique(ignoreRecord: true)->columnSpanFull(),
            Fieldset::make(__('Early payment discount'))
                ->columns(2)
                ->schema([
                    TextInput::make('discount_days')->label(__('If paid within (days)'))->numeric()->integer()->minValue(0)->default(0)->required(),
                    TextInput::make('discount_percent')->label(__('Discount (%)'))->numeric()->minValue(0)->maxValue(100)->step('0.01')->default(0)->required(),
                ])
                ->columnSpanFull(),
            TextInput::make('due_days')->label(__('Due in (days)'))->numeric()->integer()->minValue(0)->default(30)->required(),
            Textarea::make('memo')->label(__('fields.memo'))->rows(2)->columnSpanFull(),
            Toggle::make('is_default')->label(__('Default term'))->inline(false),
            self::activeToggle()->inline(false),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('fields.name'))->searchable()->sortable(),
                TextColumn::make('discount_percent')->label(__('Discount'))->state(fn (PaymentTerm $r) => Format::percent($r->discount_percent))->alignEnd(),
                TextColumn::make('discount_days')->label(__('Discount period (days)'))->alignEnd(),
                TextColumn::make('due_days')->label(__('Due (days)'))->alignEnd()->sortable(),
                TextColumn::make('memo')->label(__('fields.memo'))->limit(50)->placeholder('—'),
                self::activeColumn(),
                IconColumn::make('is_default')->label(__('fields.is_default'))->boolean(),
            ])
            ->defaultSort('name')
            ->filters([self::activeFilter()])
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePaymentTerms::route('/')];
    }
}
