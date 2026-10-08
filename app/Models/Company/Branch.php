<?php

namespace App\Models\Company;

use App\Domain\Access\GuardsUserList;
use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Branch extends Model implements HasAuditReference
{
    use GuardsUserList, RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'used_all_user' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'branch_users');
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first() ?? static::query()->orderBy('id')->first();
    }

    /** The branches a user may work in: every branch open to all users, and the ones they are assigned to. */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->isAdministrator()) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('used_all_user', true)
            ->orWhereHas('users', fn (Builder $u) => $u->whereKey($user->id)));
    }

    /**
     * The branch ids a user is limited to, or null when every branch is open
     * to them (an administrator, or an operator no branch is closed to).
     *
     * @return list<int>|null
     */
    public static function limitsOf(?User $user): ?array
    {
        if ($user === null || $user->isAdministrator()) {
            return null;
        }
        $visible = static::query()->visibleTo($user)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        return count($visible) === static::query()->count() ? null : $visible;
    }

    /** The branch a new record of the user starts in: the default branch when they may use it, else their first. */
    public static function defaultFor(?User $user): ?self
    {
        $limits = static::limitsOf($user);
        $default = static::default();
        if ($limits === null || ($default !== null && in_array($default->id, $limits, true))) {
            return $default;
        }

        return $limits === [] ? null : static::query()->find($limits[0]);
    }
}
