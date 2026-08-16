{{-- One headline figure with an optional note beneath it. --}}
@php use App\Domain\Money; @endphp

@props(['label', 'amount', 'note' => null, 'emphasis' => false])

<div @class([
    'rounded-xl border bg-white p-4 dark:bg-gray-900',
    'border-primary-200 dark:border-primary-500/30' => $emphasis,
    'border-gray-200 dark:border-white/10' => ! $emphasis,
])>
    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
        {{ $label }}
    </p>
    <p @class([
        'mt-1 font-mono text-xl font-semibold tabular-nums',
        'text-danger-600 dark:text-danger-400' => $amount < 0,
    ])>{{ Money::format($amount) }}</p>

    @if ($note)
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $note }}</p>
    @endif
</div>
