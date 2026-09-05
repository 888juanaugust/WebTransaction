{{--
    Shown only when something has drifted. See the widget class for why there
    is no healthy state to render.

    The <x-filament-widgets::widget> wrapper is what applies $columnSpan to the
    dashboard grid — without it the panel renders half width beside its
    neighbour while the class declares 'full'.
--}}
@php
    $findings = $this->getFindings();
    $shown = array_slice($findings, 0, 6);
    $rest = count($findings) - count($shown);
@endphp

<x-filament-widgets::widget>

<div class="rounded-xl border border-danger-300 bg-danger-50 p-4
            dark:border-danger-500/30 dark:bg-danger-500/10">
    <div class="flex items-start gap-3">
        <x-filament::icon
            icon="heroicon-o-scale"
            class="h-5 w-5 shrink-0 text-danger-600 dark:text-danger-400"
        />

        <div class="min-w-0">
            <h2 class="text-sm font-semibold text-danger-800 dark:text-danger-300">
                {{ count($findings) }} selisih pada pemeriksaan buku
            </h2>

            <p class="mt-1 text-sm text-danger-800/90 dark:text-danger-300/90">
                Ada kolom yang tidak lagi cocok dengan kartu di belakangnya. Sampai ini dijelaskan,
                angka yang dihitung darinya — nilai persediaan, margin, stok yang bisa dikirim —
                belum bisa dipercaya. Jangan tutup buku dulu.
            </p>

            <ul class="mt-3 space-y-1">
                @foreach ($shown as $finding)
                    <li class="text-xs text-danger-900/90 dark:text-danger-200/90">
                        <span class="font-mono">[{{ $finding->wilayah }}/{{ $finding->pemeriksaan }}]</span>
                        <span class="font-medium">{{ $finding->subjek }}</span>
                        — {{ $finding->temuan }}
                    </li>
                @endforeach
            </ul>

            @if ($rest > 0)
                <p class="mt-2 text-xs text-danger-800/80 dark:text-danger-300/80">
                    dan {{ $rest }} lainnya.
                </p>
            @endif

            <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                Daftar lengkapnya: <code>php artisan integritas:periksa</code>.
                Selisih tidak pernah diperbaiki otomatis — yang menambalnya diam-diam akan
                menghilangkan gejalanya dan membiarkan penyebabnya menulis lagi minggu depan.
            </p>
        </div>
    </div>
</div>
</x-filament-widgets::widget>
