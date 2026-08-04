<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\Company;

/**
 * Creates a fixed Virtual Account for a buyer at the payment gateway.
 *
 * Behind an interface because provisioning is the one part of the payment flow
 * that reaches out to a third party. Everything downstream — the ledger, the
 * webhook, reconciliation — has to be testable without a network, and the
 * order-to-invoice chain has to work on a laptop with no Xendit keys.
 */
interface VirtualAccountGateway
{
    /**
     * @return array{account_number: string, external_id: string|null, gateway_id: string|null}
     */
    public function createFixedAccount(Company $company, string $bankCode): array;

    /** Identifies which implementation produced an account, for the audit log. */
    public function name(): string;
}
