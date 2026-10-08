<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * When a user may work. An administrator always may. An operator may when
 * any of their groups allows the moment: a group with its own time window
 * allows its hours; a group following Preferences allows what the
 * Restrictions tab says (not restricted, restricted for everyone, or its time
 * window). An operator in no group follows Preferences. A window whose end is
 * before its start runs past midnight.
 */
final class AccessWindow
{
    public function __construct(private readonly Preferensi $preferensi) {}

    public function allows(User $user, ?CarbonInterface $at = null): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }
        $time = ($at ?? now())->format('H:i');
        $groups = $user->accessGroups()->get(['access_groups.id', 'restriction_type', 'restricted_from', 'restricted_until']);
        if ($groups->isEmpty()) {
            return $this->preferencesAllow($time);
        }

        return $groups->contains(fn (AccessGroup $group) => $group->restriction_type === 'time_window'
            ? self::within($time, $group->restricted_from, $group->restricted_until)
            : $this->preferencesAllow($time));
    }

    /** The hours shown to a user turned away, e.g. "08:00–17:00", or null when no window applies. */
    public function hoursFor(User $user): ?string
    {
        $windows = $user->accessGroups()->get(['restriction_type', 'restricted_from', 'restricted_until'])
            ->map(fn (AccessGroup $g) => $g->restriction_type === 'time_window' ? [$g->restricted_from, $g->restricted_until] : $this->preferenceWindow())
            ->filter()
            ->map(fn (array $w) => substr((string) $w[0], 0, 5).'–'.substr((string) $w[1], 0, 5))
            ->unique();

        return $windows->isEmpty() ? null : $windows->join(', ');
    }

    private function preferencesAllow(string $time): bool
    {
        return match ($this->preferensi->get(PreferensiKey::AccessRestriction)) {
            'all' => false,
            'time_window' => self::within($time, ...$this->preferenceWindow()),
            default => true,
        };
    }

    /** @return array{0: string, 1: string}|null */
    private function preferenceWindow(): ?array
    {
        return $this->preferensi->get(PreferensiKey::AccessRestriction) === 'time_window'
            ? [(string) $this->preferensi->get(PreferensiKey::AccessFrom), (string) $this->preferensi->get(PreferensiKey::AccessUntil)]
            : null;
    }

    private static function within(string $time, mixed $from, mixed $until): bool
    {
        if (blank($from) || blank($until)) {
            return true;
        }
        $from = substr((string) $from, 0, 5);
        $until = substr((string) $until, 0, 5);

        return $from <= $until
            ? $time >= $from && $time < $until
            : $time >= $from || $time < $until;
    }
}
