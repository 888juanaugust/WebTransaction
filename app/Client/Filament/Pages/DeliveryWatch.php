<?php

declare(strict_types=1);

namespace App\Client\Filament\Pages;

use App\Client\Domain\Orders\DeliveryWatch as Watch;
use App\Client\Models\OrderDeliveryNotice;
use App\Client\Screens\CentralScreen;
use App\Domain\Access\BranchLimit;
use App\Domain\Shared\Format;
use App\Filament\Resources\Sales\SalesOrders\SalesOrderResource;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\ErpPage;
use App\Models\Sales\SalesOrder;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Delivery Watch: the orders accepted thirty days ago or more and what
 * became of their goods — delivered, partly, or still held — and when the
 * administrators were told. The worklist behind the daily digest.
 */
class DeliveryWatch extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'client.pages.delivery-watch';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::DeliveryWatch;
    }

    public function table(Table $table): Table
    {
        $watch = app(Watch::class);

        return $table
            ->query(fn () => BranchLimit::apply($watch->overdue(), auth()->user()))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->fontFamily('mono')->searchable()->sortable()->weight('medium'),
                TextColumn::make('customer.name')->label(__('fields.customer'))->searchable(),
                Tanggal::make('approved_at')->label(__('Accepted on'))->sortable(),
                TextColumn::make('age')->label(__('Age (days)'))->state(fn (SalesOrder $r) => (string) $watch->summary($r)['days'])->alignEnd(),
                TextColumn::make('state')->label(__('Goods'))->badge()
                    ->state(fn (SalesOrder $r) => $watch->summary($r)['state'])
                    ->formatStateUsing(fn (string $state) => Watch::stateLabel($state))
                    ->color(fn (string $state) => match ($state) {
                        'delivered' => 'success', 'partial' => 'warning', default => 'danger'
                    }),
                TextColumn::make('delivered')->label(__('Delivered / ordered'))->alignEnd()
                    ->state(fn (SalesOrder $r) => Format::quantity($watch->summary($r)['delivered']).' / '.Format::quantity($watch->summary($r)['ordered'])),
                TextColumn::make('held')->label(__('Held in'))->state(fn (SalesOrder $r) => implode(', ', $watch->summary($r)['warehouses']))->placeholder('—'),
                TextColumn::make('reported')->label(__('Reported on'))
                    ->state(fn (SalesOrder $r) => ($n = OrderDeliveryNotice::query()->where('sales_order_id', $r->id)->first()) && $n->sent_at ? Format::date($n->sent_at) : null)->placeholder(__('not yet')),
            ])
            ->defaultSort('approved_at')
            ->filters([
                SelectFilter::make('status')->label(__('Goods'))->options(['pending' => __('not yet delivered'), 'partial' => __('partly delivered'), 'processed' => __('delivered')]),
            ])
            ->recordActions([
                Action::make('open')->label(__('Open order'))->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (SalesOrder $r) => SalesOrderResource::getUrl('edit', ['record' => $r]))->openUrlInNewTab(),
            ])
            ->emptyStateHeading(__('No order accepted :days days ago is waiting', ['days' => $watch->days()]));
    }
}
