<?php

declare(strict_types=1);

namespace App\Client\Domain\Claims;

/** The three states of a claim: filed, then verified or rejected, both final. */
final class ClaimStatus
{
    public const FILED = 'filed';

    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    public static function label(string $status): string
    {
        return match ($status) {
            self::FILED => __('Awaiting verification'),
            self::VERIFIED => __('Verified'),
            self::REJECTED => __('Rejected'),
            default => $status,
        };
    }

    public static function color(string $status): string
    {
        return match ($status) {
            self::VERIFIED => 'success',
            self::REJECTED => 'danger',
            default => 'warning',
        };
    }
}
