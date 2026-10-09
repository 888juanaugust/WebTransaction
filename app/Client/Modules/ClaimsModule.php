<?php

declare(strict_types=1);

namespace App\Client\Modules;

use App\Client\Models\SettlementClaim;
use App\Client\Screens\CentralScreen;
use App\Modules\BaseModule;

/**
 * Central's two-key claims: a sales or marketing seat files, somebody else
 * verifies, and the base document that moves the money or the stock is made
 * in the verifier's name. Always on.
 */
final class ClaimsModule extends BaseModule
{
    public static function key(): string
    {
        return 'central-claims';
    }

    public static function menuKeys(): array
    {
        return [CentralScreen::SettlementClaims];
    }

    public static function morphMap(): array
    {
        return ['settlement_claim' => SettlementClaim::class];
    }
}
