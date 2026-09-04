{{--
    One request, several deliveries.

    Shown on both panels from the same partial, because the customer on the
    phone and the salesperson answering must be looking at the same list of
    pieces. What differs is only where a piece links to and whether money is
    shown at all — passed in, never decided here.

    @var 'admin'|'portal' $panel
    @var bool $tampilkanUang
--}}
@php
    $order = $getRecord();
    $pecahan = app(\App\Domain\Orders\OrderFamily::class)->pieces($order);
    $rute = ($panel ?? 'admin') === 'portal'
        ? 'filament.portal.resources.pesanan.view'
        : 'filament.admin.resources.orders.view';
@endphp

<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-xs uppercase tracking-wide
                       text-gray-500 dark:border-white/10 dark:text-gray-400">
                <th class="py-2 pr-4 text-left">Nomor</th>
                <th class="py-2 pr-4 text-left">Dikirim dari</th>
                <th class="py-2 pr-4 text-left">Status</th>
                <th class="py-2 pr-4 text-left">Tanggal kirim</th>
                @if ($tampilkanUang ?? false)
                    <th class="py-2 text-right">Nilai</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($pecahan as $piece)
                @php($ini = (int) $piece->id === (int) $order->id)

                <tr @class([
                    'border-b border-gray-100 last:border-0 dark:border-white/5',
                    'bg-gray-50 dark:bg-white/5' => $ini,
                ])>
                    <td class="py-2 pr-4 font-mono text-xs">
                        @if ($ini)
                            {{ $piece->nomor }}
                            <span class="font-sans text-gray-500 dark:text-gray-400">— halaman ini</span>
                        @else
                            <a href="{{ route($rute, ['record' => $piece->id]) }}"
                               class="text-primary-600 hover:underline dark:text-primary-400">
                                {{ $piece->nomor }}
                            </a>
                        @endif
                    </td>

                    <td class="py-2 pr-4">{{ $piece->warehouse?->nama ?? '—' }}</td>

                    <td class="py-2 pr-4">{{ $piece->status->label() }}</td>

                    <td class="py-2 pr-4 whitespace-nowrap">
                        {{ $piece->shipped_at?->format('d/m/Y') ?? '—' }}
                    </td>

                    @if ($tampilkanUang ?? false)
                        <td class="py-2 text-right font-mono whitespace-nowrap">
                            {{-- An order has no total until its lines take their
                                 snapshot at confirmed; Rp 0 would read as free. --}}
                            {{ $piece->confirmed_at === null
                                ? '—'
                                : \App\Domain\Money::format((int) $piece->total_rupiah) }}
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
