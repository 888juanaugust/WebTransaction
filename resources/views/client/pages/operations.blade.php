@php
    use App\Client\Console\BackupCommand;
    use App\Client\Models\BackupRun;
    use App\Domain\Shared\Format;

    $checks = $this->checks();
    $backup = $this->backupHealth();
    $findings = $this->findings();
    $colors = ['success' => 'text-success-600 dark:text-success-400', 'warning' => 'text-warning-600 dark:text-warning-400', 'danger' => 'text-danger-600 dark:text-danger-400'];
@endphp
<x-filament-panels::page>
    <div class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <div class="border-b border-black/5 px-4 py-3 dark:border-white/10">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Health') }}</h2>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Measured now. The hourly sweep mails the administrators once per critical incident; :state.', ['state' => $this->alertThrottled() ? __('an alert went out and is throttled') : __('no alert is pending')]) }}</p>
            </div>
            <ul class="divide-y divide-black/5 dark:divide-white/10">
                @foreach ($checks as $check)
                    <li class="flex items-start gap-3 px-4 py-2.5">
                        <span class="mt-0.5 w-20 shrink-0 text-xs font-semibold uppercase {{ $colors[$check->status->color()] }}">{{ $check->status->label() }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $check->title }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $check->finding }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <div class="border-b border-black/5 px-4 py-3 dark:border-white/10">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Backups') }}</h2>
                <p class="mt-0.5 text-xs font-medium {{ $colors[$backup->color()] }}">{{ $backup->message() }}</p>
            </div>
            <table class="w-full text-sm">
                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr><th class="px-4 py-2 text-start">{{ __('Started') }}</th><th class="px-4 py-2 text-start">{{ __('Status') }}</th><th class="px-4 py-2 text-end">{{ __('Size') }}</th><th class="px-4 py-2 text-start">{{ __('Where') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-black/5 dark:divide-white/10">
                    @forelse ($this->runs() as $run)
                        <tr>
                            <td class="px-4 py-2">{{ Format::dateTime($run->started_at) }}</td>
                            <td class="px-4 py-2">
                                <span @class(['font-medium', $colors['success'] => $run->status === BackupRun::VERIFIED, $colors['danger'] => $run->status === BackupRun::FAILED, $colors['warning'] => $run->status === BackupRun::RUNNING])>{{ __(ucfirst($run->status)) }}</span>
                                @if ($run->error)<span class="block text-xs text-gray-500">{{ $run->error }}</span>@endif
                            </td>
                            <td class="px-4 py-2 text-end ae-money">{{ BackupCommand::bytes($run->totalBytes()) }}</td>
                            <td class="px-4 py-2 text-xs text-gray-500">{{ $run->disk }}{{ $run->offsite ? '' : ' · '.__('on this machine') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-3 text-gray-500">{{ __('No backup has run yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <div class="border-b border-black/5 px-4 py-3 dark:border-white/10">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Ledger integrity') }}</h2>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Every cache against its ledger, read now. Nothing here is repaired from the screen: find the document behind a difference first.') }}</p>
        </div>
        @if ($findings === [])
            <p class="px-4 py-3 text-sm {{ $colors['success'] }}">{{ __('Every cache agrees with its ledger.') }}</p>
        @else
            <ul class="divide-y divide-black/5 font-mono text-xs dark:divide-white/10">
                @foreach ($findings as $finding)
                    <li class="px-4 py-2 {{ $colors['danger'] }}">{{ $finding->line() }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</x-filament-panels::page>
