<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Every money-affecting action writes here.
 *
 * Price overrides and credit-limit overrides in particular must record actor,
 * old value, new value and timestamp — that is what makes a disputed number
 * answerable months later.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $oldValue
     * @param  array<string, mixed>|null  $newValue
     */
    public function log(
        string $action,
        ?Model $subject = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?User $actor = null,
        ?string $alasan = null,
    ): AuditLog {
        $actor ??= auth()->user();

        return AuditLog::create([
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role?->value,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject ? (string) $subject->getKey() : null,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'alasan' => $alasan,
            'ip_address' => request()?->ip(),
        ]);
    }

    public function priceOverride(
        Model $subject,
        int|float|null $oldPrice,
        int|float|null $newPrice,
        ?User $actor = null,
        ?string $alasan = null,
    ): AuditLog {
        return $this->log(
            action: 'price_override',
            subject: $subject,
            oldValue: ['harga' => $oldPrice],
            newValue: ['harga' => $newPrice],
            actor: $actor,
            alasan: $alasan,
        );
    }

    public function creditLimitOverride(
        Model $subject,
        int $oldLimit,
        int $newLimit,
        ?User $actor = null,
        ?string $alasan = null,
    ): AuditLog {
        return $this->log(
            action: 'credit_limit_override',
            subject: $subject,
            oldValue: ['credit_limit_rupiah' => $oldLimit],
            newValue: ['credit_limit_rupiah' => $newLimit],
            actor: $actor,
            alasan: $alasan,
        );
    }
}
