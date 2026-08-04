<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\Company;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Creates a fixed Virtual Account through Xendit's API.
 *
 * `is_closed` is deliberately false: a fixed VA accepts any amount, so a buyer
 * can pay several invoices with one transfer or part-pay one. That is how B2B
 * customers actually settle, and it is why the reconciliation side has an
 * unmatched-payments queue rather than assuming one transfer equals one order.
 *
 * `is_single_use` is false for the same reason — the account belongs to the
 * customer for as long as they trade with us.
 */
class XenditVirtualAccountGateway implements VirtualAccountGateway
{
    public function createFixedAccount(Company $company, string $bankCode): array
    {
        $secret = (string) config('xendit.secret_key');

        if ($secret === '') {
            throw new RuntimeException(
                'XENDIT_SECRET_KEY is not configured; cannot provision a virtual account.'
            );
        }

        $response = Http::withBasicAuth($secret, '')
            ->acceptJson()
            ->timeout(20)
            // Xendit rejects a repeated external_id, which makes this safe to
            // retry: a second attempt for the same company and bank fails
            // rather than minting a second account for one customer.
            ->post(rtrim((string) config('xendit.base_url'), '/').'/callback_virtual_accounts', [
                'external_id' => "company-{$company->id}-{$bankCode}",
                'bank_code' => $bankCode,
                'name' => mb_substr($company->nama, 0, 50),
                'is_closed' => false,
                'is_single_use' => false,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Xendit refused the virtual account request: {$response->status()} {$response->body()}"
            );
        }

        $body = $response->json();

        if (empty($body['account_number'])) {
            throw new RuntimeException('Xendit returned no account_number.');
        }

        return [
            'account_number' => (string) $body['account_number'],
            'external_id' => isset($body['external_id']) ? (string) $body['external_id'] : null,
            'gateway_id' => isset($body['id']) ? (string) $body['id'] : null,
        ];
    }

    public function name(): string
    {
        return 'xendit';
    }
}
