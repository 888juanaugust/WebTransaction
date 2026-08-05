{{--
    The basket.

    Every figure below is an indication, resolved live from the price list and
    stored nowhere. The binding number is the snapshot each order line takes at
    `confirmed`, which is why the banner says so rather than leaving a buyer to
    read the total as a quote.
--}}
@php
    $estimate = $this->estimate();
    $cart = $this->cart();
    $empty = $cart->isEmpty();
@endphp

<x-filament-panels::page>
    {{ $this->table }}

    @unless ($empty)
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="p-6 space-y-4">
                <div class="flex flex-wrap items-baseline justify-between gap-4">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Perkiraan subtotal</p>
                        <p class="text-lg font-semibold text-gray-950 dark:text-white">
                            {{ \App\Domain\Money::format($estimate->subtotal) }}
                        </p>
                    </div>

                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Perkiraan PPN</p>
                        <p class="text-lg font-semibold text-gray-950 dark:text-white">
                            {{ \App\Domain\Money::format($estimate->ppn) }}
                        </p>
                    </div>

                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Perkiraan total</p>
                        <p class="text-2xl font-bold text-gray-950 dark:text-white">
                            {{ \App\Domain\Money::format($estimate->total) }}
                        </p>
                    </div>

                    @if ($estimate->creditAvailable !== null)
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Sisa limit kredit</p>
                            <p @class([
                                'text-lg font-semibold',
                                'text-danger-600 dark:text-danger-400' => $estimate->exceedsCredit(),
                                'text-gray-950 dark:text-white' => ! $estimate->exceedsCredit(),
                            ])>
                                {{ \App\Domain\Money::format($estimate->creditAvailable) }}
                            </p>
                        </div>
                    @endif

                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Gudang: <span class="font-medium">{{ $cart->warehouse?->nama ?? 'belum dipilih' }}</span>
                    </p>
                </div>

                {{--
                    Red is scarce in this app and means one of three things:
                    short stock, an overdue bill, or a destructive action. Both
                    banners below are the first of those.
                --}}
                @unless ($estimate->fullyPriced)
                    <p class="rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                        Sebagian barang belum punya harga terbit. Anda tetap bisa mengajukan pesanan —
                        tim kami akan menghubungi Anda untuk barang tersebut.
                    </p>
                @endunless

                @if ($estimate->exceedsCredit())
                    <p class="rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                        Perkiraan total melebihi sisa limit kredit Anda. Pesanan tetap bisa diajukan,
                        tetapi tim kami mungkin menghubungi Anda soal pembayaran lebih dulu.
                    </p>
                @endif

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Semua angka di halaman ini masih <strong>perkiraan</strong> berdasarkan daftar harga
                    hari ini. Harga yang mengikat adalah harga pada saat pesanan Anda dikonfirmasi tim kami.
                </p>
            </div>
        </div>
    @endunless
</x-filament-panels::page>
