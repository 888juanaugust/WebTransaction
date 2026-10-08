<?php

declare(strict_types=1);

namespace App\Filament\Pages\Company;

use App\Domain\Access\MenuKey;
use App\Domain\Company\CalendarFeed;
use App\Domain\Shared\Format;
use App\Filament\Support\ErpPage;
use App\Models\Company\CalendarEvent;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;

/**
 * Calendar: invoices falling due, giros maturing, recurring transactions
 * scheduled, month ends and the company's own notes, by month, by week, or
 * as an agenda of the next 30 days.
 */
class Calendar extends ErpPage
{
    protected string $view = 'filament.pages.company.calendar';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    #[Locked] // moved only by the page's own buttons
    public int $year;

    #[Locked] // moved only by the page's own buttons
    public int $month;

    /** month | week | agenda */
    #[Locked] // moved only by the page's own buttons
    public string $calendarView = 'month';

    /** The Monday of the week the week view shows. */
    #[Locked] // moved only by the page's own buttons
    public string $weekStart = '';

    public static function menuKey(): MenuKey
    {
        return MenuKey::Calendar;
    }

    public function mount(): void
    {
        $year = request()->integer('year');
        $month = request()->integer('month');
        $this->year = $year >= 1900 && $year <= 2200 ? $year : today()->year;
        $this->month = $month >= 1 && $month <= 12 ? $month : today()->month;
        $this->calendarView = in_array(request()->query('view'), ['month', 'week', 'agenda'], true) ? (string) request()->query('view') : 'month';
        $this->weekStart = CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    }

    public function show(string $view): void
    {
        $this->calendarView = in_array($view, ['month', 'week', 'agenda'], true) ? $view : 'month';
    }

    public function previousMonth(): void
    {
        $this->calendarView === 'week'
            ? $this->moveTo(CarbonImmutable::parse($this->weekStart)->subWeek())
            : $this->moveTo($this->firstOfMonth()->subMonth());
    }

    public function nextMonth(): void
    {
        $this->calendarView === 'week'
            ? $this->moveTo(CarbonImmutable::parse($this->weekStart)->addWeek())
            : $this->moveTo($this->firstOfMonth()->addMonth());
    }

    public function today(): void
    {
        $this->moveTo(CarbonImmutable::today());
    }

    private function moveTo(CarbonImmutable $date): void
    {
        $this->year = $date->year;
        $this->month = $date->month;
        $this->weekStart = $date->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    }

    public function firstOfMonth(): CarbonImmutable
    {
        return CarbonImmutable::create($this->year, $this->month, 1);
    }

    /** @return array<string, list<array{kind: string, title: string, url: ?string}>> date → events */
    public function events(): array
    {
        return match ($this->calendarView) {
            'week' => CalendarFeed::between(CarbonImmutable::parse($this->weekStart), CarbonImmutable::parse($this->weekStart)->addDays(6)),
            'agenda' => CalendarFeed::between(CarbonImmutable::today(), CarbonImmutable::today()->addDays(30)),
            default => CalendarFeed::month($this->year, $this->month),
        };
    }

    /** @return list<CarbonImmutable> the week view's seven days */
    public function weekDays(): array
    {
        $monday = CarbonImmutable::parse($this->weekStart);

        return array_map(fn (int $i) => $monday->addDays($i), range(0, 6));
    }

    public function heading(): string
    {
        return match ($this->calendarView) {
            'week' => Format::date($this->weekStart).' – '.Format::date(CarbonImmutable::parse($this->weekStart)->addDays(6)),
            'agenda' => __('The next 30 days'),
            default => $this->firstOfMonth()->translatedFormat('F Y'),
        };
    }

    /** @return list<list<CarbonImmutable>> the month's weeks, Monday to Sunday, padded with the neighbouring months' days */
    public function weeks(): array
    {
        $first = $this->firstOfMonth();
        $day = $first->startOfWeek(CarbonImmutable::MONDAY);
        $last = $first->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);
        $weeks = [];
        while ($day->lte($last)) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $week[] = $day;
                $day = $day->addDay();
            }
            $weeks[] = $week;
        }

        return $weeks;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addNote')
                ->label(__('New note'))
                ->icon('heroicon-m-plus')
                ->color('primary')
                ->visible(fn (): bool => static::canUpdate())
                ->schema([
                    TextInput::make('title')->label(__('Title'))->required()->maxLength(200),
                    DatePicker::make('starts_on')->label(__('Date'))->required()->native(false)->default(today()),
                    Textarea::make('notes')->label(__('Notes'))->rows(3),
                ])
                ->action(function (array $data): void {
                    CalendarEvent::query()->create([
                        'title' => $data['title'],
                        'starts_on' => $data['starts_on'],
                        'notes' => $data['notes'] ?? null,
                        'created_by' => auth()->id(),
                    ]);
                    $this->moveTo(CarbonImmutable::parse($data['starts_on']));
                    Notification::make()->title(__('Note added'))->success()->send();
                }),
            Action::make('month')->label(__('Month'))->color(fn () => $this->calendarView === 'month' ? 'primary' : 'gray')->outlined(fn () => $this->calendarView !== 'month')->action(fn () => $this->show('month')),
            Action::make('week')->label(__('Week'))->color(fn () => $this->calendarView === 'week' ? 'primary' : 'gray')->outlined(fn () => $this->calendarView !== 'week')->action(fn () => $this->show('week')),
            Action::make('agenda')->label(__('Agenda'))->color(fn () => $this->calendarView === 'agenda' ? 'primary' : 'gray')->outlined(fn () => $this->calendarView !== 'agenda')->action(fn () => $this->show('agenda')),
            Action::make('today')->label(__('Today'))->color('gray')->action(fn () => $this->today()),
            Action::make('previous')->label(fn () => $this->calendarView === 'week' ? __('Previous week') : __('Previous month'))->icon('heroicon-m-chevron-left')->color('gray')->iconButton()
                ->visible(fn () => $this->calendarView !== 'agenda')->action(fn () => $this->previousMonth()),
            Action::make('next')->label(fn () => $this->calendarView === 'week' ? __('Next week') : __('Next month'))->icon('heroicon-m-chevron-right')->color('gray')->iconButton()
                ->visible(fn () => $this->calendarView !== 'agenda')->action(fn () => $this->nextMonth()),
        ];
    }
}
