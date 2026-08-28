<?php

declare(strict_types=1);

namespace App\Domain\Orders;

/**
 * draft → submitted → confirmed → awaiting_payment → paid → shipped → completed
 *              ↓           ↓              ↓
 *          rejected    rejected        expired
 */
enum OrderStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Confirmed = 'confirmed';
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Submitted => 'Diajukan',
            self::Confirmed => 'Dikonfirmasi',
            self::AwaitingPayment => 'Menunggu Pembayaran',
            self::Paid => 'Lunas',
            self::Shipped => 'Dikirim',
            self::Completed => 'Selesai',
            self::Rejected => 'Ditolak',
            self::Expired => 'Kedaluwarsa',
        };
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::Confirmed, self::Rejected],
            self::Confirmed => [self::AwaitingPayment, self::Rejected],
            /*
             * Shipped from awaiting_payment is the credit-sales path, and
             * under the reorganisation it is the normal one: the goods leave,
             * the invoice stands as the customer's debt, and the money
             * arrives on terms. Paid-then-shipped survives for customers who
             * pay up front.
             */
            self::AwaitingPayment => [self::Paid, self::Shipped, self::Expired],
            self::Paid => [self::Shipped],
            self::Shipped => [self::Completed],
            self::Completed, self::Rejected, self::Expired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /** From `confirmed` onward the order holds a stock reservation. */
    public function holdsReservation(): bool
    {
        return in_array($this, [self::Confirmed, self::AwaitingPayment, self::Paid], true);
    }
}
