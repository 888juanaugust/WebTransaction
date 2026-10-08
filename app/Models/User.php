<?php

namespace App\Models;

use App\Domain\Access\UserDeactivation;
use App\Domain\Audit\Auditor;
use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\Company\Branch;
use App\Models\Settings\AccessGroup;
use App\Models\Settings\UserRightOverride;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SensitiveParameter;

/**
 * A staff account. Administrators pass every access check; operators hold
 * rights through their access groups and are limited to their branches.
 */
#[Fillable(['name', 'email', 'password', 'access_type', 'phone', 'is_active', 'locale'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasAuditReference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, RecordsActivity;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_change_required' => 'boolean',
            'is_active' => 'boolean',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    /** A user is deactivated, never deleted: the guard refuses what an approval still needs. */
    protected static function booted(): void
    {
        // Only an administrator makes an administrator, or touches one; the last one is never demoted.
        static::saving(function (User $user): void {
            $actor = auth()->user();
            $wasAdministrator = $user->exists && $user->getOriginal('access_type') === 'administrator';
            if ($wasAdministrator && $user->isDirty('access_type') && ! $user->isAdministrator()
                && ! User::query()->where('access_type', 'administrator')->where('is_active', true)->whereKeyNot($user->id)->exists()) {
                throw ValidationException::withMessages(['data.access_type' => __(':name is the only active administrator.', ['name' => $user->name])]);
            }
            // The actor's stored access type: when they edit themselves, the change is not yet theirs.
            if (! $actor instanceof User || $actor->getOriginal('access_type') === 'administrator') {
                return; // the console, or an administrator
            }
            if ($user->isAdministrator() && (! $user->exists || $user->isDirty('access_type'))) {
                throw ValidationException::withMessages(['data.access_type' => __('Only an administrator makes another user an administrator.')]);
            }
            if ($wasAdministrator && $user->isDirty()) {
                throw ValidationException::withMessages(['data.name' => __('Only an administrator changes an administrator\'s account.')]);
            }
            // Another user's sign-in (password, email) is an administrator's to change; a user changes their own on the profile page.
            if ($user->exists && (int) $user->getKey() !== (int) $actor->getKey() && $user->isDirty(['password', 'email'])) {
                throw ValidationException::withMessages(['data.password' => __('Only an administrator changes another user\'s password or email.')]);
            }
        });
        static::updating(function (User $user): void {
            if ($user->isDirty('is_active') && ! $user->is_active && (bool) $user->getOriginal('is_active')) {
                $actor = auth()->user();
                UserDeactivation::assertAllowed($user, $actor instanceof User ? $actor : null);
            }
        });
        static::deleting(fn () => throw new RuntimeException(__('Users are never deleted; deactivate them instead.')));
    }

    public function isAdministrator(): bool
    {
        return $this->access_type === 'administrator';
    }

    /** What keeps the user on their profile page before anything else: a password to change, or a second factor to set up. */
    public function profileFirst(): ?string
    {
        if ($this->password_change_required) {
            return 'password';
        }
        if ($this->isAdministrator() && ! $this->hasTwoFactor() && (bool) app(Preferensi::class)->get(PreferensiKey::AdministratorTwoFactor)) {
            return 'two_factor';
        }

        return null;
    }

    /** Only active accounts may open the panel; the access matrix decides what they see inside. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function accessGroups(): BelongsToMany
    {
        return $this->belongsToMany(AccessGroup::class, 'access_group_users');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_users');
    }

    public function rightOverrides(): HasMany
    {
        return $this->hasMany(UserRightOverride::class);
    }

    /** @return array{groups: list<string>, branches: list<string>} the access groups and branches held, by name */
    public function memberships(): array
    {
        return [
            'groups' => $this->accessGroups()->orderBy('name')->pluck('name')->all(),
            'branches' => $this->branches()->orderBy('name')->pluck('name')->all(),
        ];
    }

    /**
     * Logs a change of access groups or branches against what they were (the screen syncs the links, which fires no
     * model event).
     *
     * @param  array{groups: list<string>, branches: list<string>}  $before
     */
    public function logMembershipChange(array $before): void
    {
        $after = $this->memberships();
        if ($after !== $before) {
            Auditor::log('memberships_changed', $this, null, ['before' => $before, 'after' => $after]);
        }
    }

    public function hasTwoFactor(): bool
    {
        return filled($this->app_authentication_secret);
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }

    public function auditReference(): string
    {
        return $this->name;
    }
}
