<?php

declare(strict_types=1);

namespace App\Client\Portal\Filament\Resources\Orders;

use App\Client\Portal\Domain\BuyerOrderPlacer;
use App\Client\Portal\Filament\Resources\Orders\Pages\ListOrders;
use App\Client\Portal\Filament\Resources\Orders\Pages\ViewOrder;
use App\Client\Portal\Filament\Support\PortalResource;
use App\Client\Portal\Filament\Support\ScopedToBuyer;
use App\Client\Portal\Portal;
use App\Domain\Shared\Format;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Models\Sales\SalesOrder;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;

/** Orders: the buyer's own, with where each stands; open one for its lines, deliveries and invoices; order it again. */
class OrderResource extends PortalResource
{
    use ScopedToBuyer;

    protected static ?string $model = SalesOrder::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $slug = 'orders';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationLabel(): string
    {
        return __('Orders');
    }

    public static function getModelLabel(): string
    {
        return __('Order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Orders');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('trans_date', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Order No.'))->fontFamily('mono')->searchable(),
                Tanggal::make('trans_date')->label(__('fields.trans_date'))->sortable(),
                TextColumn::make('po_number')->label(__('Your PO'))->placeholder('—')->searchable(),
                TextColumn::make('approval_status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (SalesOrder $r) => self::statusLabel($r))
                    ->color(fn (SalesOrder $r) => self::statusColor($r)),
                Rupiah::make('total')->label(__('fields.total')),
            ])
            ->filters([
                SelectFilter::make('approval_status')->label(__('Status'))->options([
                    SalesOrder::AWAITING => Format::code(SalesOrder::AWAITING, 'approval'),
                    SalesOrder::APPROVED => Format::code(SalesOrder::APPROVED, 'approval'),
                    SalesOrder::REJECTED => Format::code(SalesOrder::REJECTED, 'approval'),
                ]),
            ])
            ->recordActions([ViewAction::make()->label(__('Open')), self::reorderAction()])
            ->emptyStateHeading(__('No orders yet'));
    }

    /** Awaiting, rejected, or the fulfilment state once approved. */
    public static function statusLabel(SalesOrder $order): string
    {
        if ($order->approval_status !== SalesOrder::APPROVED) {
            return Format::code($order->approval_status, 'approval');
        }

        return match ($order->status) {
            'pending' => __('Approved, being prepared'),
            'partial' => __('Partly delivered'),
            'processed' => __('Delivered'),
            'closed' => __('Closed'),
            default => Format::code($order->status, 'fulfilment'),
        };
    }

    public static function statusColor(SalesOrder $order): string
    {
        return match (true) {
            $order->approval_status === SalesOrder::REJECTED => 'danger',
            $order->approval_status === SalesOrder::AWAITING => 'warning',
            in_array($order->status, ['processed', 'closed'], true) => 'success',
            default => 'info',
        };
    }

    public static function reorderAction(): Action
    {
        return Action::make('reorder')
            ->label(__('Order again'))
            ->icon('heroicon-m-arrow-path')
            ->color('primary')
            ->modalHeading(fn (SalesOrder $record) => __('Order :number again', ['number' => $record->number]))
            ->fillForm(fn (SalesOrder $record) => ['lines' => $record->lines()->with(['item', 'unit'])->get()->map(fn ($l) => ['line_id' => $l->id, 'item' => ($l->item?->number ?? '').' — '.($l->item?->name ?? '').' ('.($l->unit?->name ?? '').')', 'quantity' => rtrim(rtrim((string) $l->quantity, '0'), '.')])->all()])
            ->schema([
                Repeater::make('lines')->label(__('Lines'))->schema([
                    Hidden::make('line_id'),
                    TextInput::make('item')->label(__('Item'))->disabled()->dehydrated(false)->columnSpan(2),
                    TextInput::make('quantity')->label(__('fields.quantity'))->numeric()->minValue(0)->required(),
                ])->columns(3)->addable(false)->deletable(false)->reorderable(false),
            ])
            ->action(function (SalesOrder $record, array $data): void {
                $quantities = [];
                foreach ((array) ($data['lines'] ?? []) as $row) {
                    $quantities[(int) ($row['line_id'] ?? 0)] = (string) ($row['quantity'] ?? 0);
                }
                try {
                    $again = app(BuyerOrderPlacer::class)->repeat(Portal::buyer(), $record, $quantities);
                    Notification::make()->title(__('Order :number placed', ['number' => $again->number]))->body(__('It is with your marketing for approval.'))->success()->persistent()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title(__('Cannot place the order'))->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
