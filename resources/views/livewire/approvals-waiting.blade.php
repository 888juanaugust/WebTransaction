<div wire:poll.60s.visible class="ae-approvals">
    <x-filament::dropdown placement="bottom-end" width="sm" teleport>
        <x-slot name="trigger">
            <x-filament::icon-button
                icon="heroicon-o-bell"
                color="gray"
                size="lg"
                :label="__('Waiting for your approval')"
                :badge="$count > 0 ? $count : null"
                badge-color="danger"
            />
        </x-slot>

        <div class="ae-approvals-panel">
            <p class="ae-approvals-title">{{ __('Waiting for your approval') }}</p>
            @forelse ($items as $item)
                {{-- A panel link on the workspace opens as a tab (bridge.js). --}}
                <a href="{{ $item['url'] }}" class="ae-approvals-item" x-on:click="close()">
                    <span class="ae-approvals-item-title">{{ $item['title'] }}</span>
                    <span class="ae-approvals-item-detail">{{ $item['detail'] }}</span>
                </a>
            @empty
                <p class="ae-approvals-empty">{{ __('Nothing is waiting for your approval.') }}</p>
            @endforelse
            @if ($count > count($items))
                <p class="ae-approvals-more">{{ __('and :count more', ['count' => $count - count($items)]) }}</p>
            @endif
        </div>
    </x-filament::dropdown>
</div>
