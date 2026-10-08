<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\InventoryAdjustments;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Filament\Resources\Inventory\InventoryAdjustments\Pages\CreateInventoryAdjustment;
use App\Filament\Resources\Inventory\InventoryAdjustments\Pages\EditInventoryAdjustment;
use App\Filament\Resources\Inventory\InventoryAdjustments\Pages\ListInventoryAdjustments;
use App\Filament\Support\ApprovalActions;
use App\Filament\Support\BranchFields;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\LineItemFields;
use App\Filament\Support\NumberFields;
use App\Filament\Support\PrintAction;
use App\Filament\Support\TagFields;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\ItemCost;
use App\Models\Inventory\Warehouse;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/** Inventory Adjustments: quantity in or out, or a value correction, per item and warehouse. */
class InventoryAdjustmentResource extends ErpResource
{
    protected static ?string $model = InventoryAdjustment::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsVertical;

    protected static ?string $modelLabel = 'Inventory adjustment';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::InventoryAdjustments;
    }

    public static function form(Schema $schema): Schema
    {
        $seesCost = app(HakAkses::class)->allowsSpecial(auth()->user(), HakKhusus::SeeCost);

        return $schema->components([
            Section::make()
                ->columns(3)
                ->schema([
                    DatePicker::make('trans_date')->label(__('fields.trans_date'))->required()->native(false)->default(today()),
                    NumberFields::make(TransactionType::InventoryAdjustment, __('Adjustment No.')),
                    BranchFields::select(),
                    ...TagFields::header(),
                ]),
            Tabs::make('adjustment')->tabs([
                Tab::make(__('fields.lines'))->schema([
                    Repeater::make('lines')->label(__('fields.lines'))
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn('sort')
                        ->table([
                            TableColumn::make(__('Item')),
                            TableColumn::make(__('Type')),
                            TableColumn::make(__('Quantity'))->alignment(Alignment::End),
                            TableColumn::make(__('Unit')),
                            TableColumn::make(__('Unit cost'))->alignment(Alignment::End),
                            TableColumn::make(__('Warehouse')),
                            ...TagFields::columns(),
                            TableColumn::make(__('Memo')),
                        ])
                        ->schema([
                            LineItemFields::item(stockedOnly: true),
                            Select::make('adjustment_type')->label(__('Adjustment type'))->options(['quantity' => __('Quantity'), 'value' => __('Value')])->default('quantity')->required()->native(false)->live(),
                            LineItemFields::quantity()->placeholder(__('negative = out'))->disabled(fn (Get $get) => $get('adjustment_type') === 'value')->dehydrated(),
                            LineItemFields::unit(),
                            TextInput::make('unit_cost')->label(__('Unit cost'))->numeric()->default(0)->prefix(Format::symbol())
                                ->disabled(fn (Get $get) => ! $seesCost || $get('adjustment_type') === 'value')->dehydrated(),
                            Select::make('warehouse_id')->label(__('Warehouse'))->options(fn () => Warehouse::query()->visibleTo(auth()->user())->where('is_system', false)->where('is_active', true)->orderBy('name')->pluck('name', 'id'))->required()->native(false)
                                ->default(fn () => Warehouse::default()?->id),
                            ...TagFields::lineFields(),
                            TextInput::make('memo')->label(__('Memo'))->maxLength(255),
                            TextInput::make('total_cost')->label(__('Value change'))->numeric()->default(0)->prefix(Format::symbol())
                                ->visible(fn (Get $get) => $get('adjustment_type') === 'value'),
                            Select::make('adjustment_account_id')->label(__('Adjustment account'))->options(fn () => Account::options(AccountType::CostOfSales, AccountType::Expense, AccountType::OtherExpense, AccountType::OtherIncome, AccountType::Equity))->searchable()->native(false)->placeholder(__('Inventory Adjustments (default)')),
                            LineItemFields::baseQuantity(),
                        ])
                        ->minItems(1)
                        ->defaultItems(1)
                        ->addActionLabel(__('Add line'))
                        // Without the "see cost" right the costs never reach the page (a save sets them again from the current cost).
                        ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => $seesCost ? $data : ['unit_cost' => null, 'total_cost' => null] + $data)
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => self::normaliseLine($data))
                        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => self::normaliseLine($data)),
                ]),
                Tab::make(__('fields.other_info'))->schema([
                    Textarea::make('description')->label(__('fields.description'))->rows(3),
                ]),
            ]),
        ])->columns(1);
    }

    private static function normaliseLine(array $data): array
    {
        $data = LineItemFields::fillBaseQuantities([$data])[0];
        // Without the "see cost" right nobody sets a cost: goods come in at their current cost, and a value
        // adjustment (which is nothing but a cost) is refused.
        if (! app(HakAkses::class)->allowsSpecial(auth()->user(), HakKhusus::SeeCost)) {
            if (($data['adjustment_type'] ?? 'quantity') === 'value') {
                throw ValidationException::withMessages(['data.lines' => __('A value adjustment takes the "see cost" right.')]);
            }
            $data['unit_cost'] = ItemCost::current((int) $data['item_id'], isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null);
        }
        if (($data['adjustment_type'] ?? 'quantity') === 'value') {
            $data['quantity'] = 0;
            $data['base_quantity'] = 0;
            $data['unit_cost'] = 0;
        } else {
            $data['total_cost'] = 0;
        }

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                TextColumn::make('description')->label(__('fields.description'))->limit(60)->placeholder('—'),
                TextColumn::make('lines_count')->label(__('Lines'))->counts('lines')->alignEnd(),
                Rupiah::make('lines_sum_total_cost')->label(__('Total cost'))->sum('lines', 'total_cost')->visible(fn () => HakAkses::canSpecial(HakKhusus::SeeCost)),
                IconColumn::make('is_opening')->label(__('Opening'))->boolean()->toggleable(isToggledHiddenByDefault: true),
                ApprovalActions::column(),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([DocumentListFilters::dateRange()])
            ->recordActions([...ApprovalActions::make(), EditAction::make()->hidden(fn (InventoryAdjustment $r) => $r->is_opening), PrintAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventoryAdjustments::route('/'),
            'create' => CreateInventoryAdjustment::route('/create'),
            'edit' => EditInventoryAdjustment::route('/{record}/edit'),
        ];
    }
}
