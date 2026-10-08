<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Audit\Auditor;
use App\Domain\Numbering\TransactionType;
use App\Domain\Pengaturan\BusinessRule;
use App\Models\Approval\ApprovalDecision;
use App\Models\Approval\ApprovalRequest;
use App\Models\Company\TransactionApprover;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One approval engine for every document type a module registers.
 *
 * Every save of a registered document keeps one current request: approved on
 * the spot when nothing governs it, otherwise waiting. The governing rule is
 * the covering active rule (type, branch, amount, whose entry) with the
 * highest "from amount"; with none, the type may still wait for anyone with
 * the approve right. Conditions: any one approver; at least two different
 * people; every approver slot in any order; every slot in order. A group slot
 * is filled by any one of its members. The person who entered a document
 * never approves it while segregation of duties is on.
 *
 * A waiting or rejected document is still posted (the books follow what was
 * entered), but cannot be pulled into another document, printed or settled.
 * An edit that changes the amount, the branch or the lines starts a new
 * request; until someone decides, a request follows rule changes.
 */
final class ApprovalEngine
{
    /** @var array<class-string, ApprovalType> */
    private array $types = [];

    public function register(ApprovalType $type): void
    {
        $this->types[$type->model] = $type;
    }

    public function typeOf(Model|string $document): ?ApprovalType
    {
        return $this->types[is_string($document) ? $document : $document::class] ?? null;
    }

    /** @return list<TransactionType> the transaction types approval rules may name */
    public function transactionTypes(): array
    {
        return collect($this->types)->map(fn (ApprovalType $t) => $t->transactionType)->unique(fn (TransactionType $t) => $t->value)->values()->all();
    }

