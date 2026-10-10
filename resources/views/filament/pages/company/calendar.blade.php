<x-filament-panels::page>
    @php
        $events = $this->events();
        $today = today()->toDateString();
        $colours = [
            'receivable' => 'bg-amber-50 text-amber-800',
            'payable' => 'bg-rose-50 text-rose-800',
            'giro' => 'bg-violet-50 text-violet-800',
            'recurring' => 'bg-sky-50 text-sky-800',
            'note' => 'bg-emerald-50 text-emerald-800',
            'period' => 'bg-gray-100 text-gray-700',
        ];
        $legend = [
            'receivable' => __('Invoice due from a customer'),
            'payable' => __('Invoice to pay'),
            'giro' => __('Giro maturing'),
            'recurring' => __('Recurring transaction'),
            'note' => __('Note'),
            'period' => __('Month end'),
        ];
        foreach (\App\Domain\Company\CalendarFeed::extraKinds() as $kind => $extra) {
            $colours[$kind] = $extra['colour'];
            $legend[$kind] = $extra['label'];
        }
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-xl font-semibold text-gray-950">
            {{ $this->heading() }}
        </h2>
        <div class="flex flex-wrap gap-2 text-xs">
            @foreach ($legend as $kind => $label)
                <span class="rounded-full px-2 py-0.5 {{ $colours[$kind] }}">{{ $label }}</span>
            @endforeach
        </div>
    </div>

    @if ($this->calendarView === 'agenda')
        <div class="rounded-xl bg-white ring-1 ring-gray-200 divide-y divide-gray-100">
            @forelse ($events as $date => $dayEvents)
                <div class="flex gap-4 p-3 text-sm">
                    <div class="w-28 shrink-0 font-semibold {{ $date === $today ? 'text-primary-700' : 'text-gray-700' }}">{{ \App\Domain\Shared\Format::date($date) }}</div>
                    <ul class="space-y-1 min-w-0">
                        @foreach ($dayEvents as $event)
                            <li class="rounded px-1.5 py-0.5 text-xs {{ $colours[$event['kind']] ?? 'bg-gray-100 text-gray-700' }}">
                                @if ($event['url'])<a href="{{ $event['url'] }}" class="hover:underline">{{ $event['title'] }}</a>@else{{ $event['title'] }}@endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p class="p-3 text-sm text-gray-500">{{ __('Nothing in the next 30 days.') }}</p>
            @endforelse
        </div>
    @else
    <div class="grid grid-cols-7 gap-px bg-gray-200 rounded-xl overflow-hidden">
        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
            <div class="text-xs uppercase text-gray-500 bg-white p-2 text-center">{{ $weekday }}</div>
        @endforeach

        @foreach ($this->calendarView === 'week' ? [$this->weekDays()] : $this->weeks() as $week)
            @foreach ($week as $day)
                @php
                    $date = $day->toDateString();
                    $inMonth = $this->calendarView === 'week' || $day->month === $this->month;
                    $isToday = $date === $today;
                @endphp
                <div class="bg-white {{ $this->calendarView === 'week' ? 'min-h-64' : 'min-h-24' }} p-2 text-sm {{ $inMonth ? '' : 'text-gray-400' }}">
                    <div class="flex items-center justify-between">
                        <span @class([
                            'inline-flex h-6 w-6 items-center justify-center rounded-full font-bold',
                            'ring-2 ring-primary-600 text-primary-700' => $isToday,
                        ])>{{ $day->day }}</span>
                    </div>
                    @if (! empty($events[$date]))
                        <ul class="mt-1 space-y-1">
                            @foreach ($events[$date] as $event)
                                <li class="truncate rounded px-1.5 py-0.5 text-xs {{ $colours[$event['kind']] ?? 'bg-gray-100 text-gray-700' }}" title="{{ $event['title'] }}">
                                    @if ($event['url'])
                                        <a href="{{ $event['url'] }}" class="hover:underline">{{ $event['title'] }}</a>
                                    @else
                                        {{ $event['title'] }}
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
        @endforeach
    </div>
    @endif
</x-filament-panels::page>
