<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The company mark.
 *
 * One place that answers "is there a logo file, and where is it", because the
 * answer is needed by three surfaces — the public header, the admin panel and
 * the buyer portal — and the wrong answer on any of them is a broken image on
 * the company's own shopfront.
 *
 * `config('perusahaan.logo')` holds a path relative to public/. It is null
 * until the file is actually committed, and every caller falls back to the
 * wordmark, so an unset logo looks deliberate rather than broken.
 */
final class Branding
{
    /**
     * The logo's URL, or null when there is no logo to show.
     *
     * The existence check matters: a path configured for a file that is not
     * deployed would render a broken image, and the surfaces that use this are
     * the first thing a customer sees.
     */
    public static function logoUrl(): ?string
    {
        $path = config('perusahaan.logo');

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = ltrim(trim($path), '/');

        return file_exists(public_path($path)) ? asset($path) : null;
    }

    public static function hasLogo(): bool
    {
        return self::logoUrl() !== null;
    }

    /** What to write when there is no mark to draw. */
    public static function wordmark(): string
    {
        return (string) config('perusahaan.nama_singkat', config('perusahaan.nama'));
    }
}
