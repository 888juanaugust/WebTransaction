<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Writes created / updated / deleted entries to the Activity Log for a model,
 * with the changed columns before and after. Masters and settings use it;
 * documents log through the posting layer instead.
 */
trait RecordsActivity
{
    /** Columns whose changes are not worth an entry. */
    private const QUIET = ['updated_at', 'created_at', 'remember_token', 'password'];

    public static function bootRecordsActivity(): void
    {
        static::created(fn (Model $model) => Auditor::log('created', $model));

        static::updated(function (Model $model): void {
            $changes = collect($model->getChanges())->except(self::QUIET);
            if ($changes->isEmpty()) {
                return;
            }
            // A hidden or encrypted column (a two-factor secret, a national ID) is logged as changed, never its value:
            // the log cannot be edited later.
            $secret = Auditor::secretColumns($model);
            $hidden = $changes->only($secret)->map(fn () => __('(changed, not shown)'));
            $changes = $changes->except($secret);
            $before = collect($model->getOriginal())->only($changes->keys());

            Auditor::log('updated', $model, null, ['before' => $before->all(), 'after' => $changes->merge($hidden)->all()]);
        });

        static::deleted(fn (Model $model) => Auditor::log('deleted', $model, null, ['before' => collect($model->getOriginal())->except([...self::QUIET, ...Auditor::secretColumns($model)])->all()]));
    }

    public function auditReference(): string
    {
        return (string) ($this->getAttribute('number') ?? $this->getAttribute('name') ?? $this->getKey());
    }
}
