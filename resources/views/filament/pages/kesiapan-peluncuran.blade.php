{{--
    What is still in the way of going live.

    Two kinds of row, kept visibly apart. A checked item is evidence and
    carries no button; an attested item is somebody's word and carries their
    name. Making them look like the same green tick is the thing this screen
    exists to avoid.
--}}
@php
    use App\Domain\Launch\LaunchCheckKind;

    $checks = $this->checks();
    $outstanding = $this->outstanding();

    $otomatis = array_values(array_filter($checks, fn ($c) => $c->jenis === LaunchCheckKind::Otomatis));
    $pernyataan = array_values(array_filter($checks, fn ($c) => $c->jenis === LaunchCheckKind::Pernyataan));
@endphp

<x-filament-panels::page>

    <div @class([
        'rounded-xl border p-4 text-sm',
        'border-success-300 bg-success-50 text-success-800 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-300'
            => $outstanding === 0,
        'border-warning-300 bg-warning-50 text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300'
            => $outstanding > 0,
    ])>
        @if ($outstanding === 0)
            <strong>Semua item sudah selesai.</strong>
            Yang diperiksa sistem diperiksa ulang setiap halaman ini dibuka, jadi kalau
            nanti ada yang berubah, angkanya naik lagi dengan sendirinya.
        @else
            <strong>{{ $outstanding }} item belum selesai.</strong>
            Yang diperiksa sistem tidak bisa dicentang manual — perbaiki penyebabnya dan
            barisnya hijau sendiri.
        @endif
    </div>

    {{-- Checked. No buttons anywhere in this section, on purpose. --}}
    <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <div class="border-b border-black/5 px-4 py-3 dark:border-white/10">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                Diperiksa sistem
            </h2>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                Dihitung ulang setiap halaman ini dibuka. Tidak ada tombol untuk
                menandainya selesai, karena tidak ada yang perlu dipercaya.
            </p>
        </div>

        <ul class="divide-y divide-black/5 dark:divide-white/10">
            @foreach ($otomatis as $check)
                <li class="flex gap-3 px-4 py-3">
                    @include('filament.pages.partials.kesiapan-ikon', ['lulus' => $check->lulus])

                    <div class="min-w-0 flex-1">
                        <p @class([
                            'text-sm font-medium',
                            'text-gray-900 dark:text-gray-100' => $check->lulus,
                            'text-danger-700 dark:text-danger-400' => ! $check->lulus,
                        ])>
                            {{ $check->judul }}
                        </p>

                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ $check->keterangan }}
                        </p>

                        @if ($check->temuan)
                            <p @class([
                                'mt-1 text-xs font-medium',
                                'text-danger-700 dark:text-danger-400' => ! $check->lulus,
                                'text-gray-600 dark:text-gray-300' => $check->lulus,
                            ])>
                                {{ $check->temuan }}
                            </p>
                        @endif

                        @if (! $check->lulus && $check->tindakan)
                            <p class="mt-1 font-mono text-xs text-gray-500 dark:text-gray-400">
                                {{ $check->tindakan }}
                            </p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </div>

    {{-- Attested. Weaker evidence, and the section says so. --}}
    <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <div class="border-b border-black/5 px-4 py-3 dark:border-white/10">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                Dinyatakan orang
            </h2>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                Sistem tidak bisa melihat satu pun dari ini. Yang tercatat adalah nama dan
                tanggal — bukti yang lebih lemah daripada pemeriksaan, dan sengaja
                dipisahkan supaya kelihatan begitu.
            </p>
        </div>

        <ul class="divide-y divide-black/5 dark:divide-white/10">
            @foreach ($pernyataan as $check)
                <li class="flex flex-wrap items-start gap-3 px-4 py-3">
                    @include('filament.pages.partials.kesiapan-ikon', ['lulus' => $check->lulus])

                    <div class="min-w-0 flex-1">
                        <p @class([
                            'text-sm font-medium',
                            'text-gray-900 dark:text-gray-100' => $check->lulus,
                            'text-danger-700 dark:text-danger-400' => ! $check->lulus,
                        ])>
                            {{ $check->judul }}
                        </p>

                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ $check->keterangan }}
                        </p>

                        @if ($check->temuan)
                            <p class="mt-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                                {{ $check->temuan }}
                            </p>
                        @endif
                    </div>

                    <div class="shrink-0">
                        @if ($check->lulus)
                            {{ ($this->cabutAction)(['kunci' => $check->kunci]) }}
                        @else
                            {{ ($this->nyatakanAction)(['kunci' => $check->kunci]) }}
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </div>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        Daftar yang sama ada di <span class="font-mono">docs/DEPLOY.md</span>, lengkap
        dengan langkah server yang tidak ada di layar ini.
    </p>

</x-filament-panels::page>
