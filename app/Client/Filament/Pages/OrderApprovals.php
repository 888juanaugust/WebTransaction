<?php

declare(strict_types=1);

namespace App\Client\Filament\Pages;

use App\Client\Screens\CentralScreen;
use App\Domain\Access\BranchLimit;
use App\Filament\Resources\Sales\SalesOrders\SalesOrderResource;
use App\Filament\Support\ApprovalActions;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\ErpPage;
use App\Models\Sales\SalesOrder;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/** Order Approvals: the sales orders waiting for approval, with what the approver needs to decide. */
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
        return $table
            ->query(fn () => BranchLimit::apply(SalesOrder::query(), auth()->user())->with(['customer', 'branch'])->where('approval_status', SalesOrder::AWAITING))
            ->columns([
                TextColumn::make('customer.name')->label(__('fields.customer'))->weight('medium')->searchable(),
                TextColumn::make('number')->label(__('Order No.'))->fontFamily('mono')->searchable(),
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                TextColumn::make('branch.name')->label(__('Branch'))->placeholder('—'),
                Rupiah::make('total')->label(__('fields.total')),
            ])
            ->defaultSort('trans_date')
            ->recordActions([
                Action::make('open')->label(__('Open'))->icon('heroicon-m-arrow-top-right-on-square')->color('gray')
                    ->url(fn (SalesOrder $record) => SalesOrderResource::getUrl('edit', ['record' => $record])),
                ...ApprovalActions::make(),
            ])
            ->emptyStateHeading(__('Nothing waits for approval'))
            ->emptyStateDescription(__('Orders entered under the Sales Order Approval rule appear here until someone decides.'));
    }
}
