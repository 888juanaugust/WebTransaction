<?php

declare(strict_types=1);

namespace App\Filament\Resources\GeneralLedger\AccountingPeriods\Pages;

use App\Domain\Audit\Auditor;
use App\Domain\Posting\PeriodLock;
use App\Domain\Shared\Format;
use App\Filament\Resources\GeneralLedger\AccountingPeriods\AccountingPeriodResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageAccountingPeriods extends ManageRecords
{
    protected static string $resource = AccountingPeriodResource::class;

    protected function getHeaderActions(): array
    {
        $lock = app(PeriodLock::class);

        return [
            Action::make('close')
                ->label(__('Close a month'))
                ->icon('heroicon-m-lock-closed')
                ->visible(fn () => AccountingPeriodResource::canCreate())
                ->schema([
                    Select::make('month')->label(__('Month'))
                        ->options(Format::months())
                        ->default(fn () => $lock->nextToClose()->month)->required()->native(false),
                    Select::make('year')->label(__('Year'))
                        ->options(collect(range((int) date('Y') - 6, (int) date('Y')))->mapWithKeys(fn (int $y) => [$y => (string) $y])->all())
                        ->default(fn () => $lock->nextToClose()->year)->required()->native(false),
                    Textarea::make('notes')->label(__('fields.memo'))->rows(2),
                ])
                ->modalDescription(fn () => __('Months close in order. The next month to close is :month. Nothing dated in a closed month can be added, changed or deleted.', ['month' => $lock->nextToClose()->translatedFormat('F Y')]))
                ->action(function (array $data) use ($lock): void {
                    try {
                        $period = $lock->close((int) $data['year'], (int) $data['month'], auth()->id(), $data['notes'] ?? null);
                        Auditor::log('period_closed', $period, $period->label());
                        Notification::make()->title(__(':period closed', ['period' => $period->label()]))->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title(__('Cannot close'))->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
