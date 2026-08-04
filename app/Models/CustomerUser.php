<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A buyer's login. Belongs to exactly one buyer company.
 *
 * Deliberately not a `User`: staff and buyers authenticate on different guards
 * against different tables, so a buyer session carries no staff identity at
 * all. See canAccessPanel() — this model can only ever reach the buyer portal.
 */
#[Fillable(['company_id', 'name', 'email', 'password', 'telepon', 'is_active', 'created_by'])]
#[Hidden(['password', 'remember_token'])]
class CustomerUser extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Buyers may reach the portal and nothing else.
     *
     * A suspended or pending company must not be able to log in either —
     * account status is decided by staff, and the login screen is where that
     * decision has to bite.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'portal'
            && $this->is_active
            && $this->company?->isActive();
    }
}
