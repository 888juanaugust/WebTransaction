<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchaseReturns;

use App\Domain\Access\MenuKey;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Format;
use App\Filament\Resources\Purchasing\PurchaseReturns\Pages\CreatePurchaseReturn;
use App\Filament\Resources\Purchasing\PurchaseReturns\Pages\EditPurchaseReturn;
use App\Filament\Resources\Purchasing\PurchaseReturns\Pages\ListPurchaseReturns;
use App\Filament\Support\ApprovalActions;
use App\Filament\Support\Columns\InCurrency;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\PayableFields;
use App\Filament\Support\PricedDocumentForm;
use App\Filament\Support\PrintAction;
use App\Filament\Support\PullAction;
use App\Filament\Support\VendorFields;
use App\Models\Purchasing\GoodsReceipt;
use App\Models\Purchasing\PurchaseInvoice;
use App\Models\Purchasing\PurchaseReturn;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Purchase Returns: goods back to the vendor from an invoice, a receipt, a down payment or without one; a debit note used in a payment. */
class PurchaseReturnResource extends ErpResource
{
    protected static ?string $model = PurchaseReturn::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static ?string $modelLabel = 'Purchase return';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::PurchaseReturns;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            PricedDocumentForm::header(VendorFields::select(), TransactionType::PurchaseReturn, __('Return No.'), [
                Select::make('return_type')->label(__('Return from'))->options(['invoice' => __('Invoice'), 'receipt' => __('Receipt'), 'none' => __('No invoice'), 'down_payment' => __('Down payment')])->default('invoice')->required()->native(false)->live()
                    ->afterStateUpdated(fn (Set $set) => $set('source_key', null)),
                Select::make('source_key')->label(__('Document'))
                    ->options(function (Get $get) {
                        $vendor = (int) $get('vendor_id');
                        if (! $vendor) {
                            return [];
                        }

                        return match ($get('return_type')) {
                            'invoice' => PurchaseInvoice::query()->where('vendor_id', $vendor)->orderByDesc('trans_date')->limit(50)->get()->mapWithKeys(fn ($d) => ['purchase_invoice:'.$d->id => $d->number.' · '.Format::date($d->trans_date)])->all(),
                            'receipt' => GoodsReceipt::query()->where('vendor_id', $vendor)->orderByDesc('trans_date')->limit(50)->get()->mapWithKeys(fn ($d) => ['goods_receipt:'.$d->id => $d->number.' · '.Format::date($d->trans_date)])->all(),
                            default => [],
                        };
                    })
                    ->native(false)->live()
                    ->visible(fn (Get $get) => in_array($get('return_type'), ['invoice', 'receipt'], true))
                    ->dehydrated(false)
                    ->afterStateHydrated(fn (Select $component, ?PurchaseReturn $record) => $component->state($record?->source_type ? "{$record->source_type}:{$record->source_id}" : null)),
                Hidden::make('source_type')->dehydrated(),
                Hidden::make('source_id')->dehydrated(),
            ]),
            Tabs::make('return')->tabs([
                PricedDocumentForm::linesTab(
                    before: [PullAction::make(__('Pull the lines of the document'), 'vendor_id',
                        function (Get $get) {
                            $key = $get('source_key');
                            $doc = $key ? PayableFields::resolve($key) ?? (str_starts_with($key, 'goods_receipt:') ? GoodsReceipt::query()->find((int) substr($key, 14)) : null) : null;

                            return $doc ? [$doc] : [];
                        },
                        function (int $id) {
                            return [];
                        },
                    )->action(function (Set $set, Get $get): void {
                        $key = (string) $get('source_key');
                        [$type, $id] = array_pad(explode(':', $key, 2), 2, null);
                        $doc = match ($type) {
                            'purchase_invoice' => PurchaseInvoice::query()->find((int) $id), 'goods_receipt' => GoodsReceipt::query()->find((int) $id), default => null
                        };
                        if ($doc === null) {
                            return;
                        }
                        $rows = [];
                        foreach ($doc->lines()->with('item')->get() as $line) {
                            $rows[(string) Str::uuid()] = ['item_id' => $line->item_id, 'quantity' => (string) $line->quantity, 'unit_id' => $line->unit_id, 'base_quantity' => (string) $line->base_quantity,
                                'unit_price' => (string) $line->unit_price, 'discount_percent' => (string) $line->discount_percent, 'tax_code_id' => $line->tax_code_id, 'warehouse_id' => $line->warehouse_id, 'memo' => $line->memo];
                        }
                        $set('lines', $rows);
                        $set('source_type', $type);
                        $set('source_id', (int) $id);
                        $set('taxable', $doc->taxable);
                        $set('inclusive_tax', $doc->inclusive_tax);
                    })],
                ),
                PricedDocumentForm::otherInfoTab(shipping: false),
                PricedDocumentForm::chargesTab(),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('vendor'))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                TextColumn::make('vendor.name')->label(__('fields.vendor'))->searchable(),
                TextColumn::make('return_type')->label(__('Return from'))->badge()->color('gray')->formatStateUsing(fn (string $state) => Format::code($state, 'return_type')),
                TextColumn::make('description')->label(__('fields.description'))->limit(40)->placeholder('—'),
                TextColumn::make('payment_status')->label(__('Credit used'))->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'paid' => __('Used'), 'partial' => __('Partly used'), default => __('Open')
                    })
                    ->color(fn (string $state) => match ($state) {
                        'paid' => 'success', 'partial' => 'warning', default => 'gray'
                    }),
                Rupiah::make('total')->label(__('fields.total')),
                ...InCurrency::make('fc_total'),
                ApprovalActions::column(),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([DocumentListFilters::dateRange(), SelectFilter::make('vendor_id')->label(__('fields.vendor'))->relationship('vendor', 'name')->searchable(), TernaryFilter::make('is_printed')->label(__('fields.is_printed'))])
            ->recordActions([...ApprovalActions::make(), EditAction::make(), PrintAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseReturns::route('/'),
            'create' => CreatePurchaseReturn::route('/create'),
            'edit' => EditPurchaseReturn::route('/{record}/edit'),
        ];
    }
}
