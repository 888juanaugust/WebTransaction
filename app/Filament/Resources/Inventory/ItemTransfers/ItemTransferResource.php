<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\ItemTransfers;

use App\Domain\Access\MenuKey;
use App\Domain\Inventory\TransferReceiver;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Format;
use App\Filament\Resources\Inventory\ItemTransfers\Pages\CreateItemTransfer;
use App\Filament\Resources\Inventory\ItemTransfers\Pages\EditItemTransfer;
use App\Filament\Resources\Inventory\ItemTransfers\Pages\ListItemTransfers;
use App\Filament\Support\ApprovalActions;
use App\Filament\Support\BranchFields;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\LineItemFields;
use App\Filament\Support\NumberFields;
use App\Filament\Support\PrintAction;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemTransfer;
use App\Models\Inventory\Warehouse;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
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
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Item Transfers: a send puts goods in transit; "Receive" books them into the destination. */
class ItemTransferResource extends ErpResource
{
    protected static ?string $model = ItemTransfer::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $modelLabel = 'Item transfer';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::ItemTransfers;
    }

    public static function form(Schema $schema): Schema
    {
        $warehouses = fn () => Warehouse::query()->visibleTo(auth()->user())->where('is_system', false)->where('is_active', true)->orderBy('name')->pluck('name', 'id');

        return $schema->components([
            Section::make()
                ->columns(3)
                ->schema([
                    Select::make('item_transfer_type')->label(__('Process'))->options(['send' => __('Send goods'), 'receive' => __('Receive goods')])->default('send')->disabled()->dehydrated()->native(false),
                    Select::make('warehouse_id')->label(__('From warehouse'))->options($warehouses)->required()->native(false)->default(fn () => Warehouse::default()?->id),
                    Select::make('reference_warehouse_id')->label(__('To warehouse'))->options($warehouses)->required()->native(false)->different('warehouse_id'),
                    DatePicker::make('trans_date')->label(__('fields.trans_date'))->required()->native(false)->default(today()),
                    NumberFields::make(TransactionType::ItemTransfer, __('Transfer No.')),
                    BranchFields::select(),
                ]),
            Tabs::make('transfer')->tabs([
                Tab::make(__('fields.lines'))->schema([
                    Repeater::make('lines')->label(__('fields.lines'))
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn('sort')
                        ->table([
                            TableColumn::make(__('Item')),
                            TableColumn::make(__('Category')),
                            TableColumn::make(__('Quantity'))->alignment(Alignment::End),
                            TableColumn::make(__('Unit')),
                            TableColumn::make(__('Memo')),
                        ])
                        ->schema([
                            LineItemFields::item(stockedOnly: true),
                            Placeholder::make('category')->label(__('Category'))->hiddenLabel()->content(fn (Get $get) => $get('item_id') ? (Item::query()->with('category')->find($get('item_id'))?->category?->name ?? '—') : ''),
                            LineItemFields::quantity()->minValue(0.0001),
                            LineItemFields::unit(),
                            TextInput::make('memo')->label(__('Memo'))->maxLength(255),
                            LineItemFields::baseQuantity(),
                        ])
                        ->minItems(1)
                        ->defaultItems(1)
                        ->addActionLabel(__('Add line'))
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => LineItemFields::fillBaseQuantities([$data])[0])
                        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => LineItemFields::fillBaseQuantities([$data])[0])
                        ->disabled(fn (?ItemTransfer $record) => $record !== null && ! $record->isSend()),
                ]),
                Tab::make(__('fields.other_info'))->schema([
                    Textarea::make('description')->label(__('fields.description'))->rows(3),
                ]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['warehouse', 'referenceWarehouse']))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                TextColumn::make('item_transfer_type')->label(__('Process'))->badge()->formatStateUsing(fn (string $state) => $state === 'send' ? 'Send' : 'Receive')->color(fn (string $state) => $state === 'send' ? 'info' : 'success'),
                TextColumn::make('referenceWarehouse.name')->label(__('To / from')),
                TextColumn::make('warehouse.name')->label(__('Warehouse')),
                TextColumn::make('description')->label(__('fields.description'))->limit(40)->placeholder('—'),
                TextColumn::make('status')->label(__('Delivery status'))->badge()->formatStateUsing(fn (string $state) => __('status.fulfilment.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'processed' => 'success', 'partial' => 'warning', 'closed' => 'gray', default => 'info'
                    }),
                ApprovalActions::column(),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([
                DocumentListFilters::dateRange(),
                SelectFilter::make('item_transfer_type')->label(__('Process'))->options(['send' => __('Send'), 'receive' => __('Receive')]),
                SelectFilter::make('status')->label(__('Delivery status'))->options(['pending' => __('Pending'), 'partial' => __('Partial'), 'processed' => __('Processed')]),
                SelectFilter::make('warehouse_id')->label(__('From warehouse'))->relationship('warehouse', 'name'),
                SelectFilter::make('reference_warehouse_id')->label(__('To warehouse'))->relationship('referenceWarehouse', 'name'),
            ])
            ->recordActions([
                ...ApprovalActions::make(),
                EditAction::make()->visible(fn (ItemTransfer $r) => $r->isSend()),
                self::receiveAction(),
                PrintAction::make(),
            ]);
    }

    /** The standard's "Terima Barang": receive what is still in transit from a send. */
    public static function receiveAction(): Action
    {
        return Action::make('receive')
            ->label(__('Receive'))
            ->icon('heroicon-m-inbox-arrow-down')
            ->color('success')
            ->visible(fn (ItemTransfer $record) => $record->isSend() && in_array($record->status, ['pending', 'partial'], true) && static::canCreate())
            ->modalHeading(fn (ItemTransfer $record) => "Receive {$record->number} into {$record->referenceWarehouse->name}")
            ->schema(fn (ItemTransfer $record) => [
                DatePicker::make('trans_date')->label(__('Receipt date'))->required()->native(false)->default(today()),
                Repeater::make('quantities')
                    ->label(__('Quantities received'))
                    ->table([TableColumn::make(__('Item')), TableColumn::make(__('Still in transit')), TableColumn::make(__('Receive now'))])
                    ->schema([
                        TextInput::make('item')->disabled()->dehydrated(false),
                        TextInput::make('remaining')->disabled()->dehydrated(false),
                        TextInput::make('quantity')->label(__('Quantity'))->numeric()->minValue(0)->required(),
                        Hidden::make('line_id'),
                    ])
                    ->default($record->load('lines.item')->remainingLines()->map(fn ($l) => [
                        'line_id' => $l->id,
                        'item' => "{$l->item->number} · {$l->item->name}",
                        'remaining' => Format::quantity((string) BigDecimal::of((string) $l->base_quantity)->minus((string) $l->processed_quantity)),
                        'quantity' => (string) BigDecimal::of((string) $l->base_quantity)->minus((string) $l->processed_quantity)->toScale(4),
                    ])->values()->all())
                    ->addable(false)->deletable(false)->reorderable(false),
                Textarea::make('description')->label(__('fields.description'))->rows(2),
            ])
            ->action(function (ItemTransfer $record, array $data): void {
                try {
                    $quantities = collect($data['quantities'] ?? [])->mapWithKeys(fn ($row) => [(int) $row['line_id'] => $row['quantity']])->all();
                    $receipt = app(TransferReceiver::class)->receive($record, $quantities, CarbonImmutable::parse($data['trans_date']), null, $data['description'] ?? null);
                    Notification::make()->title(__('Received as :number', ['number' => $receipt->number]))->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title(__('Cannot receive'))->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListItemTransfers::route('/'),
            'create' => CreateItemTransfer::route('/create'),
            'edit' => EditItemTransfer::route('/{record}/edit'),
        ];
    }
}
