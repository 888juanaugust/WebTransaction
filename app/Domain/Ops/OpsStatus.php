<?php

declare(strict_types=1);

namespace App\Domain\Ops;

enum OpsStatus: string
{
    case Sehat = 'sehat';

    /** Degraded but working — worth a look today, not at 03:00. */
    case Waspada = 'waspada';

    /** Broken in a way that is costing something right now. */
    case Gawat = 'gawat';

    public function worseThan(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Sehat => 0,
            self::Waspada => 1,
            self::Gawat => 2,
        };
    }
}
