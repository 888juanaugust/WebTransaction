{{--
    One named block of a financial statement, with its subtotal.

    Zero rows are shown rather than hidden. On a chart this small an account
    that disappears reads as an account that does not exist, and "where did
    Utang Belum Ditagih go" is a worse question than a row of nils.
--}}
@php use App\Domain\Money; @endphp

@props(['section'])

<section class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
    <h2 class="border-b border-gray-200 px-4 py-3 text-xs font-semibold uppercase tracking-wide
               text-gray-500 dark:border-white/10 dark:text-gray-400">
        {{ $section->label }}
    </h2>

    @if ($section->isEmpty())
        <p class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">Belum ada saldo.</p>
    @else
        <table class="w-full text-sm">
            <tbody>
                @foreach ($section->lines as $line)
                    <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                        <td class="py-2 pl-4 pr-2">
                            @if ($line->kode())
                                <span class="font-mono text-xs text-gray-400 dark:text-gray-500">
                                    {{ $line->kode() }}
                                </span>
                                <span class="ml-2">{{ $line->label }}</span>
                            @else
                                <span class="italic text-gray-600 dark:text-gray-300">{{ $line->label }}</span>
                            @endif
                        </td>
                        <td @class([
                            'py-2 pl-2 pr-4 text-right font-mono tabular-nums',
                            'text-danger-600 dark:text-danger-400' => $line->amount < 0,
                        ])>
                            {{ Money::format($line->amount) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-gray-300 dark:border-white/20">
                    <th class="py-2 pl-4 pr-2 text-left text-sm font-semibold">
                        Total {{ strtolower($section->label) }}
                    </th>
                    <td class="py-2 pl-2 pr-4 text-right font-mono text-sm font-semibold tabular-nums">
                        {{ Money::format($section->total()) }}
                    </td>
                </tr>
            </tfoot>
        </table>
    @endif
</section>
