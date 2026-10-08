<?php

declare(strict_types=1);

namespace App\Modules\Settings;

use App\Domain\Access\MenuKey;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\Company\TransactionApprover;
use App\Modules\BaseModule;

/** Transaction approval rules: who approves which documents. Switched by the Approval feature. */
final class ApprovalModule extends BaseModule
{
    public static function key(): string
    {
        return 'approval';
    }

    public static function feature(): ?PreferensiKey
    {
        return PreferensiKey::Approval;
    }

    public static function menuKeys(): array
    {
        return [MenuKey::TransactionApprovers];
    }

    public static function morphMap(): array
    {
        return ['transaction_approver' => TransactionApprover::class];
    }
}
