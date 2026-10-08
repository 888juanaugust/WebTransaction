<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;

if (! function_exists('bccomp_safe')) {
    /** Compares two decimal strings without the bcmath extension: -1, 0 or 1. */
    function bccomp_safe(string $a, string $b): int
    {
        return BigDecimal::of($a === '' ? '0' : $a)->compareTo(BigDecimal::of($b === '' ? '0' : $b));
    }
}
