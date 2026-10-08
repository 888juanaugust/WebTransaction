<?php

namespace App\Models\Inventory;

use App\Domain\Access\GuardsUserList;
use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use App\Models\Company\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Warehouse extends Model implements HasAuditReference
{
    use GuardsUserList, RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scrap_warehouse' => 'boolean',
            'is_default' => 'boolean',
            'is_system' => 'boolean',
            'used_all_user' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'warehouse_users');
    }

    /** The warehouses a user may work in: every open one, plus the ones naming them. Administrators see all. */
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

    /** The system warehouse goods sit in between a transfer's send and its receive. */
    public static function inTransit(): self
    {
        return static::query()->firstOrCreate(['name' => 'In Transit'], [
            'description' => 'Goods sent between warehouses, not yet received. Managed by the system.',
            'is_system' => true,
            'used_all_user' => true,
            'is_active' => true,
        ]);
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first() ?? static::query()->where('is_active', true)->orderBy('id')->first();
    }
}
