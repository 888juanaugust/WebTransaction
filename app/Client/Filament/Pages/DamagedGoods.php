<?php

declare(strict_types=1);

namespace App\Client\Filament\Pages;

use App\Client\Domain\Stock\DamagedGoods as Goods;
use App\Client\Screens\CentralScreen;
use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Filament\Resources\Purchasing\PurchaseReturns\PurchaseReturnResource;
use App\Filament\Support\ErpPage;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\Item;
use App\Models\Inventory\Warehouse;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use RuntimeException;

/**
 * Damaged Goods: what sits in the damaged-goods warehouses, how old it is
 * and the return it came from; Purchasing writes it off (an inventory
 * adjustment on the loss account) or sends it back to the vendor.
 */
class DamagedGoods extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'client.pages.damaged-goods';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::DamagedGoods;
    }

    public function table(Table $table): Table
    {
        $seesCost = HakAkses::canSpecial(HakKhusus::SeeCost);

        return $table
            ->records(fn () => app(Goods::class)->rows()->keyBy('key'))
            ->columns(array_values(array_filter([
                TextColumn::make('number')->label(__('Item code'))->fontFamily('mono')->searchable(),
                TextColumn::make('name')->label(__('Item name'))->searchable(),
                TextColumn::make('warehouse')->label(__('Warehouse')),
                TextColumn::make('on_hand')->label(__('On hand'))->alignEnd()->formatStateUsing(fn ($state) => Format::quantity((string) $state)),
                TextColumn::make('oldest_days')->label(__('Age (days)'))->alignEnd(),
                TextColumn::make('return')->label(__('From return'))->fontFamily('mono')->placeholder('—'),
                $seesCost ? TextColumn::make('value')->label(__('Value'))->alignEnd()->formatStateUsing(fn ($state) => Format::money((int) $state)) : null,
            ])))
            ->recordActions([
                Action::make('write_off')->label(__('Write off'))->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->visible(fn () => static::canUpdate())
                    ->modalHeading(fn (array $record) => __('Write off :item', ['item' => $record['name']]))
                    ->fillForm(fn (array $record) => ['quantity' => $record['on_hand'], 'trans_date' => today()->toDateString(), 'account_id' => Goods::lossAccount()->id])
                    ->schema([
                        TextInput::make('quantity')->label(__('Quantity (base units)'))->numeric()->minValue(0.0001)->required(),
                        DatePicker::make('trans_date')->label(__('fields.trans_date'))->native(false)->required(),
                        Select::make('account_id')->label(__('Loss account'))->options(fn () => Account::query()->where('account_type', AccountType::Expense)->where('is_active', true)->orderBy('no')->get()->mapWithKeys(fn (Account $a) => [$a->id => "{$a->no} · {$a->name}"]))->required()->native(false)->searchable(),
                        TextInput::make('memo')->label(__('fields.memo'))->maxLength(255),
                    ])
                    ->action(function (array $record, array $data): void {
                        try {
                            $adjustment = app(Goods::class)->writeOff(Item::query()->findOrFail($record['item_id']), Warehouse::query()->findOrFail($record['warehouse_id']), (string) $data['quantity'], auth()->user(), $data['memo'] ?? null, (string) $data['trans_date'], (int) $data['account_id']);
                            Notification::make()->title(__('Written off: :number', ['number' => $adjustment->number]))->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title(__('Cannot write off'))->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
                Action::make('vendor_return')->label(__('Return to vendor'))->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->url(fn () => PurchaseReturnResource::getUrl('create'))->openUrlInNewTab()
                    ->visible(fn () => class_exists(PurchaseReturnResource::class)),
            ])
            ->emptyStateHeading(__('No damaged goods on hand'));
    }
}
