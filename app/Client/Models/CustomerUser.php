<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Domain\Audit\HasAuditReference;
use App\Models\Sales\Customer;
use App\Models\User;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A buyer's login to the portal: a person at one customer, invited by staff.
 * It signs in on the customer guard only, never on the staff panel, and only
 * while both the login and the customer are active.
 */
class CustomerUser extends Authenticatable implements FilamentUser, HasAuditReference
{
    use Notifiable;

    protected $table = 'customer_users';

    protected $fillable = ['customer_id', 'name', 'email', 'password', 'phone', 'locale', 'is_active', 'created_by'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean', 'last_login_at' => 'datetime', 'invited_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'portal' && $this->is_active && (bool) $this->customer?->is_active;
    }

    public function auditReference(): string
    {
        return $this->email.' · '.($this->customer?->name ?? '');
    }
}
