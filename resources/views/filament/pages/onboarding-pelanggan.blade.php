{{--
    One card per customer, least-ready first. The steps are derived on every
    load — a step that stopped being true un-ticks itself, which is what
    makes this list safe to trust during the pilot.
--}}
@php
    $baris = $this->baris();
    $total = $this->total();
@endphp

<x-filament-panels::page>

    <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-600 dark:border-white/10 dark:bg-gray-900 dark:text-gray-300">
        Urut dari yang paling belum siap. Setiap langkah dihitung ulang dari keadaan
        sebenarnya — tidak ada centang manual. Pelanggan pilot dinyatakan
        <strong>onboard</strong> ketika sembilan langkahnya hijau; dua langkah terakhir
        (pembeli masuk, order pertama) hanya bisa dihijaukan oleh pembelinya sendiri.
        Panduan lengkapnya: <span class="font-mono">docs/PILOT.md</span>.
    </div>

    @forelse ($baris as $b)
        <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <div class="flex flex-wrap items-center gap-3 border-b border-black/5 px-4 py-3 dark:border-white/10">
                <div class="min-w-0 flex-1">
                    <a href="{{ $this->urlPelanggan($b['company']) }}"
                       class="text-sm font-semibold text-primary-600 hover:underline dark:text-primary-400">
                        {{ $b['company']->nama }}
                    </a>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ $b['company']->kode }} · {{ $b['company']->kota ?: 'kota belum diisi' }}
                    </p>
                </div>

                <span @class([
                    'shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                    'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400'
                        => $b['selesai'] === $total,
                    'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400'
                        => $b['selesai'] < $total,
                ])>
                    {{ $b['selesai'] }}/{{ $total }} langkah
                </span>
            </div>

            <ul class="grid gap-x-6 divide-y divide-black/5 sm:grid-cols-1 dark:divide-white/10">
                @foreach ($b['langkah'] as $langkah)
                    <li class="flex gap-3 px-4 py-2.5">
                        @include('filament.pages.partials.kesiapan-ikon', ['lulus' => $langkah->selesai])

                        <div class="min-w-0 flex-1">
                            <p @class([
                                'text-sm font-medium',
                                'text-gray-900 dark:text-gray-100' => $langkah->selesai,
                                'text-danger-700 dark:text-danger-400' => ! $langkah->selesai,
                            ])>
                                {{ $langkah->judul }}
                            </p>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ $langkah->temuan }}
                            </p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @empty
        <div class="rounded-xl border border-gray-200 bg-white p-6 text-center text-sm text-gray-500 dark:border-white/10 dark:bg-gray-900 dark:text-gray-400">
            Belum ada pelanggan untuk di-onboard di wilayah ini.
        </div>
    @endforelse

</x-filament-panels::page>
