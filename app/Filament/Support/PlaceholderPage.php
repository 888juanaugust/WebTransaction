<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * A screen of the standard that exists in the menu but is built in a later
 * release. Says so, instead of a dead link; ScreenRouteTest counts it as not
 * done.
 */
abstract class PlaceholderPage extends ErpPage
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    abstract protected function explanation(): string;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make()
                ->heading(__(':screen is planned for a later release', ['screen' => static::menuKey()->label()]))
                ->description($this->explanation())
                ->icon(Heroicon::OutlinedClock)
                ->color('info'),
            Text::make(__('See docs/standard for what this screen will hold.'))
                ->color('gray')
                ->size('sm'),
        ]);
    }
}
