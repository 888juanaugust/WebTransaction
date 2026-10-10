<?php

declare(strict_types=1);

namespace App\Client\Filament\Pages;

use App\Client\Domain\Stock\OpnameScheduler;
use App\Client\Domain\Warehouse\WarehouseScope;
use App\Client\Screens\CentralScreen;
use App\Domain\Inventory\OpnameApprover;
use App\Domain\Shared\Format;
use App\Filament\Resources\Inventory\StockOpnameResults\StockOpnameResultResource;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\ErpPage;
use App\Models\Inventory\StockOpnameResult;
use App\Models\Inventory\Warehouse;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Count Sheets: the scheduled counts — the day's sheet of what went out,
 * the semester's full sheet — for the gudang to count (its own warehouse)
 * and for Purchasing to approve, which posts the variance as the base
 * does. A manual count still lives on the base's Stock Opname screens.
 */
class CountSheets extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'client.pages.count-sheets';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::CountSheets;
    }

    public function table(Table $table): Table
    {
        $bound = WarehouseScope::of(auth()->user());
        $approver = app(OpnameApprover::class);

        return $table
            ->query(fn () => StockOpnameResult::query()->with(['order.warehouse', 'countedBy'])->withCount('lines')
                ->whereHas('order', fn (Builder $q) => $q->whereIn('kind', [OpnameScheduler::DAILY, OpnameScheduler::SEMESTER])
                    ->when($bound, fn (Builder $w) => $w->where('warehouse_id', $bound->id))
                    ->when(! $bound, fn (Builder $w) => $w->whereIn('warehouse_id', Warehouse::query()->visibleTo(auth()->user())->select('id')))))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->fontFamily('mono')->searchable()->weight('medium'),
                TextColumn::make('order.kind')->label(__('Kind'))->badge()->formatStateUsing(fn ($state) => $state === OpnameScheduler::SEMESTER ? __('Half-year') : __('Daily'))
                    ->color(fn ($state) => $state === OpnameScheduler::SEMESTER ? 'warning' : 'info'),
                TextColumn::make('order.warehouse.name')->label(__('Warehouse')),
                Tanggal::make('trans_date')->label(__('fields.trans_date'))->sortable(),
                TextColumn::make('lines_count')->label(__('Items'))->alignEnd(),
                TextColumn::make('counted_at')->label(__('Counted'))->state(fn (StockOpnameResult $r) => $r->counted_at ? Format::date($r->counted_at).' · '.($r->countedBy?->name ?? '') : null)->placeholder(__('not yet')),
                TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => $state === StockOpnameResult::APPROVED ? __('Approved') : __('Open'))
                    ->color(fn ($state) => $state === StockOpnameResult::APPROVED ? 'success' : 'gray'),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options([StockOpnameResult::DRAFT => __('Open'), StockOpnameResult::APPROVED => __('Approved')])->default(StockOpnameResult::DRAFT),
            ])
            ->recordActions([
                Action::make('count')->label(__('Count'))->icon(Heroicon::OutlinedPencilSquare)->color('primary')
                    ->visible(fn (StockOpnameResult $r) => ! $r->isApproved() && static::canUpdate())
                    ->modalHeading(fn (StockOpnameResult $r) => __('Count :number', ['number' => $r->number]))
                    ->fillForm(fn (StockOpnameResult $r) => ['lines' => $r->lines()->with(['item', 'unit'])->orderBy('sort')->get()->map(fn ($l) => [
                        'line_id' => $l->id, 'item' => ($l->item?->number ?? '').' — '.($l->item?->name ?? ''), 'unit' => $l->unit?->name ?? '', 'counted' => (string) $l->base_quantity,
                    ])->all()])
                    ->schema([
                        Repeater::make('lines')->label(__('Lines'))->schema([
                            Hidden::make('line_id'),
                            TextInput::make('item')->label(__('Item'))->disabled()->dehydrated(false)->columnSpan(2),
                            TextInput::make('unit')->label(__('Unit'))->disabled()->dehydrated(false),
                            TextInput::make('counted')->label(__('Counted (base units)'))->numeric()->minValue(0)->required(),
                        ])->columns(4)->addable(false)->deletable(false)->reorderable(false),
                    ])
                    ->action(function (StockOpnameResult $r, array $data): void {
                        $counts = [];
                        foreach ((array) ($data['lines'] ?? []) as $row) {
                            $counts[(int) ($row['line_id'] ?? 0)] = (string) ($row['counted'] ?? 0);
                        }
                        try {
                            app(OpnameScheduler::class)->count($r, $counts, auth()->user());
                            Notification::make()->title(__(':number counted', ['number' => $r->number]))->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title(__('Cannot count'))->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
                Action::make('approve')->label(__('Approve'))->icon(Heroicon::OutlinedCheckBadge)->color('success')
                    ->visible(fn (StockOpnameResult $r) => $r->counted_at !== null && $approver->canApprove($r, auth()->user()))
                    ->requiresConfirmation()->modalHeading(fn (StockOpnameResult $r) => __('Approve :number?', ['number' => $r->number]))
                    ->modalDescription(__('The difference between the count and the system quantity posts as an inventory adjustment.'))
                    ->action(function (StockOpnameResult $r) use ($approver): void {
                        try {
                            $adjustment = $approver->approve($r, auth()->user());
                            Notification::make()->title($adjustment ? __('Approved; variance :number posted', ['number' => $adjustment->number]) : __('Approved; no variance'))->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title(__('Cannot approve'))->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
                Action::make('open')->label(__('Open'))->icon(Heroicon::OutlinedArrowTopRightOnSquare)->visible(fn () => ! WarehouseScope::of(auth()->user()))
                    ->url(fn (StockOpnameResult $r) => StockOpnameResultResource::getUrl('edit', ['record' => $r]))->openUrlInNewTab(),
            ])
            ->emptyStateHeading(__('No count sheet is waiting'));
    }
}
