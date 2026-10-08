@php($config = $this->shellConfig())
<div class="ae-workspace" wire:ignore x-data="aeWorkspace(@js($config))">
    {{ \App\Filament\Shell\Assets::script('workspace') }}
    @vite('resources/js/workspace-motion.js')
    <div class="ae-tabstrip" role="tablist" aria-label="{{ __('Open screens') }}" x-ref="strip" x-on:keydown="stripKey($event)">
        <template x-for="tab in tabs" :key="tab.id">
            <div
                class="ae-tab"
                role="tab"
                x-bind:tabindex="tab.id === active ? 0 : -1"
                x-bind:data-tab="tab.id"
                x-bind:class="{ 'is-active': tab.id === active, 'is-pinned': tab.pinned }"
                x-bind:aria-selected="tab.id === active ? 'true' : 'false'"
                x-bind:title="tab.title"
                x-on:click="activate(tab.id)"
                x-on:keydown.enter="activate(tab.id)"
                x-on:mouseup.middle="close(tab.id)"
            >
                <span class="ae-tab-title" x-text="tab.title"></span>
                <button type="button" class="ae-tab-close" x-show="! tab.pinned" x-on:click.stop="close(tab.id)" x-bind:aria-label="labels.close">
                    <x-filament::icon icon="heroicon-m-x-mark" class="ae-tab-close-icon" />
                </button>
            </div>
        </template>
    </div>
    <div class="ae-frames">
        <template x-for="tab in tabs" :key="tab.id">
            <iframe class="ae-frame" x-bind:src="tab.src" x-bind:title="tab.title" x-bind:data-tab="tab.id" x-show="tab.id === active"></iframe>
        </template>
    </div>
</div>
