<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Audit\AuditLogger;
use App\Models\Company;

/**
 * Credit limits are money-affecting, so every change is logged with actor, old
 * value, new value and timestamp — wherever it was made from, including a
 * correction typed straight into the CRUD form.
 */
class CompanyObserver
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function updating(Company $company): void
    {
        if ($company->isDirty('credit_limit_rupiah')) {
            $this->audit->creditLimitOverride(
                $company,
                (int) $company->getOriginal('credit_limit_rupiah'),
                (int) $company->credit_limit_rupiah,
            );
        }

        if ($company->isDirty('status')) {
            $this->audit->log(
                action: 'company_status_changed',
                subject: $company,
                oldValue: ['status' => $company->getOriginal('status')],
                newValue: ['status' => $company->status],
            );
        }
    }
}
