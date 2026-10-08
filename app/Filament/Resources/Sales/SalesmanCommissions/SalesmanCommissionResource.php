<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesmanCommissions;

use App\Domain\Access\MenuKey;
use App\Domain\Shared\Format;
use App\Filament\Resources\Sales\SalesmanCommissions\Pages\CommissionStatement;
use App\Filament\Resources\Sales\SalesmanCommissions\Pages\ManageSalesmanCommissions;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MoneyInput;
use App\Models\Sales\SalesmanCommission;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Salesman Commissions: a commission rule, for whom, under what requirement, what gain. Paid on settled invoices (the business rule). */
class SalesmanCommissionResource extends MasterResource
{
    protected static ?string $model = SalesmanCommission::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $modelLabel = 'Commission rule';

    public static function menuKey(): MenuKey
    {
        return MenuKey::SalesmanCommissions;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('commission')->tabs([
                Tab::make(__('Commission'))->schema([
                    TextInput::make('name')->label(__('Rule name'))->required()->maxLength(100),
                    Radio::make('active_period')->label(__('In force'))->options(['forever' => __('Always'), 'period' => __('For a period')])->default('forever')->live()->inline(),
                    Grid::make(2)->visible(fn (Get $get) => $get('active_period') === 'period')->schema([
                        DatePicker::make('from_date')->label(__('From'))->native(false),
                        DatePicker::make('to_date')->label(__('Until'))->native(false),
                    ]),
                    Radio::make('salesman_scope')->label(__('Salespeople'))->options(['all' => __('Everyone'), 'specific' => __('Chosen ones')])->default('all')->live()->inline(),
                    CheckboxList::make('salesmen')->label(__('Chosen salespeople'))->relationship('salesmen', 'name', fn ($query) => $query->where('is_salesman', true))->columns(3)
                        ->visible(fn (Get $get) => $get('salesman_scope') === 'specific'),
                    CheckboxList::make('levels')->label(__('Applies to levels'))->options([1 => __('First'), 2 => __('Second'), 3 => __('Third'), 4 => __('Fourth'), 5 => __('Fifth')])->columns(5),
                    Fieldset::make(__('Requirement'))->schema([
                        Radio::make('requirement')->hiddenLabel()->options([
                            'none' => __('No requirement'),
                            'sales_value' => __('Sales value between'),
                            'sales_qty' => __('Sales quantity between'),
                            'per_qty' => __('Per quantity sold'),
                        ])->default('none')->live(),
                        Grid::make(2)->visible(fn (Get $get) => in_array($get('requirement'), ['sales_value', 'sales_qty'], true))->schema([
                            TextInput::make('requirement_from')->label(__('From'))->numeric()->default(0),
                            TextInput::make('requirement_to')->label(__('To'))->numeric()->default(0),
                        ]),
                        TextInput::make('requirement_qty')->label(__('Per quantity'))->numeric()->default(0)->visible(fn (Get $get) => $get('requirement') === 'per_qty'),
                    ]),
                    Fieldset::make(__('Gain'))->columns(3)->schema([
                        Select::make('gain_type')->label(__('Commission is'))->options(['percent' => __('A percentage'), 'fixed' => __('A fixed amount')])->default('percent')->required()->native(false)->live(),
                        TextInput::make('gain_value')->label(__('Percent'))->numeric()->required()->default(0)->visible(fn (Get $get) => $get('gain_type') !== 'fixed'),
                        MoneyInput::make('gain_amount')->label(__('Amount (:symbol)', ['symbol' => Format::symbol()]))->required()->default(0)->visible(fn (Get $get) => $get('gain_type') === 'fixed'),
                        Select::make('gain_basis')->label(__('% of'))->options(['sales_value' => __('Sales value'), 'gross_profit' => __('Gross profit')])->default('sales_value')->native(false)->visible(fn (Get $get) => $get('gain_type') !== 'fixed'),
                    ]),
                ]),
                Tab::make(__('Other'))->schema([
                    Textarea::make('notes')->label(__('fields.memo'))->rows(3),
                    self::activeToggle()->inline(false),
                ]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('notes')->label(__('fields.memo'))->limit(40)->placeholder('—'),
                TextColumn::make('name')->label(__('Rule name'))->searchable()->sortable()->weight('medium'),
                TextColumn::make('period')->label(__('In force'))->state(fn (SalesmanCommission $r) => $r->periodLabel()),
                TextColumn::make('gain')->label(__('Gain'))->state(fn (SalesmanCommission $r) => $r->gain_type === 'fixed' ? Format::rupiah((int) $r->gain_amount) : __(':part of :whole', ['part' => Format::percent($r->gain_value), 'whole' => Format::code($r->gain_basis, 'commission_basis')])),
                self::activeColumn(),
            ])
            ->defaultSort('name')
            ->filters([self::activeFilter()])
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSalesmanCommissions::route('/'), 'statement' => CommissionStatement::route('/statement')];
    }
}
