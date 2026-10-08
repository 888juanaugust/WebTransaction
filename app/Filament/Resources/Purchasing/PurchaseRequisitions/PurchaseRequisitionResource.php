<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchaseRequisitions;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Format;
use App\Filament\Resources\Purchasing\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Purchasing\PurchaseRequisitions\Pages\CreatePurchaseRequisition;
use App\Filament\Resources\Purchasing\PurchaseRequisitions\Pages\EditPurchaseRequisition;
use App\Filament\Resources\Purchasing\PurchaseRequisitions\Pages\ListPurchaseRequisitions;
use App\Filament\Support\ApprovalActions;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\LineItemFields;
use App\Filament\Support\NumberFields;
use App\Models\Purchasing\PurchaseRequisition;
use Filament\Actions\Action;
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
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/** Purchase Requisitions: what the business asks to buy; purchase orders pull from them. */
class PurchaseRequisitionResource extends ErpResource
{
    protected static ?string $model = PurchaseRequisition::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocument;

    protected static ?string $modelLabel = 'Purchase requisition';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::PurchaseRequisitions;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                DatePicker::make('trans_date')->label(__('fields.trans_date'))->required()->native(false)->default(today()),
                Select::make('requisition_type')->label(__('Request type'))->options(['buy' => __('Buy items'), 'send' => __('Send items')])->default('buy')->required()->native(false),
                NumberFields::make(TransactionType::PurchaseRequisition),
            ]),
            Tabs::make('requisition')->tabs([
                Tab::make(__('fields.lines'))->schema([
                    Repeater::make('lines')->label(__('fields.lines'))
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn('sort')
                        ->table([
                            TableColumn::make(__('Item')),
                            TableColumn::make(__('Quantity'))->alignment(Alignment::End),
                            TableColumn::make(__('Unit')),
                            TableColumn::make(__('Requested for')),
                            TableColumn::make(__('Estimated price'))->alignment(Alignment::End),
                            TableColumn::make(__('Memo')),
                        ])
                        ->schema([
                            LineItemFields::item(groups: false),
                            LineItemFields::quantity()->minValue(0.0001),
                            LineItemFields::unit(),
                            DatePicker::make('requested_date')->label(__('Requested date'))->native(false),
                            TextInput::make('estimated_price')->label(__('Estimated price'))->numeric()->default(0)->prefix(Format::symbol()),
                            TextInput::make('memo')->label(__('Memo'))->maxLength(255),
                            LineItemFields::baseQuantity(),
                        ])
                        ->minItems(1)->defaultItems(1)->addActionLabel(__('Add line'))
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => LineItemFields::fillBaseQuantities([$data])[0])
                        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => LineItemFields::fillBaseQuantities([$data])[0]),
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
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                TextColumn::make('requisition_type')->label(__('Request type'))->badge()->color('gray')->formatStateUsing(fn (string $state) => $state === 'buy' ? 'Buy items' : 'Send items'),
                TextColumn::make('description')->label(__('fields.description'))->limit(50)->placeholder('—'),
                TextColumn::make('status')->label(__('fields.status'))->badge()->formatStateUsing(fn (string $state) => __('status.fulfilment.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'processed' => 'success', 'partial' => 'warning', 'closed' => 'gray', default => 'info'
                    }),
                Rupiah::make('estimated_total')->label(__('Estimated total')),
                ApprovalActions::column(),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([DocumentListFilters::dateRange(), SelectFilter::make('requisition_type')->label(__('Request type'))->options(['buy' => __('Buy items'), 'send' => __('Send items')]), TernaryFilter::make('is_printed')->label(__('fields.is_printed'))])
            ->recordActions([
                ...ApprovalActions::make(),
                EditAction::make(),
                Action::make('order')->label(__('Create order'))->icon('heroicon-m-arrow-right-circle')->color('primary')
                    ->visible(fn (PurchaseRequisition $record) => in_array($record->status, ['pending', 'partial'], true) && PurchaseOrderResource::canCreate())
                    ->url(fn (PurchaseRequisition $record) => PurchaseOrderResource::getUrl('create', ['source' => $record->id])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseRequisitions::route('/'),
            'create' => CreatePurchaseRequisition::route('/create'),
            'edit' => EditPurchaseRequisition::route('/{record}/edit'),
        ];
    }
}
