<?php

declare(strict_types=1);

namespace App\Client\Domain\Ops\Health;

/** How a check stands: fine, worth a look, or somebody must act now. */
enum OpsStatus: string
{
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Critical = 'critical';

    public function worseThan(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    public function label(): string
    {
        return match ($this) {
            self::Healthy => __('Healthy'),
            self::Warning => __('Warning'),
            self::Critical => __('Critical'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Healthy => 'success',
            self::Warning => 'warning',
            self::Critical => 'danger',
        };
    }

    private function rank(): int
    {
        return match ($this) {
            self::Healthy => 0,
            self::Warning => 1,
            self::Critical => 2,
        };
    }
}
