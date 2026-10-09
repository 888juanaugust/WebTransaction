<?php

declare(strict_types=1);

namespace App\Client\Filament\Pages;

use App\Client\Domain\Orders\OrderSplitter;
use App\Client\Domain\Orders\SplitPlan;
use App\Client\Domain\Teams\TeamAssigner;
use App\Client\Screens\CentralScreen;
use App\Domain\Access\BranchLimit;
use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Sales\CreditCheck;
use App\Domain\Shared\Format;
use App\Filament\Resources\Sales\SalesOrders\SalesOrderResource;
use App\Filament\Support\ApprovalActions;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\ErpPage;
use App\Models\Sales\Customer;
use App\Models\Sales\SalesOrder;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use RuntimeException;

/**
 * Order Approvals: the sales orders waiting for a decision, with what the
 * approver needs to see — the customer's free credit and whether the goods
 * sit in one warehouse or several. Approving shows the plan first; an order
 * that ships from several warehouses is split and every piece approved
 * together.
 */
class OrderApprovals extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'client.pages.order-approvals';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::OrderApprovals;
    }

    public function table(Table $table): Table
    {
        $seesCredit = HakAkses::canSpecial(HakKhusus::SeeCreditData);

        return $table
            ->query(fn () => BranchLimit::apply(SalesOrder::query(), auth()->user())->with(['customer', 'branch', 'lines.item'])->where('approval_status', SalesOrder::AWAITING))
            ->columns([
                TextColumn::make('customer.name')->label(__('fields.customer'))->weight('medium')->searchable()
                    ->description(fn (SalesOrder $record) => $record->customer?->marketing_user_id === null ? __('no marketing seat') : null),
                TextColumn::make('number')->label(__('Order No.'))->fontFamily('mono')->searchable(),
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                TextColumn::make('branch.name')->label(__('Branch'))->placeholder('—'),
                Rupiah::make('total')->label(__('fields.total')),
                TextColumn::make('credit')->label(__('Free credit'))->alignEnd()->visible($seesCredit)
                    ->state(fn (SalesOrder $record) => $this->freeCredit($record->customer))
                    ->color(fn (SalesOrder $record) => $this->freeCreditAmount($record->customer) !== null && $this->freeCreditAmount($record->customer) < $record->total ? 'danger' : null),
                TextColumn::make('coverage')->label(__('Stock'))->badge()
                    ->state(fn (SalesOrder $record) => $this->coverage($this->plan($record)))
                    ->color(fn (SalesOrder $record) => match (true) {
                        ! $this->plan($record)->coversAll() => 'danger',
                        $this->plan($record)->needsSplit() => 'warning',
                        default => 'success',
                    }),
            ])
            ->defaultSort('trans_date')
            ->recordActions([
                Action::make('open')->label(__('Open'))->icon('heroicon-m-arrow-top-right-on-square')->color('gray')
                    ->url(fn (SalesOrder $record) => SalesOrderResource::getUrl('edit', ['record' => $record])),
                $this->approveAction(),
                ApprovalActions::reject(),
            ])
            ->emptyStateHeading(__('Nothing waits for approval'))
            ->emptyStateDescription(__('Orders entered under the Sales Order Approval rule appear here until someone decides.'));
    }

    private function approveAction(): Action
    {
        return Action::make('approve')
            ->label(__('Approve'))
            ->icon('heroicon-m-check-badge')
            ->color('success')
            ->modalHeading(fn (SalesOrder $record) => __('Approve :number', ['number' => $record->number]))
            ->modalSubmitActionLabel(fn (SalesOrder $record) => $this->plan($record)->needsSplit() ? __('Split and approve') : __('Approve'))
            ->modalContent(fn (SalesOrder $record) => view('client.pages.order-approval-plan', [
                'order' => $record,
                'plan' => $this->plan($record),
                'credit' => HakAkses::canSpecial(HakKhusus::SeeCreditData) ? __('Free credit of :name: :amount', ['name' => $record->customer?->name, 'amount' => $this->freeCredit($record->customer)]) : null,
            ]))
            ->visible(fn (SalesOrder $record) => $this->mayApprove($record))
            ->action(function (SalesOrder $record): void {
                try {
                    $pieces = app(OrderSplitter::class)->execute($record, $this->plan($record), auth()->user());
                    Notification::make()
                        ->title(count($pieces) > 1
                            ? __(':number approved as :count orders: :numbers', ['number' => $record->number, 'count' => count($pieces), 'numbers' => implode(', ', array_map(fn (SalesOrder $p) => $p->number, $pieces))])
                            : __(':number approved', ['number' => $record->number]))
                        ->success()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title(__('Cannot approve'))->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    /** The engine's slot or right, and the customer's seat when it has one. */
    private function mayApprove(SalesOrder $order): bool
    {
        $user = auth()->user();
        if (! app(ApprovalEngine::class)->canApprove($order, $user)) {
            return false;
        }

        return $order->customer === null || $order->customer->marketing_user_id === null || TeamAssigner::holdsApprovalSeat($user, $order->customer);
    }

    /** @var array<int, SplitPlan> */
    private array $plans = [];

    private function plan(SalesOrder $order): SplitPlan
    {
        return $this->plans[$order->id] ??= app(OrderSplitter::class)->plan($order);
    }

    private function coverage(SplitPlan $plan): string
    {
        if (! $plan->coversAll()) {
            return __('short');
        }

        return $plan->needsSplit() ? __(':count warehouses', ['count' => count($plan->shares)]) : __('one warehouse');
    }

    private function freeCreditAmount(?Customer $customer): ?int
    {
        if ($customer === null) {
            return null;
        }
        $holder = $customer->credit_limit_mode === 'parent' && $customer->parentCustomer ? $customer->parentCustomer : $customer;
        if (! $holder->credit_limit_amount_enabled) {
            return null;
        }
        $check = app(CreditCheck::class);

        return (int) $holder->credit_limit_amount - $check->exposure($customer) - $check->openOrders($customer);
    }

    private function freeCredit(?Customer $customer): string
    {
        $amount = $this->freeCreditAmount($customer);

        return $amount === null ? __('no limit') : Format::money($amount);
    }
}
