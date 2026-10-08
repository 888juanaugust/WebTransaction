<?php

declare(strict_types=1);

namespace App\Filament\Pages\Purchasing;

use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\MenuKey;
use App\Domain\Numbering\NumberGenerator;
use App\Domain\Numbering\TransactionType;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Shared\Format;
use App\Filament\Support\ErpPage;
use App\Models\Purchasing\PaymentOrderLine;
use App\Models\Purchasing\PurchasePayment;
use Carbon\CarbonImmutable;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Vendor Transfers: every invoice queued in a payment order and not yet paid,
 * with the vendor's bank account, ready to export to the bank or to pay in one
 * go: one purchase payment per vendor per order.
 */
class VendorTransfers extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.purchasing.vendor-transfers';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    public static function menuKey(): MenuKey
    {
        return MenuKey::VendorTransfers;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => PaymentOrderLine::query()->with(['paymentOrder.bankAccount', 'vendor.bankAccounts.bank'])->whereNull('purchase_payment_id')
                ->whereHas('paymentOrder', fn ($query) => $query->where('status', '!=', 'processed')))
            ->columns([
                TextColumn::make('paymentOrder.trans_date')->label(__('Transfer deadline'))->formatStateUsing(fn ($state) => Format::date($state))->sortable(),
                TextColumn::make('paymentOrder.number')->label(__('Order'))->fontFamily('mono'),
                TextColumn::make('vendor.name')->label(__('fields.vendor'))->searchable(),
                TextColumn::make('paymentOrder.payment_method')->label(__('Method'))->badge()->color('gray'),
                TextColumn::make('paymentOrder.bankAccount.name')->label(__('Bank'))->placeholder('—'),
                TextColumn::make('vendor_account')->label(__('Vendor\'s account No.'))->state(fn (PaymentOrderLine $r) => $r->vendor->bankAccounts->first()?->bank_account)->placeholder('—'),
                TextColumn::make('vendor_holder')->label(__('Account holder'))->state(fn (PaymentOrderLine $r) => trim(($r->vendor->bankAccounts->first()?->bank?->name ?? '').' '.($r->vendor->bankAccounts->first()?->bank_account_name ?? '')))->placeholder('—'),
                TextColumn::make('amount')->label(__('Amount'))->formatStateUsing(fn ($state) => Format::number((int) $state))->alignEnd(),
            ])
            ->defaultSort('id')
            ->toolbarActions([
                BulkAction::make('pay')
                    ->label(__('Pay the selected'))
                    ->icon('heroicon-m-banknotes')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription(__('One purchase payment per vendor and payment order, from the order\'s bank, dated today.'))
                    ->visible(fn () => app(HakAkses::class)->allows(auth()->user(), MenuKey::PurchasePayments, Hak::Create))
                    ->action(function (Collection $records): void {
                        $count = 0;
                        try {
                            DB::transaction(function () use ($records, &$count): void {
                                foreach ($records->groupBy(fn (PaymentOrderLine $l) => "{$l->payment_order_id}:{$l->vendor_id}") as $group) {
                                    $first = $group->first();
                                    $order = $first->paymentOrder;
                                    if ($order->bank_account_id === null) {
                                        throw new \RuntimeException(__('Payment order :number has no bank to pay from.', ['number' => $order->number]));
                                    }
                                    $series = app(NumberGenerator::class)->defaultSeries(TransactionType::CashBankVoucher, auth()->user());
                                    $payment = PurchasePayment::query()->create([
                                        'number' => app(NumberGenerator::class)->next($series, CarbonImmutable::today()),
                                        'series_id' => $series->id,
                                        'trans_date' => today(),
                                        'vendor_id' => $first->vendor_id,
                                        'bank_account_id' => $order->bank_account_id,
                                        'payment_method' => $order->payment_method,
                                        'description' => "Payment order {$order->number}",
                                        'created_by' => auth()->id(),
                                    ]);
                                    foreach ($group as $i => $line) {
                                        $payment->lines()->create(['sort' => $i, 'payable_type' => $line->payable_type, 'payable_id' => $line->payable_id, 'amount' => $line->amount, 'discount' => $line->discount]);
                                        $line->forceFill(['purchase_payment_id' => $payment->id])->saveQuietly();
                                    }
                                    $payment->refreshTotal();
                                    app(DocumentRepository::class)->created($payment);
                                    $order->refreshTotal();
                                    $count++;
                                }
                            });
                            Notification::make()->title(__(':count payment(s) recorded', ['count' => $count]))->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title(__('Cannot pay'))->body($e->getMessage())->danger()->persistent()->send();
                        }
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->emptyStateHeading(__('Nothing queued for transfer'))
            ->emptyStateDescription(__('Invoices put on a payment order appear here until they are paid.'));
    }
}