    /** @return Collection<int, TransactionApprover> the active rules covering the document, the governing one first */
    public function rulesFor(Model $document): Collection
    {
        $type = $this->typeOf($document);
        if ($type === null || ! $type->isEnabled() || ! app(ModuleRegistry::class)->isEnabled('approval')) {
            return collect();
        }
        $branchId = $document->getAttribute('branch_id');
        $creator = $document->getAttribute('created_by');

        return TransactionApprover::query()
            ->active()
            ->where('transaction_type', $type->transactionType->value)
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($q) => $q->orWhere('branch_id', $branchId)))
            ->where('min_amount', '<=', self::amountOf($document))
            ->with('requesters')
            ->orderByDesc('min_amount')->orderBy('id')
            ->get()
            ->filter(fn (TransactionApprover $rule) => $rule->requesters->isEmpty() || ($creator !== null && $rule->requesters->contains('id', $creator)))
            ->values();
    }

    /** The request of the document's current version, or null (not registered, or saved before approvals existed). */
    public function current(Model $document): ?ApprovalRequest
    {
        if ($this->typeOf($document) === null || ! $document->exists) {
            return null;
        }

        return ApprovalRequest::query()->active()
            ->where('approvable_type', $document->getMorphClass())
            ->where('approvable_id', $document->getKey())
            ->first();
    }

    public function isApproved(Model $document): bool
    {
        return $this->status($document) === ApprovalRequest::APPROVED;
    }

    /** 'approved', 'awaiting' or 'rejected', for a badge. */
    public function status(Model $document): string
    {
        $request = $this->current($document);
        if ($request !== null) {
            return $request->status;
        }
        $type = $this->typeOf($document);

        return $type === null || $type->approvedWithoutRequest($document) ? ApprovalRequest::APPROVED : ApprovalRequest::AWAITING;
    }

    /** Refuses an action a waiting or rejected document must not take. */
    public function assertApproved(Model $document, string $refusal): void
    {
        if (! $this->isApproved($document)) {
            throw new RuntimeException(__(':number :refusal', ['number' => (string) $document->getAttribute('number'), 'refusal' => $refusal]));
        }
    }

    /** After a document is saved: keep its request when nothing that matters changed, else supersede it and open the next. */
    public function sync(Model $document): ?ApprovalRequest
    {
        $type = $this->typeOf($document);
        if ($type === null) {
            return null;
        }
        $fingerprint = self::fingerprint($document);
        $current = $this->current($document);
        if ($current !== null && $current->fingerprint === $fingerprint) {
            return $current;
        }

        return DB::transaction(function () use ($document, $type, $current, $fingerprint): ApprovalRequest {
            $current?->forceFill(['superseded_at' => now()])->save();
            $request = $this->open($document, $type, $fingerprint, changed: $current !== null);
            $this->notify($type, $document, $request, null);

            return $request;
        });
    }

    /** When a document goes: its request goes with it (kept as history). */
    public function forget(Model $document): void
    {
        $this->current($document)?->forceFill(['superseded_at' => now()])->save();
    }

    public function canApprove(Model $document, ?User $user): bool
    {
        if ($user === null || ! $user->is_active) {
            return false;
        }
        $request = $this->pending($document);

        return $request !== null && ! $this->hasDecided($request, $user) && $this->slotFor($request, $user) !== null
            && ! $this->segregated($request, $user);
    }

    /** Under segregation of duties, whoever entered or last changed the document does not approve it. */
    private function segregated(ApprovalRequest $request, User $user): bool
    {
        return BusinessRule::SegregationOfDuties->isOn()
            && in_array((int) $user->id, array_map('intval', array_filter([$request->requested_by, $request->edited_by])), true);
    }

    /** Whether the user is one of the request's approvers, at any position: they may reject. */
    public function canReject(Model $document, ?User $user): bool
    {
        if ($user === null || ! $user->is_active) {
            return false;
        }
        $request = $this->pending($document);

        return $request !== null && $this->isEligible($request, $user) && ! $this->segregated($request, $user);
    }

    /** Records an approval; true when it completes the request. */
    public function approve(Model $document, User $approver): bool
    {
        $type = $this->typeOf($document) ?? throw new RuntimeException(__(':number needs no approval.', ['number' => $document->getAttribute('number')]));
        $request = $this->pending($document);
        if ($request === null) {
            throw new RuntimeException(__(':number is already approved.', ['number' => $document->getAttribute('number')]));
        }
        if ($this->hasDecided($request, $approver)) {
            throw new RuntimeException(__('You have already approved :number.', ['number' => $document->getAttribute('number')]));
        }
        $slot = $this->slotFor($request, $approver);
        if ($slot === null) {
            throw new RuntimeException($request->rule === TransactionApprover::IN_ORDER && $this->isEligible($request, $approver)
                ? __('This document is approved in order; it waits for :name first.', ['name' => $this->nextSlot($request)['name'] ?? '—'])
                : __('Approving this document takes an approval rule that names you, or the "approve transactions" right.'));
        }
        if ($this->segregated($request, $approver)) {
            throw new RuntimeException(__('Segregation of duties: the person who entered or last changed the document cannot approve it.'));
        }
        if ($type->beforeApprove !== null) {
            ($type->beforeApprove)($document, $approver);
        }

        return DB::transaction(function () use ($document, $type, $request, $approver, $slot): bool {
            ApprovalDecision::query()->create(['approval_request_id' => $request->id, 'user_id' => $approver->id, 'decision' => ApprovalRequest::APPROVED, 'slot' => $slot]);
            $complete = $this->approvalsOf($request)->count() >= $request->required_count;
            if ($complete) {
                $request->forceFill(['status' => ApprovalRequest::APPROVED, 'decided_at' => now()])->save();
            }
            Auditor::log($complete ? 'approved' : 'approval_given', $document, (string) $document->getAttribute('number'), $complete ? [] : ['of' => $request->required_count]);
            $this->notify($type, $document, $request, $approver);

            return $complete;
        });
    }

    public function reject(Model $document, User $approver, string $reason): void
    {
        $type = $this->typeOf($document);
        $request = $this->pending($document);
        if ($type === null || $request === null || ! $this->isEligible($request, $approver)) {
            throw new RuntimeException(__('Rejecting this document takes an approval rule that names you, or the "approve transactions" right.'));
        }
        if ($this->segregated($request, $approver)) {
            throw new RuntimeException(__('Segregation of duties: the person who entered or last changed the document cannot approve it.'));
        }
        DB::transaction(function () use ($document, $type, $request, $approver, $reason): void {
            ApprovalDecision::query()->create(['approval_request_id' => $request->id, 'user_id' => $approver->id, 'decision' => ApprovalRequest::REJECTED, 'reason' => $reason]);
            $request->forceFill(['status' => ApprovalRequest::REJECTED, 'decided_at' => now()])->save();
            Auditor::log('rejected', $document, (string) $document->getAttribute('number'), ['reason' => $reason]);
            $this->notify($type, $document, $request, $approver);
        });
    }

    /**
     * The documents still waiting for this user that cannot be approved
     * without them. A request nobody has decided yet first re-reads the
     * rules, so one the user was taken off no longer counts.
     *
     * @return list<Model>
     */
    public function waitingOn(User $user): array
    {
        $out = [];
        $requests = ApprovalRequest::query()->where('status', ApprovalRequest::AWAITING)->whereNull('superseded_at')->get()
            ->filter(fn (ApprovalRequest $r) => collect((array) $r->slots)->contains(fn (array $slot) => $this->fits($slot, $user)));
        foreach ($requests as $request) {
            $document = $request->approvable;
            $request = $document !== null ? $this->pending($document) : null;
            if ($request !== null && $this->needs($request, $user)) {
                $out[] = $document;
            }
        }

        return $out;
    }

    /** Who has approved so far, and who is still to, for the approval panel. @return array{done: list<string>, waiting: list<string>} */
    public function progress(Model $document): array
    {
        $request = $this->current($document);
        if ($request === null) {
            return ['done' => [], 'waiting' => []];
        }
        $done = $this->approvalsOf($request)->map(fn (ApprovalDecision $d) => User::query()->find($d->user_id)?->name ?? '?')->values()->all();
        $filled = $this->approvalsOf($request)->pluck('slot')->filter(fn ($s) => $s !== null)->all();
        $waiting = $request->status !== ApprovalRequest::AWAITING ? [] : match ($request->rule) {
            ApprovalRequest::RULE_RIGHT => [__('anyone with the approve right')],
            TransactionApprover::ANY_ONE, TransactionApprover::AT_LEAST_TWO => [__(':count more of: :names', ['count' => $request->required_count - count($done), 'names' => collect($request->slots)->pluck('name')->join(', ')])],
            default => collect($request->slots)->reject(fn ($s, $i) => in_array($i, $filled, true))->pluck('name')->values()->all(),
        };

        return ['done' => $done, 'waiting' => $waiting];
    }

    // --- internals --------------------------------------------------------

    private function open(Model $document, ApprovalType $type, string $fingerprint, bool $changed = false): ApprovalRequest
    {
        return ApprovalRequest::query()->create([
            'approvable_type' => $document->getMorphClass(),
            'approvable_id' => $document->getKey(),
            'transaction_type' => $type->transactionType->value,
            'amount' => self::amountOf($document),
            'fingerprint' => $fingerprint,
            'requested_by' => $document->getAttribute('created_by') ?? auth()->id(),
            // A change asks again for approval, and whoever made it may not give it either.
            'edited_by' => $changed ? (auth()->id() ?? $document->getAttribute('updated_by')) : null,
        ] + $this->governance($document, $type));
    }

    /** The rule, slots, count and status a document falls under now. */
    private function governance(Model $document, ApprovalType $type): array
    {
        $rule = $this->rulesFor($document)->first();
        if ($rule !== null) {
            $slots = self::slotsOf($rule);

            return [
                'transaction_approver_id' => $rule->id,
                'rule' => $rule->rule,
                'slots' => $slots,
                'required_count' => match ($rule->rule) {
                    TransactionApprover::AT_LEAST_TWO => 2,
                    TransactionApprover::IN_ORDER, TransactionApprover::ANY_ORDER => max(1, count($slots)),
                    default => 1,
                },
                'status' => ApprovalRequest::AWAITING,
                'decided_at' => null,
            ];
        }
        if ($type->isEnabled() && $type->requiredWithoutRule) {
            return ['transaction_approver_id' => null, 'rule' => ApprovalRequest::RULE_RIGHT, 'slots' => [], 'required_count' => 1, 'status' => ApprovalRequest::AWAITING, 'decided_at' => null];
        }

        return ['transaction_approver_id' => null, 'rule' => ApprovalRequest::RULE_NONE, 'slots' => [], 'required_count' => 0, 'status' => ApprovalRequest::APPROVED, 'decided_at' => now()];
    }

    /**
     * The current request while it waits. Until anyone decides, it follows
     * the rules as they are now (a rule added, changed or switched off).
     */
    private function pending(Model $document): ?ApprovalRequest
    {
        $request = $this->current($document);
        $type = $this->typeOf($document);
        if ($request === null && $type !== null && $document->exists && ! $type->approvedWithoutRequest($document)) {
            $request = $this->sync($document); // saved before approvals were recorded, and still waiting
        }
        if ($request === null || ! $request->isAwaiting()) {
            return null;
        }
        if (! $request->decisions()->exists()) {
            $now = $this->governance($document, $type);
            if ($now['transaction_approver_id'] !== $request->transaction_approver_id || $now['rule'] !== $request->rule || $now['slots'] != $request->slots) {
                $request->forceFill($now)->save();
                if ($request->status !== ApprovalRequest::AWAITING) {
                    $this->notify($type, $document, $request, null);

                    return null;
                }
            }
        }

        return $request;
    }

    /** @return list<array{kind: string, id: int, name: string}> the rule's approvers and groups in approval order */
    private static function slotsOf(TransactionApprover $rule): array
    {
        $users = $rule->approvers()->get()->map(fn (User $u) => ['kind' => 'user', 'id' => (int) $u->id, 'name' => (string) $u->name, 'sort' => (int) $u->pivot->sort]);
        $groups = $rule->groups()->get()->map(fn ($g) => ['kind' => 'group', 'id' => (int) $g->id, 'name' => (string) $g->name, 'sort' => (int) $g->pivot->sort]);

        return $users->concat($groups)
            ->sortBy(fn (array $s) => sprintf('%05d-%s-%010d', $s['sort'], $s['kind'] === 'user' ? 'a' : 'b', $s['id']))
            ->map(fn (array $s) => ['kind' => $s['kind'], 'id' => $s['id'], 'name' => $s['name']])
            ->values()->all();
    }

    /** @return Collection<int, ApprovalDecision> */
    private function approvalsOf(ApprovalRequest $request): Collection
    {
        return $request->decisions()->where('decision', ApprovalRequest::APPROVED)->get();
    }

    private function hasDecided(ApprovalRequest $request, User $user): bool
    {
        return $request->decisions()->where('user_id', $user->id)->exists();
    }

    /** The slot this user's approval would fill now, or null when it cannot count. */
    private function slotFor(ApprovalRequest $request, User $user): ?int
    {
        if ($request->rule === ApprovalRequest::RULE_RIGHT) {
            return app(HakAkses::class)->allowsSpecial($user, HakKhusus::ApproveTransactions) ? 0 : null;
        }
        $slots = (array) $request->slots;
        $filled = $this->approvalsOf($request)->pluck('slot')->filter(fn ($s) => $s !== null)->map(fn ($s) => (int) $s)->all();

        if ($request->rule === TransactionApprover::IN_ORDER) {
            $next = $this->nextSlotIndex($request, $filled);

            return $next !== null && $this->fits($slots[$next], $user) ? $next : null;
        }
        foreach ($slots as $i => $slot) {
            $open = in_array($request->rule, [TransactionApprover::ANY_ONE, TransactionApprover::AT_LEAST_TWO], true) || ! in_array($i, $filled, true);
            if ($open && $this->fits($slot, $user)) {
                return $i;
            }
        }

        return null;
    }

    private function isEligible(ApprovalRequest $request, User $user): bool
    {
        if ($request->rule === ApprovalRequest::RULE_RIGHT) {
            return app(HakAkses::class)->allowsSpecial($user, HakKhusus::ApproveTransactions);
        }

        return collect((array) $request->slots)->contains(fn (array $slot) => $this->fits($slot, $user));
    }

    private function nextSlotIndex(ApprovalRequest $request, ?array $filled = null): ?int
    {
        $filled ??= $this->approvalsOf($request)->pluck('slot')->filter(fn ($s) => $s !== null)->map(fn ($s) => (int) $s)->all();
        foreach (array_keys((array) $request->slots) as $i) {
            if (! in_array($i, $filled, true)) {
                return $i;
            }
        }

        return null;
    }

    /** @return array{kind: string, id: int, name: string}|null */
    private function nextSlot(ApprovalRequest $request): ?array
    {
        $i = $this->nextSlotIndex($request);

        return $i === null ? null : ((array) $request->slots)[$i];
    }

    /** Whether the request cannot complete without this user's approval. */
    private function needs(ApprovalRequest $request, User $user): bool
    {
        if ($this->hasDecided($request, $user)) {
            return false;
        }
        $slots = (array) $request->slots;
        $filled = $this->approvalsOf($request)->pluck('slot')->filter(fn ($s) => $s !== null)->map(fn ($s) => (int) $s)->all();
        $excluded = $request->decisions()->pluck('user_id')->map(fn ($id) => (int) $id)->push($user->id);
        if (BusinessRule::SegregationOfDuties->isOn() && $request->edited_by !== null) {
            $excluded->push((int) $request->edited_by);
        }
        if (BusinessRule::SegregationOfDuties->isOn() && $request->requested_by !== null) {
            $excluded->push((int) $request->requested_by);
        }
        $others = fn (array $slot) => $slot['kind'] === 'user'
            ? DB::table('users')->where('id', $slot['id'])->where('is_active', true)->whereNotIn('id', $excluded)->pluck('id')
            : DB::table('access_group_users')->join('users', 'users.id', '=', 'access_group_users.user_id')
                ->where('access_group_id', $slot['id'])->where('users.is_active', true)->whereNotIn('users.id', $excluded)->pluck('users.id');

        if (in_array($request->rule, [TransactionApprover::IN_ORDER, TransactionApprover::ANY_ORDER], true)) {
            // Every slot must be filled: one of theirs still open that nobody else can fill.
            foreach ($slots as $i => $slot) {
                if (! in_array($i, $filled, true) && $this->fits($slot, $user) && $others($slot)->isEmpty()) {
                    return true;
                }
            }

            return false;
        }
        // Any one, at least two: enough other people left to give the approvals still missing?
        $people = collect($slots)->flatMap(fn (array $slot) => $others($slot))->unique();

        return $people->count() < $request->required_count - $this->approvalsOf($request)->count();
    }

    /** A user slot is that user; a group slot is any current member of the group. */
    private function fits(array $slot, User $user): bool
    {
        return $slot['kind'] === 'user'
            ? (int) $slot['id'] === $user->id
            : DB::table('access_group_users')->where('access_group_id', $slot['id'])->where('user_id', $user->id)->exists();
    }

    private function notify(ApprovalType $type, Model $document, ?ApprovalRequest $request, ?User $actor): void
    {
        if ($type->changed !== null) {
            ($type->changed)($document, $request, $actor);
        }
    }

    public static function amountOf(Model $document): int
    {
        foreach (['total', 'amount', 'total_amount'] as $column) {
            if (array_key_exists($column, $document->getAttributes())) {
                return (int) $document->getAttribute($column);
            }
        }

        return 0;
    }

    /** What an edit must change to need approval again: the amount, the branch and the lines. */
    public static function fingerprint(Model $document): string
    {
        $lines = [];
        if (method_exists($document, 'lines')) {
            $relation = $document->lines();
            $skip = ['id', 'created_at', 'updated_at', 'processed_quantity', $relation->getForeignKeyName()];
            foreach ($relation->get() as $line) {
                $values = array_diff_key($line->getAttributes(), array_flip($skip));
                ksort($values);
                $lines[] = array_map(fn ($v) => $v === null ? null : (string) $v, $values);
            }
        }

        // What was approved: the amount, the branch, who it is with, the bank or cash account, the date, the lines.
        $header = array_map(fn (string $column) => $document->getAttribute($column) === null ? null : (string) $document->getAttribute($column),
            ['branch_id' => 'branch_id', 'customer_id' => 'customer_id', 'vendor_id' => 'vendor_id', 'bank_account_id' => 'bank_account_id', 'trans_date' => 'trans_date']);
        if ($document->getAttribute('trans_date') instanceof \DateTimeInterface) {
            $header['trans_date'] = $document->getAttribute('trans_date')->format('Y-m-d');
        }

        return hash('sha256', json_encode([self::amountOf($document), $header, $lines]));
    }
}
