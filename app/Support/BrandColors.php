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
 * Color::hex('#073185') does not do that: it keeps only the *hue* of the
 * colour you give it and applies a generic lightness curve, which produced a
 * washed-out mid-blue button with dark text and poor contrast. So the ramp is
 * declared explicitly here instead.
 *
 * The company blue is #073185, read off the logo. The company red #DC2626 is
 * already Tailwind red-600, which is why red needs no adjustment at all.
 *
 * Values are oklch to match Filament's own constants — mixing hex and oklch in
 * one ramp makes the interpolated states (hover, ring, disabled) drift.
 */
final class BrandColors
{
    /**
     * Company blue, taken from the logo, anchored so shade 600 is #073185.
     *
     * The mark is a deep navy — L 0.348 against the 0.5 or so Filament expects
     * at 600 — so the shades above it are compressed rather than stretched out
     * to black. That is the right trade: a button in the company's actual blue
     * with a slightly subtle hover beats a bright button that is not the
     * company's blue at all.
     *
     * @var array<int, string>
     */
    public const Blue = [
        50 => 'oklch(0.970 0.014 262.160)',   // #F0F5FF
        100 => 'oklch(0.932 0.032 262.160)',  // #DDE9FF
        200 => 'oklch(0.880 0.060 262.160)',  // #C2D8FF
        300 => 'oklch(0.800 0.098 262.160)',  // #9BBEFD — legible on a dark ground
        400 => 'oklch(0.680 0.145 262.160)',  // #6696F1
        500 => 'oklch(0.545 0.180 262.160)',  // #3169D7
        600 => 'oklch(0.348 0.148 262.160)',  // #073185 — the logo. Buttons, active nav
        700 => 'oklch(0.310 0.152 262.160)',  // #00247B — hover
        800 => 'oklch(0.268 0.126 262.160)',  // #001D62
        900 => 'oklch(0.222 0.098 262.160)',  // #011547
        950 => 'oklch(0.170 0.072 262.160)',  // #010C2E
    ];

    /**
     * FixFlow indigo, anchored so shade 600 is #4F46E5.
     *
     * The design system's primary, and it replaces the logo blue on the two
     * panels — see the note on panel() about what that costs. Declared by
     * hand for the same reason Blue is: `Color::hex()` keeps only the hue and
     * applies a generic lightness curve, which lands the button somewhere
     * near indigo rather than on it.
     *
     * #4F46E5 is oklch(0.511 0.230 276.966), which is close to where Filament
     * expects 600 to sit, so unlike the navy this ramp needs no compression —
     * the shades either side fall where the curve wants them and the hover,
     * ring and disabled states interpolate cleanly.
     *
     * @var array<int, string>
     */
    public const Indigo = [
        50 => 'oklch(0.962 0.018 276.966)',   // #EEF2FF
        100 => 'oklch(0.930 0.034 276.966)',  // #E0E7FF
        200 => 'oklch(0.870 0.065 276.966)',  // #C7D2FE
        300 => 'oklch(0.785 0.110 276.966)',  // #A5B4FC — legible on a dark ground
        400 => 'oklch(0.673 0.170 276.966)',  // #818CF8
        500 => 'oklch(0.585 0.212 276.966)',  // #6366F1
        600 => 'oklch(0.511 0.230 276.966)',  // #4F46E5 — the design system's primary
        700 => 'oklch(0.457 0.220 276.966)',  // #4338CA — hover
        800 => 'oklch(0.398 0.190 276.966)',  // #3730A3
        900 => 'oklch(0.348 0.152 276.966)',  // #312E81
        950 => 'oklch(0.245 0.108 276.966)',  // #1E1B4B
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
     * **Indigo, not the logo blue** (2026-09, FixFlow design system). The two
     * panels now lead with #4F46E5 while the shopfront and every printed
     * document keep #073185, so the staff tools and the public face no longer
     * match. That is a real cost and it is deliberate: the design system was
     * handed over as the reference for the panels, and the panels are what
     * staff look at for eight hours. If the shopfront should follow, that is
     * one line in `resources/css/app.css` and the public-site tokens — but it
     * is a brand decision, not a styling one, so it waits to be asked for.
     *
     * `Blue` stays here rather than being deleted: it is still the logo, the
     * faktur's colour and the shopfront's, and it is what the panels go back
     * to if the answer changes.
     *
     * @return array<string, array<int, string>>
     */
    public static function panel(): array
    {
        return [
            'primary' => self::Indigo,
            'info' => self::Indigo,
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
