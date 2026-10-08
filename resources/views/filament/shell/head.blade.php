{{-- A client's primary colour (config/client.php) drives the theme's accent tokens too, not only Filament's palette. --}}
@php($accent = config('client.theme.colors.primary'))
@if (is_string($accent) && preg_match('/^#[0-9a-fA-F]{6}$/', $accent))
    <style>
        :root:not(.dark) { --ae-accent: {{ $accent }}; --ae-accent-soft: color-mix(in oklab, {{ $accent }} 12%, white); --ae-focus-ring: color-mix(in oklab, {{ $accent }} 22%, white); --ae-info-bg: var(--ae-accent-soft); --ae-info-fg: {{ $accent }}; }
        :root.dark { --ae-accent: color-mix(in oklab, {{ $accent }} 55%, white); --ae-accent-fill: {{ $accent }}; --ae-accent-soft: color-mix(in oklab, {{ $accent }} 25%, #0f1219); --ae-focus-ring: color-mix(in oklab, {{ $accent }} 40%, #0f1219); --ae-info-bg: var(--ae-accent-soft); --ae-info-fg: var(--ae-accent); }
    </style>
@endif
@auth
    <script>window.aeShellConfig = @js([
        'home' => \App\Filament\Shell\Menu::path(\App\Filament\Pages\Workspace::getUrl()),
        'dashboard' => \App\Filament\Shell\Menu::path(\App\Filament\Pages\Dashboard::getUrl()),
        'login' => \App\Filament\Shell\Menu::path((string) filament()->getLoginUrl()),
        'paths' => \App\Filament\Shell\Menu::paths(),
    ]);</script>
    {{ \App\Filament\Shell\Assets::script('bridge') }}
@endauth
