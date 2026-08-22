{{--
    Shown only when something is wrong. See the widget class for why there is
    no healthy state to render.

    The <x-filament-widgets::widget> wrapper is what applies $columnSpan to the
    dashboard grid. Without it this rendered at `grid-column: auto` — half
    width, beside its neighbour — while the class declared 'full'.
--}}
@php
    use App\Domain\Backup\BackupHealth;

    $health = $this->getHealth();
    $state = $health->state();
    $danger = in_array($state, [BackupHealth::NEVER, BackupHealth::FAILING], true);
@endphp

<x-filament-widgets::widget>

<div @class([
    'rounded-xl border p-4',
    'border-danger-300 bg-danger-50 dark:border-danger-500/30 dark:bg-danger-500/10' => $danger,
    'border-warning-300 bg-warning-50 dark:border-warning-500/30 dark:bg-warning-500/10' => ! $danger,
])>
    <div class="flex items-start gap-3">
        <x-filament::icon
            :icon="$danger ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-shield-exclamation'"
            @class([
                'h-5 w-5 shrink-0',
                'text-danger-600 dark:text-danger-400' => $danger,
                'text-warning-600 dark:text-warning-400' => ! $danger,
            ])
        />

        <div>
            <h2 @class([
                'text-sm font-semibold',
                'text-danger-800 dark:text-danger-300' => $danger,
                'text-warning-800 dark:text-warning-300' => ! $danger,
            ])>
                {{ match ($state) {
                    BackupHealth::NEVER => 'Belum ada backup',
                    BackupHealth::FAILING => 'Backup gagal',
                    BackupHealth::STALE => 'Backup sudah lama tidak jalan',
                    default => 'Backup belum keluar dari mesin ini',
                } }}
            </h2>

            <p @class([
                'mt-1 text-sm',
                'text-danger-800/90 dark:text-danger-300/90' => $danger,
                'text-warning-800/90 dark:text-warning-300/90' => ! $danger,
            ])>
                {{ $health->message() }}
            </p>

            <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                Panduan lengkap ada di <code>docs/BACKUP.md</code>.
            </p>
        </div>
    </div>
</div>
</x-filament-widgets::widget>
