<?php

declare(strict_types=1);

namespace App\Domain\Audit;

interface HasAuditReference
{
    /** The number or name the Activity Log shows for this record. */
    public function auditReference(): string;
}
