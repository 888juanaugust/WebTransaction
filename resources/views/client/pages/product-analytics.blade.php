<x-filament-panels::page>
    {{ $this->form }}

    @php($top = $this->topTen())
    @if ($top !== [])
        @php($max = max(array_map(fn ($t) => $t['value'], $top)) ?: 1)
        <section class="ae-bars" aria-label="{{ __('Top ten') }}">
            <h3 class="ae-bars-title">{{ __('Top ten') }}</h3>
            <ol class="ae-bars-list">
                @foreach ($top as $t)
                    <li class="ae-bars-row">
                        <span class="ae-bars-label">{{ $t['label'] }}</span>
                        <span class="ae-bars-track"><span class="ae-bars-fill" style="width: {{ max(2, round($t['value'] / $max * 100)) }}%"></span></span>
                        <span class="ae-bars-value">{{ \App\Domain\Shared\Format::quantity((string) $t['value']) }}</span>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
