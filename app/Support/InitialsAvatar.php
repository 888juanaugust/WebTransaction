<?php

declare(strict_types=1);

namespace App\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * An avatar that never leaves the building.
 *
 * Filament's default provider builds the avatar as a URL on ui-avatars.com
 * with the account's name in the query string — so every page a staff
 * member opened had their browser send their full name to a third party,
 * and the privacy notice this company publishes says every image on the
 * site is served from its own server. Nobody noticed because the request
 * was silent and, on the box this was measured on, blocked: the topbar
 * showed a broken image with "Avat…" as its alt text, and had since the
 * panel was first stood up.
 *
 * This draws the same thing — two initials on a coloured disc — as an SVG
 * and hands it back as a data URI. No request, no host, nothing to leak.
 * Indigo, to match the panel; white initials, because that is what the
 * design system's primary button does with text and an avatar is a small
 * one of those.
 */
final class InitialsAvatar implements AvatarProvider
{
    public function get(Model $record): string
    {
        $initials = $this->initials(Filament::getNameForDefaultAvatar($record));

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" rx="32" fill="#4f46e5"/>'
            .'<text x="32" y="32" dy=".36em" text-anchor="middle" '
            .'font-family="Cairo, ui-sans-serif, system-ui, sans-serif" font-size="26" font-weight="600" fill="#ffffff">'
            .htmlspecialchars($initials, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            .'</text></svg>';

        return 'data:image/svg+xml;utf8,'.rawurlencode($svg);
    }

    /**
     * The first letter of the first two words — "Budi Santoso" → "BS",
     * "Ibu Sari" → "IS", one word → its first letter, nothing → "?".
     *
     * Leading punctuation is skipped, as Filament's own provider does, so
     * a "[SYSTEM] Admin" convention does not become "[A".
     */
    private function initials(string $name): string
    {
        $letters = collect(preg_split('/\s+/u', trim($name)) ?: [])
            ->map(fn (string $word): string => preg_replace('/^[^\p{L}\p{N}]+/u', '', $word) ?? '')
            ->filter()
            ->take(2)
            ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('');

        return $letters !== '' ? $letters : '?';
    }
}
