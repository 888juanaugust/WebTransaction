@php use App\Domain\Shared\Format; @endphp
<div class="space-y-4 text-sm">
    <p>
        @if ($plan->needsSplit())
            {{ __(':number ships from :count warehouses. Approving splits it: the first share stays on this order, each other warehouse gets an order of its own in its branch, and every piece is approved, credit-checked and reserved together, or none is.', ['number' => $order->number, 'count' => count($plan->shares)]) }}
        @elseif (! $plan->coversAll())
            {{ __('No warehouse can cover :number in full. Approve what stock allows only after the shortfall is resolved.', ['number' => $order->number]) }}
        @else
            {{ __(':number ships from one warehouse. Approving reserves its goods there.', ['number' => $order->number]) }}
        @endif
    </p>

    @foreach ($plan->shares as $i => $share)
        <div class="rounded-xl border border-gray-200 dark:border-white/10">
            <div class="flex items-center justify-between border-b border-gray-200 px-3 py-2 font-medium dark:border-white/10">
                <span>{{ $share['warehouse']->name }}</span>
                <span class="text-xs font-normal text-gray-500">
                    {{ $share['warehouse']->branch?->name ?? '—' }}
                    · {{ $i === 0 ? __('stays on this order') : __('becomes its own order') }}
                </span>
            </div>
            <table class="w-full">
                <tbody>
                    @foreach ($share['lines'] as $row)
                        <tr class="border-t border-gray-100 first:border-t-0 dark:border-white/5">
                            <td class="px-3 py-1.5">{{ $row['line']->item->name }}</td>
                            <td class="px-3 py-1.5 font-mono text-gray-500">{{ $row['line']->item->number }}</td>
                            <td class="px-3 py-1.5 text-end ae-money">{{ Format::quantity($row['quantity']) }} {{ $row['line']->item->unit1?->name }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach

    @if (! $plan->coversAll())
        <div class="rounded-xl border border-danger-300 bg-danger-50 px-3 py-2 text-danger-700 dark:border-danger-500/40 dark:bg-danger-500/10 dark:text-danger-300">
            <div class="font-medium">{{ __('Short everywhere') }}</div>
            <ul class="mt-1 space-y-0.5">
                @foreach ($plan->shortfalls as $row)
                    <li>{{ $row['line']->item->name }} — {{ Format::quantity($row['quantity']) }} {{ $row['line']->item->unit1?->name }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($credit !== null)
        <p class="text-gray-500">{{ $credit }}</p>
    @endif
</div>
