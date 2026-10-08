<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Models\Settings\AccessGroupRight;
use App\Models\Settings\UserRightOverride;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The access matrix. An administrator passes everything. For an operator a
 * per-user override on (screen, right) wins; otherwise any of their groups
 * granting the right is enough. Rights are loaded once per user per request.
 */
final class HakAkses
{
    /** @var array<int, array<string, array<string, bool>>> user id → menu key → right → allowed */
    private array $rights = [];

    /** @var array<int, list<string>> user id → special rights */
    private array $special = [];

    public function allows(?User $user, ScreenKey $key, Hak $hak): bool
    {
        if ($user === null || ! $user->is_active) {
            return false;
        }
        if ($user->isAdministrator()) {
            return true;
        }

        return $this->rightsOf($user)[$key->value][$hak->value] ?? false;
    }

    public function allowsSpecial(?User $user, HakKhusus $right): bool
    {
        if ($user === null || ! $user->is_active) {
            return false;
        }
        if ($user->isAdministrator()) {
            return true;
        }

        return in_array($right->value, $this->specialOf($user), true);
    }

    /** @return array<string, array<string, bool>> */
    public function rightsOf(User $user): array
    {
        if (isset($this->rights[$user->id])) {
            return $this->rights[$user->id];
        }

        $rights = [];
        $groupIds = DB::table('access_group_users')->where('user_id', $user->id)->pluck('access_group_id');

        foreach (AccessGroupRight::query()->whereIn('access_group_id', $groupIds)->get() as $row) {
            foreach (Hak::cases() as $hak) {
                if ($row->{$hak->column()}) {
                    $rights[$row->menu_key][$hak->value] = true;
                }
            }
        }

        foreach (UserRightOverride::query()->where('user_id', $user->id)->get() as $override) {
            $rights[$override->menu_key][$override->right] = (bool) $override->allowed;
        }

        return $this->rights[$user->id] = $rights;
    }

    /** @return list<string> */
    public function specialOf(User $user): array
    {
        return $this->special[$user->id] ??= DB::table('access_group_special_rights')
            ->join('access_group_users', 'access_group_users.access_group_id', '=', 'access_group_special_rights.access_group_id')
            ->where('access_group_users.user_id', $user->id)
            ->distinct()
            ->pluck('right')
            ->all();
    }

    public function forget(?User $user = null): void
    {
        if ($user === null) {
            $this->rights = [];
            $this->special = [];

            return;
        }
        unset($this->rights[$user->id], $this->special[$user->id]);
    }

    /** Convenience for the current user. */
    public static function can(ScreenKey $key, Hak $hak = Hak::View): bool
    {
        return app(self::class)->allows(auth()->user(), $key, $hak);
    }

    public static function canSpecial(HakKhusus $right): bool
    {
        return app(self::class)->allowsSpecial(auth()->user(), $right);
    }
}
