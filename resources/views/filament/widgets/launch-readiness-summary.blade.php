{{--
    Shown only until the list is clear. See the widget class for why there is
    no finished state to render.

    The <x-filament-widgets::widget> wrapper is not decoration: it is the only
    thing that applies $columnSpan to the dashboard grid. A view whose root is
    its own div gets `grid-column: auto` and lands beside its neighbour at half
    width, however emphatically the class declares 'full'.
--}}
@php
    use App\Domain\Launch\LaunchCheckKind;
    use App\Filament\Pages\KesiapanPeluncuran;

    $outstanding = $this->outstanding();
    $top = $this->topOutstanding();
    $more = $this->moreCount();
@endphp

<x-filament-widgets::widget>
<div class="rounded-xl border border-warning-300 bg-warning-50 p-4
            dark:border-warning-500/30 dark:bg-warning-500/10">
    <div class="flex items-start gap-3">
        <x-filament::icon
            icon="heroicon-o-rocket-launch"
            class="h-5 w-5 shrink-0 text-warning-600 dark:text-warning-400"
        />

        <div class="min-w-0 flex-1">
            <h2 class="text-sm font-semibold text-warning-800 dark:text-warning-300">
                {{ $outstanding }} hal belum selesai sebelum sistem ini dipakai sungguhan
            </h2>

            <p class="mt-1 text-sm text-warning-800/90 dark:text-warning-300/90">
                Selama masih ada yang merah, panel ini belum siap dipakai pelanggan.
                Kotak ini hilang sendiri begitu semuanya beres.
            </p>

            <ul class="mt-3 space-y-1.5">
                @foreach ($top as $check)
                    <li class="flex items-baseline gap-2 text-sm">
                        {{-- Which kind it is, said plainly. One is a fact about
                             the system now; the other is paperwork nobody has
                             recorded yet, and they need different actions. --}}
                        <span @class([
                            'shrink-0 rounded px-1.5 py-0.5 text-xs font-medium',
                            'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-300'
                                => $check->jenis === LaunchCheckKind::Otomatis,
                            'bg-gray-200 text-gray-700 dark:bg-white/10 dark:text-gray-300'
                                => $check->jenis === LaunchCheckKind::Pernyataan,
                        ])>
                            {{ $check->jenis === LaunchCheckKind::Otomatis ? 'sistem' : 'perlu pernyataan' }}
                        </span>

                        <span class="text-warning-900 dark:text-warning-200">
                            {{ $check->judul }}
                            @if ($check->temuan)
                                <span class="text-warning-800/70 dark:text-warning-300/70">
                                    — {{ $check->temuan }}
                                </span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>

            <div class="mt-3 flex flex-wrap items-center gap-3">
                <x-filament::button
                    tag="a"
                    :href="KesiapanPeluncuran::getUrl()"
                    size="sm"
                    color="warning"
                >
                    Lihat daftar lengkap
                </x-filament::button>

                @if ($more > 0)
                    <span class="text-xs text-warning-800/80 dark:text-warning-300/80">
                        dan {{ $more }} lagi
                    </span>
                @endif
            </div>
        </div>
    </div>
</div>
</x-filament-widgets::widget>
