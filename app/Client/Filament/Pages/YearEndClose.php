<?php

declare(strict_types=1);

namespace App\Client\Filament\Pages;

use App\Client\Domain\Books\YearEnd;
use App\Client\Domain\Books\YearEndCheck;
use App\Client\Models\FiscalYearClose;
use App\Client\Screens\CentralScreen;
use App\Domain\Company\FiscalYear;
use App\Filament\Support\ErpPage;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * Year-end Close: the checklist of the fiscal year to close (every month
 * closed, depreciation posted, the semester counts approved, the ledgers
 * reconciled) and the Owner's lock on it. No closing entry is posted.
 */
class YearEndClose extends ErpPage
{
    protected string $view = 'client.pages.year-end-close';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::YearEnd;
    }

    public function yearStart(): CarbonImmutable
    {
        return app(YearEnd::class)->nextToClose();
    }

    public function yearLabel(CarbonImmutable $start): string
    {
        $end = FiscalYear::endOf($start);

        return $start->year === $end->year ? (string) $start->year : $start->format('M Y').' – '.$end->format('M Y');
    }

    /** @return list<YearEndCheck> */
    public function checks(): array
    {
        return app(YearEnd::class)->checklist($this->yearStart());
    }

    public function closedYears(): Collection
    {
        return FiscalYearClose::query()->with('closedBy')->orderByDesc('fiscal_year_start')->get();
    }

    public function closeAction(): Action
    {
        return Action::make('close')->label(fn () => __('Close the year :year', ['year' => $this->yearLabel($this->yearStart())]))->icon(Heroicon::OutlinedLockClosed)->color('danger')
            ->visible(fn () => auth()->user()?->isAdministrator() && collect($this->checks())->every(fn ($c) => $c->passed))
            ->requiresConfirmation()
            ->modalDescription(__('No month of a closed year can be reopened. No closing entry is posted: the balance sheet keeps computing retained earnings.'))
            ->schema([Textarea::make('notes')->label(__('Notes'))->rows(2)])
            ->action(function (array $data): void {
                try {
                    app(YearEnd::class)->close($this->yearStart(), auth()->user(), $data['notes'] ?? null);
                    Notification::make()->title(__('The year is closed'))->success()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title(__('Cannot close the year'))->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    public function reopenAction(): Action
    {
        return Action::make('reopen')->label(__('Reopen'))->icon(Heroicon::OutlinedLockOpen)->color('gray')
            ->visible(fn () => auth()->user()?->isAdministrator())
            ->requiresConfirmation()
            ->schema([Textarea::make('reason')->label(__('Reason'))->rows(2)->required()])
            ->action(function (array $arguments, array $data): void {
                try {
                    app(YearEnd::class)->reopen((string) $arguments['start'], auth()->user(), (string) $data['reason']);
                    Notification::make()->title(__('The year is open again'))->success()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title(__('Cannot reopen'))->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }
}
