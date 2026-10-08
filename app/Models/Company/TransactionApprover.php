<?php

namespace App\Models\Company;

use App\Domain\Audit\RecordsActivity;
use App\Domain\Numbering\TransactionType;
use App\Domain\Shared\Format;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Who must approve which documents, from what amount, under which rule. */
class TransactionApprover extends Model
{
    use RecordsActivity;

    public const ANY_ONE = 'any_one';

    public const AT_LEAST_TWO = 'at_least_two';

    public const IN_ORDER = 'all_in_order';

    public const ANY_ORDER = 'all_any_order';

    /** @return array<string, string> rule value → label */
    public static function rules(): array
    {
        return [
            self::ANY_ONE => __('Any one of the approvers'),
            self::AT_LEAST_TWO => __('At least two approvers'),
            self::IN_ORDER => __('Every approver, in order'),
            self::ANY_ORDER => __('Every approver, in any order'),
        ];
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return ['min_amount' => 'integer', 'is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Whose documents need the approval; nobody listed means everyone's. */
    public function requesters(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'transaction_approver_requesters');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(AccessGroup::class, 'transaction_approver_groups')->withPivot('sort');
    }

    public function approvers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'transaction_approver_users')->withPivot('sort');
    }

    /** "Purchase Orders from Rp 10.000.000", for messages that name the rule. */
    public function label(): string
    {
        return __(':document from :amount', [
            'document' => TransactionType::tryFrom((string) $this->transaction_type)?->getLabel() ?? (string) $this->transaction_type,
            'amount' => Format::money((int) $this->min_amount),
        ]);
    }

    /** Whether this user may approve under this rule: named, or in a named group. */
    public function allowsApprover(User $user): bool
    {
        return $this->approvers()->whereKey($user->id)->exists()
            || $this->groups()->whereHas('users', fn (Builder $q) => $q->whereKey($user->id))->exists();
    }
}
