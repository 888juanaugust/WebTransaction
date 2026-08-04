<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\Company;
use App\Models\VirtualAccount;

/**
 * Issues VA numbers locally, without contacting Xendit.
 *
 * Used in development, in CI, and whenever XENDIT_SECRET_KEY is unset — so the
 * whole order → invoice → payment chain can be exercised end to end on a
 * laptop. The numbers it mints are real rows in our own table but no bank has
 * ever heard of them.
 *
 * That is deliberate rather than a shortcut: a developer who cannot complete
 * the flow locally ends up testing on production data instead.
 */
class LocalVirtualAccountGateway implements VirtualAccountGateway
{
    /** Prefix that makes a locally-minted account obvious at a glance. */
    private const PREFIX = '8808';

    public function createFixedAccount(Company $company, string $bankCode): array
    {
        // Deterministic on company id, so re-running a seed does not scatter
        // orphan accounts, and padded to a plausible VA length.
        $number = self::PREFIX.str_pad((string) $company->id, 10, '0', STR_PAD_LEFT);

        // Vanishingly unlikely, but a collision here would attribute one
        // customer's money to another — so check rather than assume.
        while (VirtualAccount::query()->where('account_number', $number)->exists()) {
            $number = self::PREFIX.str_pad((string) random_int(1, 9_999_999_999), 10, '0', STR_PAD_LEFT);
        }

        return [
            'account_number' => $number,
            'external_id' => "local-{$company->kode}-{$bankCode}",
            'gateway_id' => null,
        ];
    }

    public function name(): string
    {
        return 'local';
    }
}
