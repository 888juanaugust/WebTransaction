<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Filament\Modul;
use BackedEnum;

/**
 * A screen's key: what rights, menus, routes and the standard's pages are
 * keyed by. The template's screens are MenuKey; a client adds its own as a
 * string-backed enum implementing this, listed in config/client.php under
 * "screens". The value is stored (access rights, user overrides), so it never
 * changes once a screen is in use; a client's values start with "client__".
 */
interface ScreenKey extends BackedEnum
{
    /** The module group (sidebar group) the screen sits in. */
    public function modul(): Modul;

    public function label(): string;

    /** Position in its group. */
    public function sort(): int;

    /** What the screen is for: its tile colour. */
    public function kind(): ScreenKind;

    /** Whether the screen is built (false for a screen deliberately left out). */
    public function isReplicated(): bool;

    /** The URL slug of its resource or page. */
    public function slug(): string;
}
