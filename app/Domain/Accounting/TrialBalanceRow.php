<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Models\Account;

/**
 * One account's two column totals, and the balance they make.
 */
final readonly class TrialBalanceRow
{
    public function __construct(
        public Account $account,
        public int $debit,
        public int $kredit,
    ) {}

    /** In the account's own direction: positive means it holds what it should. */
    public function balance(): int
    {
        return $this->account->saldo_normal->balance($this->debit, $this->kredit);
    }

    /**
     * The balance placed back in the column it belongs in, which is how a
     * trial balance is actually printed — one figure per account, on one side.
     */
    public function debitBalance(): int
    {
        $signed = $this->debit - $this->kredit;

        return $signed > 0 ? $signed : 0;
    }

    public function kreditBalance(): int
    {
        $signed = $this->kredit - $this->debit;

        return $signed > 0 ? $signed : 0;
    }

    public function isEmpty(): bool
    {
        return $this->debit === 0 && $this->kredit === 0;
    }

    /**
     * A balance on the wrong side. Not always wrong — a bank account can be
     * overdrawn — but negative inventory or negative sales is a question.
     *
     * Contra accounts are excluded. Akumulasi Penyusutan holds a credit
     * balance for its entire life by design, and flagging it every month
     * would train whoever reads this screen to ignore the label — including
     * the month it turns up on Persediaan, which is the one that matters.
     */
    public function isContrary(): bool
    {
        if (in_array($this->account->kode, AccountCode::contraAccounts(), true)) {
            return false;
        }

        return $this->balance() < 0;
    }
}
