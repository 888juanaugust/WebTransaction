{{-- Only failing checks appear; a healthy system renders nothing at all. --}}
<x-filament-widgets::widget>
    <div class="rounded-xl border border-danger-300 bg-danger-50 p-4
                dark:border-danger-500/30 dark:bg-danger-500/10">
        <h2 class="text-sm font-semibold text-danger-800 dark:text-danger-300">
            Kesehatan sistem
        </h2>

        <ul class="mt-2 space-y-1 text-sm text-danger-800 dark:text-danger-300">
            @foreach ($this->getFailing() as $check)
                <li>
                    <span class="font-semibold">
                        {{ $check->status === \App\Domain\Ops\OpsStatus::Gawat ? 'GAWAT' : 'Waspada' }}
                        — {{ $check->judul }}:
                    </span>
                    {{ $check->temuan }}
                </li>
            @endforeach
        </ul>

        <p class="mt-2 text-xs text-danger-700/80 dark:text-danger-300/70">
            Rincian dari terminal: <code>php artisan ops:check</code>
        </p>
    </div>
</x-filament-widgets::widget>
