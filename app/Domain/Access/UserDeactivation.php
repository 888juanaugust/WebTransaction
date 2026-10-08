<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Approval\ApprovalEngine;
use App\Models\Company\TransactionApprover;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Users are never deleted: they are deactivated, which ends their access at
 * once and keeps their name on everything they did. Deactivating is refused
 * for yourself, for the last active administrator, and for anyone an
 * approval still needs: a named approver on a rule, the only active member
 * of an approving group, or the person a waiting document cannot be
 * approved without. Nothing is then approved by fewer people than its rule
 * names.
 */
final class UserDeactivation
{
    /** @return list<string> why the user cannot be deactivated now; empty when they can */
    public static function reasons(User $user, ?User $actor): array
    {
        $reasons = [];
        if ($actor !== null && $actor->is($user)) {
            $reasons[] = __('You cannot deactivate your own account.');
        }
        if ($user->isAdministrator() && ! User::query()->where('access_type', 'administrator')->where('is_active', true)->whereKeyNot($user->id)->exists()) {
            $reasons[] = __(':name is the only active administrator.', ['name' => $user->name]);
        }

        $named = TransactionApprover::query()->whereHas('approvers', fn (Builder $q) => $q->whereKey($user->id))->orderBy('transaction_type')->get();
        if ($named->isNotEmpty()) {
            $reasons[] = __(':name approves under :rules: name someone else on those approval rules first.', ['name' => $user->name, 'rules' => self::labels($named)]);
        }

        $lastIn = AccessGroup::query()
            ->whereHas('users', fn (Builder $q) => $q->whereKey($user->id))
            ->whereDoesntHave('users', fn (Builder $q) => $q->where('users.is_active', true)->whereKeyNot($user->id))
            ->get();
        foreach ($lastIn as $group) {
            $rules = TransactionApprover::query()->whereHas('groups', fn (Builder $q) => $q->whereKey($group->id))->orderBy('transaction_type')->get();
            if ($rules->isNotEmpty()) {
                $reasons[] = __(':name is the only active member of :group, which approves under :rules: add someone to the group first.', ['name' => $user->name, 'group' => $group->name, 'rules' => self::labels($rules)]);
            }
        }

        $waiting = app(ApprovalEngine::class)->waitingOn($user);
        if ($waiting !== []) {
            $reasons[] = __(':documents cannot be approved without :name: they decide first, or edit the document so it asks again under the current rules.', [
                'documents' => collect($waiting)->map(fn (Model $document) => (string) $document->getAttribute('number'))->join(', '),
                'name' => $user->name,
            ]);
        }

        return $reasons;
    }

    public static function assertAllowed(User $user, ?User $actor): void
    {
        $reasons = self::reasons($user, $actor);
        if ($reasons !== []) {
            throw new RuntimeException(implode(' ', $reasons));
        }
    }

    /** @param  iterable<TransactionApprover>  $rules */
    private static function labels(iterable $rules): string
    {
        return collect($rules)->map(fn (TransactionApprover $rule) => $rule->label())->join('; ');
    }
}
