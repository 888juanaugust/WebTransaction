<?php

declare(strict_types=1);

namespace App\Domain\GeneralLedger;

use App\Domain\Company\DataStart;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Shared\Enums\AccountType;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\AccountOpeningBalance;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * An account's opening balance is a document of its own: created, changed
 * and removed through the document repository, so it is audited, keeps its
 * revisions and obeys the period lock and the back-date right. Receivable
 * and payable accounts take their opening balances per customer or vendor
 * instead, so nothing is counted twice.
 */
final class AccountOpenings
{
    public function __construct(private readonly DocumentRepository $documents) {}

    /** @throws RuntimeException when the opening balance may not be saved */
    public function save(Account $account, mixed $amount, ?string $date): void
    {
        $amount = $amount === null || $amount === '' ? 0 : (int) $amount;
        $existing = AccountOpeningBalance::query()->where('account_id', $account->id)->first();
        if ($amount !== 0 && in_array($account->account_type, [AccountType::AccountsReceivable, AccountType::AccountsPayable], true)) {
            throw new RuntimeException(__(':account takes its opening balance per customer or vendor, on their Opening balance tab.', ['account' => $account->displayName()]));
        }
        if ($amount === 0) {
            if ($existing !== null) {
                $this->documents->delete($existing);
            }

            return;
        }
        $date = $date ?: DataStart::openingDate();
        if ($existing === null) {
            $this->documents->created(AccountOpeningBalance::query()->create(['account_id' => $account->id, 'amount' => $amount, 'trans_date' => $date]));

            return;
        }
        $existing->fill(['amount' => $amount, 'trans_date' => $date]);
        if (! $existing->isDirty()) {
            return;
        }
        $before = $this->documents->beforeUpdate($existing->fresh(), Carbon::parse($date));
        $existing->save();
        $this->documents->updated($existing, $before);
    }
}
