<?php

declare(strict_types=1);

namespace App\Support;

use Filament\Support\Colors\Color;

/**
 * The company palette.
 *
 *   #2B3467  navy    structure — nav, headings, primary buttons
 *   #EB455F  coral   accent and danger
 *   #BAD7E9  powder  surfaces, badges, quiet fills
 *   #FCFFE7  cream   the page itself
 *
 * Filament paints solid buttons and active states with shade **600** of a ramp,
 * so getting a brand colour onto a button means putting it at 600 — not merely
 * naming it somewhere. Color::hex() will not do it: it keeps only the *hue* and
 * applies a generic lightness curve, which is how the old blue ended up a
 * washed-out mid-tone with poor contrast. The ramps are declared explicitly.
 *
 * Two of the four are not button colours, and forcing them to 600 would be a
 * mistake in opposite directions:
 *
 *   · Navy is dark for a 600 (L 0.345 against Filament's usual ~0.5), so the
 *     shades above it are compressed rather than stretched to black.
 *   · Powder blue is a *tint*, L 0.864. At 600 it would be a pale button with
 *     white text on it. It sits at 200, where it is used, and the ramp carries
 *     on to a solid blue at 600 for the rare info button.
 *   · Cream is the page, not a ramp at all — see theme.css and app.css.
 *
 * Values are oklch to match Filament's own constants; mixing hex and oklch in
 * one ramp makes the interpolated states (hover, ring, disabled) drift.
 */
final class BrandColors
{
    /** Company navy, anchored so shade 600 is exactly #2B3467. */
    public const Navy = [
        50 => 'oklch(0.965 0.012 273.324)',
        100 => 'oklch(0.930 0.025 273.324)',
        200 => 'oklch(0.870 0.042 273.324)',
        300 => 'oklch(0.780 0.062 273.324)',
        400 => 'oklch(0.620 0.085 273.324)',
        500 => 'oklch(0.470 0.092 273.324)',
        600 => 'oklch(0.345 0.089 273.324)',  // #2B3467 — buttons, active nav
        700 => 'oklch(0.300 0.078 273.324)',
        800 => 'oklch(0.255 0.064 273.324)',
        900 => 'oklch(0.210 0.050 273.324)',
        950 => 'oklch(0.160 0.038 273.324)',
    ];

    /** Company coral, anchored so shade 600 is exactly #EB455F. */
    public const Coral = [
        50 => 'oklch(0.971 0.015 16.300)',
        100 => 'oklch(0.936 0.033 16.300)',
        200 => 'oklch(0.888 0.060 16.300)',
        300 => 'oklch(0.820 0.105 16.300)',
        400 => 'oklch(0.730 0.155 16.300)',
        500 => 'oklch(0.685 0.180 16.300)',
        600 => 'oklch(0.636 0.201 16.300)',  // #EB455F
        700 => 'oklch(0.570 0.190 16.300)',
        800 => 'oklch(0.490 0.160 16.300)',
        900 => 'oklch(0.420 0.130 16.300)',
        950 => 'oklch(0.290 0.090 16.300)',
    ];

    /**
     * Powder blue, anchored so shade **200** is exactly #BAD7E9.
     *
     * The tint is the point of this colour — it is what quiet panels, badges
     * and table stripes are filled with. The darker end exists so text and the
     * occasional info button have something legible on it.
     */
    public const Powder = [
        50 => 'oklch(0.975 0.010 235.188)',
        100 => 'oklch(0.935 0.022 235.188)',
        200 => 'oklch(0.864 0.040 235.188)',  // #BAD7E9
        300 => 'oklch(0.800 0.065 235.188)',
        400 => 'oklch(0.720 0.105 235.188)',
        500 => 'oklch(0.640 0.140 235.188)',
        600 => 'oklch(0.560 0.155 235.188)',
        700 => 'oklch(0.480 0.140 235.188)',
        800 => 'oklch(0.400 0.115 235.188)',
        900 => 'oklch(0.330 0.090 235.188)',
        950 => 'oklch(0.240 0.065 235.188)',
    ];

    /** The page tint. Not a ramp — one value, used as a surface. */
    public const Cream = '#FCFFE7';

    public const NavyHex = '#2B3467';

    public const CoralHex = '#EB455F';

    public const PowderHex = '#BAD7E9';

    /**
     * The panel palette.
     *
     * Coral is the brand accent *and* the danger colour, which puts pressure on
     * a rule worth keeping: in the panels, coral means stock is short, an
     * invoice is overdue, or the action destroys something. Decoration is navy,
     * powder and cream — there is enough colour in those three that coral does
     * not need to be spent on ornament. It stays scarce here so it keeps
     * meaning something; the public site, which has no danger states, uses it
     * freely.
     *
     * @return array<string, array<int, string>>
     */
    public static function panel(): array
    {
        return [
            'primary' => self::Navy,
            'info' => self::Powder,
            'danger' => self::Coral,
            'warning' => Color::Amber,
            // Kept green, deliberately: "Lunas" and "Selesai" are the states
            // finance scans a list for, and painting them navy like everything
            // else would erase the only distinction that matters there.
            'success' => Color::Emerald,
            // Slate, very slightly cool, so grey text and borders sit under the
            // navy rather than fighting it.
            'gray' => Color::Slate,
        ];
    }
}
