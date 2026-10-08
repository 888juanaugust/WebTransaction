<?php

declare(strict_types=1);

namespace App\Modules\Sales;

use App\Domain\Access\MenuKey;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\Sales\CheckIn;
use App\Models\Sales\SalesmanCommission;
use App\Models\Sales\SalesTarget;
use App\Modules\BaseModule;

/** Sales extras: store check-ins, salesperson commissions and targets. Off until a company runs a sales force. */
final class SalesExtrasModule extends BaseModule
{
    public static function key(): string
    {
        return 'sales-extras';
    }

    public static function feature(): ?PreferensiKey
    {
        return PreferensiKey::SalesExtras;
    }

    public static function menuKeys(): array
    {
        return [MenuKey::CheckIns, MenuKey::SalesmanCommissions, MenuKey::SalesTargets];
    }

    public static function morphMap(): array
    {
        return ['salesman_commission' => SalesmanCommission::class, 'sales_target' => SalesTarget::class, 'check_in' => CheckIn::class];
    }
}
