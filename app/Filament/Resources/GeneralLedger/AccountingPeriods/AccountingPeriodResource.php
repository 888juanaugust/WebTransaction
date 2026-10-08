<?php

declare(strict_types=1);

namespace App\Filament\Resources\GeneralLedger\AccountingPeriods;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Access\MenuKey;
use App\Domain\Audit\Auditor;
use App\Domain\Posting\PeriodLock;
use App\Domain\Shared\Format;
use App\Filament\Resources\GeneralLedger\AccountingPeriods\Pages\ManageAccountingPeriods;
use App\Filament\Support\ErpResource;
use App\Models\GeneralLedger\AccountingPeriod;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Month-end Process: close months in order; the last closed one can be reopened with the special right. */
class AccountingPeriodResource extends ErpResource
{
    protected static ?string $model = AccountingPeriod::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static ?string $modelLabel = 'Closed month';

    protected static ?string $recordTitleAttribute = 'year';

    public static function menuKey(): MenuKey
    {
        return MenuKey::MonthEndProcess;
    }

    /** Closing is "create", reopening is "delete"; there is no editing of a period. */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $ability = $action instanceof UnitEnum ? ($action->value ?? $action->name) : $action;
        if (in_array($ability, ['update', 'deleteAny', 'forceDelete', 'forceDeleteAny'], true)) {
            return Response::deny();
        }

        return parent::getAuthorizationResponse($action, $record);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('closedBy'))
            ->columns([
                TextColumn::make('label')->label(__('Month'))->state(fn (AccountingPeriod $r) => $r->label())->weight('medium'),
                TextColumn::make('status')->label(__('fields.status'))->badge()
                    ->formatStateUsing(fn (string $state) => Format::code($state, 'period'))
                    ->color(fn (string $state) => $state === AccountingPeriod::CLOSED ? 'success' : 'gray'),
                TextColumn::make('closed_at')->label(__('Closed on'))->formatStateUsing(fn ($state) => Format::dateTime($state))->placeholder('—'),
                TextColumn::make('closedBy.name')->label(__('By'))->placeholder('—'),
                TextColumn::make('notes')->label(__('fields.memo'))->limit(60)->placeholder('—'),
            ])
            ->defaultSort('year', 'desc')
            ->filters([
                SelectFilter::make('year')->label(__('Year'))->options(fn () => AccountingPeriod::query()->distinct()->orderByDesc('year')->pluck('year', 'year')->all()),
                SelectFilter::make('month')->label(__('Month'))->options(Format::months()),
            ])
            ->recordActions([
                Action::make('reopen')
                    ->label(__('Reopen'))
                    ->icon('heroicon-m-lock-open')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(__('Documents dated in this month can be changed again until it is closed once more.'))
                    ->visible(fn (AccountingPeriod $record) => $record->status === AccountingPeriod::CLOSED
                        && app(PeriodLock::class)->lastClosed()?->is($record)
                        && app(HakAkses::class)->allowsSpecial(auth()->user(), HakKhusus::OpenClosedPeriod))
                    ->action(function (AccountingPeriod $record): void {
                        try {
                            app(PeriodLock::class)->reopen($record->year, $record->month);
                            Auditor::log('period_reopened', $record, $record->label());
                            Notification::make()->title(__(':period reopened', ['period' => $record->label()]))->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title(__('Cannot reopen'))->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAccountingPeriods::route('/')];
    }
}
