<?php

declare(strict_types=1);

namespace App\Filament\Shell;

use Illuminate\Support\HtmlString;

/** The shell's small scripts, inlined so the panel needs no extra build step. */
final class Assets
{
    /** @var array<string, string> */
    private static array $cache = [];

    public static function script(string $name): HtmlString
    {
        self::$cache[$name] ??= (string) file_get_contents(resource_path("js/shell/{$name}.js"));

        return new HtmlString('<script>'.self::$cache[$name].'</script>');
    }
}
