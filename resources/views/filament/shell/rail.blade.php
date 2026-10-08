@php($groups = \App\Filament\Shell\Menu::forUser())
{{ \App\Filament\Shell\Assets::script('rail') }}
<nav
    class="ae-rail"
    x-data="aeRail()"
    x-bind:class="{ 'is-mobile-open': mobile }"
    x-on:click.outside="closeUnlessToggle($event)"
    x-on:keydown.escape.window="close()"
    x-on:ae-menu-toggle.window="toggleMobile()"
    aria-label="{{ __('Modules') }}"
>
    <div class="ae-rail-bar" x-ref="bar" x-on:pointerover="tipFor($event)" x-on:focusin="tipFor($event)" x-on:pointerleave="hideTip()" x-on:focusout="hideTip($event)">
        <button type="button" class="ae-rail-btn" x-on:click="home()" aria-label="{{ __('Dashboard') }}" data-tip="{{ __('Dashboard') }}">
            <x-filament::icon icon="heroicon-o-home" class="ae-rail-icon" />
        </button>
        <span class="ae-rail-sep" aria-hidden="true"></span>
        @foreach ($groups as $group)
            <button
                type="button"
                class="ae-rail-btn"
                data-group-btn="{{ $group['key'] }}"
                x-bind:class="{ 'is-open': openGroup === @js($group['key']) }"
                x-bind:aria-expanded="openGroup === @js($group['key']) ? 'true' : 'false'"
                x-on:click="toggle(@js($group['key']))"
                aria-haspopup="dialog"
                aria-label="{{ $group['label'] }}"
                data-tip="{{ $group['label'] }}"
            >
                <x-filament::icon :icon="$group['icon']" class="ae-rail-icon" />
            </button>
        @endforeach
    </div>
    {{-- The buttons carry their names; this bubble only shows them to the eye. --}}
    <span class="ae-tt" x-ref="tip" aria-hidden="true" data-show="false"><span class="ae-tt-text"></span></span>

    @foreach ($groups as $group)
        <div
            class="ae-flyout"
            data-group="{{ $group['key'] }}"
            x-show="openGroup === @js($group['key'])"
            x-cloak
            x-transition:enter="ae-pop-enter"
            x-transition:enter-start="ae-pop-from"
            x-transition:enter-end="ae-pop-to"
            x-transition:leave="ae-pop-leave"
            x-transition:leave-start="ae-pop-to"
            x-transition:leave-end="ae-pop-out"
            x-on:keydown="moveFocus($event)"
            role="dialog"
            aria-label="{{ $group['label'] }}"
        >
            <h2 class="ae-flyout-title">{{ $group['label'] }}</h2>
            <div class="ae-tiles">
                @foreach ($group['tiles'] as $tile)
                    <a href="{{ $tile['url'] }}" class="ae-tile ae-tile-{{ $tile['kind'] }}" data-key="{{ $tile['key'] }}" x-on:click.prevent="openTile($el)">
                        <x-filament::icon :icon="$tile['icon']" class="ae-tile-icon" />
                        <span class="ae-tile-label">{{ $tile['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach
</nav>
