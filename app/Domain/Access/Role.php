<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Staff roles. The hard rule this encodes: whoever confirms a payment must not
 * be able to edit the invoice amount.
 */
enum Role: string
{
    case Sales = 'sales';
    case Warehouse = 'warehouse';
    case Finance = 'finance';
    case Owner = 'owner';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Sales',
            self::Warehouse => 'Gudang',
            self::Finance => 'Keuangan',
            self::Owner => 'Pemilik',
        };
    }

    /** Sales quote prices; warehouse must never see them. */
    public function canSeePrices(): bool
    {
        return $this !== self::Warehouse;
    }

    /** Warehouse is deliberately blind to credit and AR data. */
    public function canSeeCreditData(): bool
    {
        return in_array($this, [self::Sales, self::Finance, self::Owner], true);
    }

    public function canCreateOrders(): bool
    {
        return in_array($this, [self::Sales, self::Owner], true);
    }

    /** Finance confirms money in. Sales never does. */
    public function canConfirmPayment(): bool
    {
        return in_array($this, [self::Finance, self::Owner], true);
    }

    /**
     * Deliberately disjoint from canConfirmPayment() for Finance: the person
     * who confirms a payment must not be able to move the amount owed.
     */
    public function canEditOrderPrices(): bool
    {
        return in_array($this, [self::Sales, self::Owner], true);
    }

    public function canOverrideCreditLimit(): bool
    {
        return in_array($this, [self::Finance, self::Owner], true);
    }

    public function canPickAndShip(): bool
    {
        return in_array($this, [self::Warehouse, self::Owner], true);
    }

    public function canViewAuditLog(): bool
    {
        return $this === self::Owner;
    }
}
