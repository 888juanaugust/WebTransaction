<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

use Filament\Support\Contracts\HasLabel;

/** The four item types of the standard. */
enum ItemType: string implements HasLabel
{
    case Inventory = 'inventory';
    case NonInventory = 'non_inventory';
    case Service = 'service';
    case Group = 'group';

    public function getLabel(): string
    {
        return match ($this) {
            self::Inventory => __('Inventory item'),
            self::NonInventory => __('Non-inventory item'),
            self::Service => __('Service'),
            self::Group => __('Group / bundle'),
        };
    }

    /** Whether stock is kept for it. */
    public function isStocked(): bool
    {
        return $this === self::Inventory;
    }
}
