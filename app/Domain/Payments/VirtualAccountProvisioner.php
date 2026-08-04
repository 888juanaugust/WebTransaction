<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Audit\AuditLogger;
use App\Models\Company;
use App\Models\VirtualAccount;
use Illuminate\Support\Facades\DB;

/**
 * Makes sure a buyer has somewhere to pay into.
 *
 * A company keeps one fixed VA per bank, forever — that is the whole point of
 * "fixed": the buyer saves it once as a beneficiary and every future transfer
 * lands attributable. Minting a fresh account per order would break the
 * reconciliation model, since the account number is how an incoming transfer
 * is matched back to a customer.
 */
class VirtualAccountProvisioner
{
    public function __construct(
        private readonly VirtualAccountGateway $gateway,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Return the company's VA for this bank, creating it on first use.
     *
     * Idempotent, and safe to call on every invoice.
     */
    public function ensureFor(Company $company, ?string $bankCode = null): VirtualAccount
    {
        $bankCode = $bankCode ?? (config('xendit.va_banks')[0] ?? 'BCA');

        $existing = VirtualAccount::query()
            ->where('company_id', $company->id)
            ->where('bank_code', $bankCode)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // The gateway call happens outside the transaction on purpose: holding
        // a database transaction open across a third-party HTTP request is how
        // a slow gateway turns into lock contention across the whole app.
        $account = $this->gateway->createFixedAccount($company, $bankCode);

        return DB::transaction(function () use ($company, $bankCode, $account) {
            // Two concurrent first-invoices for one customer both reach here;
            // the unique index on (company_id, bank_code) decides, and the
            // loser returns the winner's row rather than raising.
            VirtualAccount::query()->insertOrIgnore([
                'company_id' => $company->id,
                'bank_code' => $bankCode,
                'account_number' => $account['account_number'],
                'external_id' => $account['external_id'],
                'gateway_id' => $account['gateway_id'],
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $va = VirtualAccount::query()
                ->where('company_id', $company->id)
                ->where('bank_code', $bankCode)
                ->firstOrFail();

            $this->audit->log(
                action: 'virtual_account_provisioned',
                subject: $va,
                newValue: [
                    'company_id' => $company->id,
                    'bank_code' => $bankCode,
                    'account_number' => $va->account_number,
                    'gateway' => $this->gateway->name(),
                ],
            );

            return $va;
        });
    }
}
