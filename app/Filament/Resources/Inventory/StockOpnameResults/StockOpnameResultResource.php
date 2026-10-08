<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\StockOpnameResults;

use App\Domain\Access\MenuKey;
use App\Domain\Inventory\OpnameApprover;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Format;
use App\Filament\Resources\Inventory\StockOpnameResults\Pages\CreateStockOpnameResult;
use App\Filament\Resources\Inventory\StockOpnameResults\Pages\EditStockOpnameResult;
use App\Filament\Resources\Inventory\StockOpnameResults\Pages\ListStockOpnameResults;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\LineItemFields;
use App\Filament\Support\NumberFields;
use App\Models\Inventory\ItemCost;
use App\Models\Inventory\StockOpnameOrder;
use App\Models\Inventory\StockOpnameResult;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
use Illuminate\Support\Str;

/** Stock Opname Results: the count against the system; approval by someone else posts the variance. */
class StockOpnameResultResource extends ErpResource
{
    protected static ?string $model = StockOpnameResult::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $modelLabel = 'Stock opname result';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::StockOpnameResults;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->schema([
                    DatePicker::make('trans_date')->label(__('Count date'))->required()->native(false)->default(today()),
                    Select::make('stock_opname_order_id')->label(__('Count order'))
                        ->options(fn () => StockOpnameOrder::query()->where('status', 'open')->orderByDesc('trans_date')->get()->mapWithKeys(fn ($o) => [$o->id => "{$o->number} · {$o->warehouse->name}"]))
                        ->required()->native(false)->live()
                        ->disabled(fn (?StockOpnameResult $record) => $record !== null)->dehydrated(),
                    NumberFields::make(TransactionType::StockOpnameResult, __('Count No.')),
                ]),
            Tabs::make('result')->tabs([
                Tab::make(__('fields.lines'))->schema([
                    Action::make('pull')
                        ->label(__('Pull the items of the order'))
                        ->icon('heroicon-m-arrow-down-tray')
                        ->visible(fn (?StockOpnameResult $record, Get $get) => ($record === null || ! $record->isApproved()) && $get('stock_opname_order_id'))
                        ->action(function (Set $set, Get $get): void {
                            $order = StockOpnameOrder::query()->find($get('stock_opname_order_id'));
                            if ($order === null) {
                                return;
                            }
                            $existing = collect($get('lines') ?? [])->pluck('item_id')->filter()->all();
                            $lines = $get('lines') ?? [];
                            foreach ($order->itemsToCount()->get() as $item) {
                                if (in_array($item->id, $existing, true)) {
                                    continue;
                                }
                                $system = ItemCost::query()->where('item_id', $item->id)->where('warehouse_id', $order->warehouse_id)->value('qty_on_hand') ?? '0';
                                $lines[(string) Str::uuid()] = ['item_id' => $item->id, 'counted_qty' => (string) $system, 'unit_id' => $item->unit1_id, 'base_quantity' => (string) $system, 'system_qty' => (string) $system];
                            }
                            $set('lines', array_filter($lines, fn ($l) => ! empty($l['item_id'])));
                        }),
                    Repeater::make('lines')->label(__('fields.lines'))
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn('sort')
                        ->table([
                            TableColumn::make(__('Item')),
                            TableColumn::make(__('Counted'))->alignment(Alignment::End),
                            TableColumn::make(__('Unit')),
                            TableColumn::make(__('System'))->alignment(Alignment::End),
                        ])
                        ->schema([
                            LineItemFields::item(stockedOnly: true),
                            LineItemFields::quantity('counted_qty', __('Counted'))->minValue(0),
                            LineItemFields::unit(),
                            TextInput::make('system_qty')->numeric()->disabled()->dehydrated(false)->default(0), // read from stock on every save, never from the form
                            LineItemFields::baseQuantity(),
                        ])
                        ->minItems(1)
                        ->defaultItems(0)
                        ->addActionLabel(__('Add item'))
                        ->disabled(fn (?StockOpnameResult $record) => $record?->isApproved() ?? false)
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => LineItemFields::fillBaseQuantities([$data], 'counted_qty')[0])
                        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => LineItemFields::fillBaseQuantities([$data], 'counted_qty')[0]),
                ]),
                Tab::make(__('fields.other_info'))->schema([
                    Textarea::make('description')->label(__('fields.description'))->rows(3),
                ]),
            ]),
        ])->columns(1);
    }

    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label(__('Approve and post the variance'))
            ->icon('heroicon-m-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('The differences between the count and the system become one inventory adjustment in the order\'s warehouse.'))
            ->visible(fn (StockOpnameResult $record) => app(OpnameApprover::class)->canApprove($record, auth()->user()))
            ->action(function (StockOpnameResult $record): void {
                try {
                    $adjustment = app(OpnameApprover::class)->approve($record, auth()->user());
                    Notification::make()->title(match (true) {
                        $adjustment !== null => __('Approved; variance posted as :number', ['number' => $adjustment->number]),
                        $record->fresh()->isApproved() => __('Approved; no differences'),
                        default => __('Approval recorded; the count waits for the other approvers'),
                    })->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title(__('Cannot approve'))->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('order'))
            ->columns([
                TextColumn::make('status')->label('#')->badge()->formatStateUsing(fn (string $state) => Format::code($state, 'opname'))->color(fn (string $state) => $state === 'approved' ? 'success' : 'warning'),
                Tanggal::make('trans_date')->label(__('Count date')),
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('order.number')->label(__('Count order'))->fontFamily('mono'),
                TextColumn::make('description')->label(__('fields.description'))->limit(50)->placeholder('—'),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([
                DocumentListFilters::dateRange(),
                SelectFilter::make('status')->label(__('Status'))->options(['draft' => __('Draft'), 'approved' => __('Approved')]),
            ])
            ->recordActions([EditAction::make(), self::approveAction()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockOpnameResults::route('/'),
            'create' => CreateStockOpnameResult::route('/create'),
            'edit' => EditStockOpnameResult::route('/{record}/edit'),
        ];
    }
}
