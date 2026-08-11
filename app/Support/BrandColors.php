<?php

declare(strict_types=1);

namespace App\Support;

use Filament\Support\Colors\Color;

/**
 * The company palette: clean white, blue, red.
 *
 * Filament paints solid buttons and active states with shade **600** of a
 * ramp, so getting the brand colour onto a button means putting it at 600 —
 * not simply naming it somewhere.
 *
 * Color::hex('#1D4ED8') does not do that: it keeps only the *hue* of the
 * colour you give it and applies a generic lightness curve, which produced a
 * washed-out mid-blue button with dark text and poor contrast. So the ramp is
 * declared explicitly here instead.
 *
 * The company blue #1D4ED8 is exactly Tailwind blue-700, so the blue ramp is
 * Tailwind's shifted down one step from 600 onward. The company red #DC2626 is
 * already Tailwind red-600, which is why red needs no adjustment at all.
 *
 * Values are oklch to match Filament's own constants — mixing hex and oklch in
 * one ramp makes the interpolated states (hover, ring, disabled) drift.
 */
final class BrandColors
{
    /**
     * Company blue, anchored so shade 600 is #1D4ED8.
     *
     * @var array<int, string>
     */
    public const Blue = [
        50 => 'oklch(0.97 0.014 254.604)',
        100 => 'oklch(0.932 0.032 255.585)',
        200 => 'oklch(0.882 0.059 254.128)',
        300 => 'oklch(0.809 0.105 251.813)',
        400 => 'oklch(0.707 0.165 254.624)',
        500 => 'oklch(0.623 0.214 259.815)',
        600 => 'oklch(0.488 0.243 264.376)',  // #1D4ED8 — buttons, active nav
        700 => 'oklch(0.424 0.199 265.638)',
        800 => 'oklch(0.379 0.146 265.522)',
        900 => 'oklch(0.282 0.091 267.935)',
        950 => 'oklch(0.22 0.07 268)',
    ];

    /** Company red #DC2626 already sits at 600 in Filament's ramp. */
    public const Red = Color::Red;

    /**
     * The panel palette.
     *
     * Red is not used decoratively anywhere — on this panel red means stock is
     * short, an invoice is overdue, or the action destroys something. That only
     * carries meaning while red stays scarce.
     *
     * @return array<string, array<int, string>>
     */
    public static function panel(): array
    {
        return [
            'primary' => self::Blue,
            'info' => self::Blue,
            'danger' => self::Red,
            'warning' => Color::Amber,
            // Kept green, deliberately: "Lunas" and "Selesai" are the states
            // finance scans a list for, and painting them blue like everything
            // else would erase the only distinction that matters there.
            'success' => Color::Emerald,
            // Cool neutral, so white surfaces read as white rather than beige.
            'gray' => Color::Slate,
        ];
    }
}
