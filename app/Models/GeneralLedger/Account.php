<?php

namespace App\Models\GeneralLedger;

use App\Domain\Access\GuardsUserList;
use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use App\Domain\Shared\Enums\AccountType;
use App\Models\Company\Bank;
use App\Models\Company\Currency;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An account of the chart of accounts. */
class Account extends Model implements HasAuditReference
{
    use GuardsUserList, RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'is_sub' => 'boolean',
            'is_system' => 'boolean',
            'used_all_user' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'account_users');
    }

    /** "[1101] Cash" as the standard prints account lookups. */
    public function displayName(): string
    {
        return "[{$this->no}] {$this->name}";
    }

    public function auditReference(): string
    {
        return $this->displayName();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, AccountType ...$types): Builder
    {
        return $query->whereIn('account_type', array_map(fn (AccountType $t) => $t->value, $types));
    }

    /** @return array<int, string> id → "[no] name", for selects */
    public static function options(AccountType ...$types): array
    {
        return static::query()->active()
            ->when($types !== [], fn (Builder $q) => $q->ofType(...$types))
            ->orderBy('no')
            ->get()
            ->mapWithKeys(fn (self $a) => [$a->id => $a->displayName()])
            ->all();
    }
}
