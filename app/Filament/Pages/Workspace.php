<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Shell\Menu;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The panel's home: the icon rail, the tab strip and one live frame per open
 * screen. Every screen is an ordinary panel page shown in a frame, so a
 * half-typed form keeps its state while another tab is in front.
 */
class Workspace extends Page
{
    public const MAX_TABS = 10;

    protected string $view = 'filament.shell.workspace';

    protected static bool $shouldRegisterNavigation = false;

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public function getTitle(): string|Htmlable
    {
        return __('Workspace');
    }

    /** @return array<string, mixed> what the tab strip needs in the browser */
    public function shellConfig(): array
    {
        return [
            'home' => Menu::path(static::getUrl()),
            'dashboard' => Menu::path(Dashboard::getUrl()),
            'brand' => AdminPanelProvider::brandName(),
            'storageKey' => 'ae.tabs.'.auth()->id(),
            'maxTabs' => self::MAX_TABS,
            'labels' => [
                'dashboard' => __('Dashboard'),
                'close' => __('Close tab'),
                'loading' => __('Loading…'),
                'tooMany' => __('Up to :max tabs stay open. Close the oldest one to open this screen?', ['max' => self::MAX_TABS]),
                'unsaved' => __('This tab has unsaved changes. Close it anyway?'),
            ],
        ];
    }
}
