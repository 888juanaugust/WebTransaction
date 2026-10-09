<?php

declare(strict_types=1);

namespace App\Client\Screens;

use App\Domain\Access\ScreenKey;
use App\Domain\Access\ScreenKind;
use App\Filament\Modul;

/** Central's own screens. A value is stored in access rights, so it never changes once in use. */
enum CentralScreen: string implements ScreenKey
{
    case Teams = 'client__teams';
    case OrderApprovals = 'client__order-approvals';

    public function modul(): Modul
    {
        return match ($this) {
            self::Teams, self::OrderApprovals => Modul::Sales,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Teams => __('Customer Teams'),
            self::OrderApprovals => __('Order Approvals'),
        };
    }

    public function sort(): int
    {
        return match ($this) {
            self::OrderApprovals => 15,
            self::Teams => 510,
        };
    }

    public function kind(): ScreenKind
    {
        return match ($this) {
            self::OrderApprovals => ScreenKind::Work,
            self::Teams => ScreenKind::Setup,
        };
    }

    public function isReplicated(): bool
    {
        return true;
    }

    public function slug(): string
    {
        return str_replace('__', '/', $this->value);
    }
}
