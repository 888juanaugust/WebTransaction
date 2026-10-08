<?php

declare(strict_types=1);

namespace App\Modules\Budgeting;

use App\Domain\Access\MenuKey;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\Budgeting\Budget;
use App\Models\Budgeting\BudgetLine;
use App\Models\Budgeting\BudgetTransfer;
use App\Modules\BaseModule;

/** Budgets per account per month, their monitor and transfers. Switched by the Budgets feature. */
final class BudgetsModule extends BaseModule
{
    public static function key(): string
    {
        return 'budgets';
    }

    public static function feature(): ?PreferensiKey
    {
        return PreferensiKey::BudgetTarget;
    }

    public static function menuKeys(): array
    {
        return [MenuKey::Budgets, MenuKey::BudgetMonitor, MenuKey::BudgetTransfers];
    }

    public static function morphMap(): array
    {
        return ['budget' => Budget::class, 'budget_line' => BudgetLine::class, 'budget_transfer' => BudgetTransfer::class];
    }
}
